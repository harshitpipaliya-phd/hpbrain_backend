<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Context\ResolvedContext;

/**
 * Resolved context, expressed as grounding.
 *
 * THIS IS A FORMATTER. IT IS NOT AN ENGINE, AND THE DISTINCTION IS THE WHOLE
 * REASON IT EXISTS AS ITS OWN CLASS.
 *
 * It reads a ResolvedContext and rewrites it into the shape the Brain's
 * grounding step already uses — `['id' => …, 'kind' => …, 'row' => …]`, the
 * same rows ExplainVerb assembles from evidence and memory. It does not reason,
 * rank, score, summarise, interpret, predict, recommend, retrieve memory,
 * detect a pattern or call a model. Every value it emits is a value
 * ContextEngine already resolved; nothing is derived and nothing is added.
 *
 * WHY NOT INSIDE ContextEngine. The engine must not know that an AI layer
 * exists — it answers "what is actually present" for any caller, and a method
 * on it shaped for prompts would be the first step towards it shaping its
 * answers for one. Putting the adapter on the AI side of the boundary makes
 * that separation structural rather than a matter of discipline.
 *
 * ROWS ARE FACTS; GAPS ARE ABSENCES. rows() returns entries ONLY for layers
 * that actually resolved. An absent layer becomes a gap string instead, for two
 * reasons: VerbPipeline treats empty grounding as an immediate UNDETERMINED, so
 * emitting an "object not found" row would tell a consumer it has grounding
 * when it has none; and `gaps` is already this codebase's vocabulary for
 * "what would resolve the question" (VerbResult::undetermined).
 *
 * WHAT IT DELIBERATELY DROPS. The session layer's `id` is the caller's
 * access-token jti. It is not a fact about the organization, it has no bearing
 * on any question, and these rows are PERSISTED — so it is excluded rather than
 * carried into a durable row. Only `screen` survives from that layer. Contact
 * details never appear because ContextEngine does not expose them.
 */
final class ContextGrounding
{
    /**
     * Kinds, mirroring the five layers. The `context.` prefix keeps them
     * distinguishable from the `evidence` and `learning` kinds the verbs
     * already produce, so a consumer can tell a resolved-context fact from a
     * curated evidence row without inspecting the id.
     */
    public const KIND_OBJECT = 'context.object';

    public const KIND_USER = 'context.user';

    public const KIND_ORGANIZATION = 'context.organization';

    public const KIND_SIGNAL = 'context.signal';

    public const KIND_SESSION = 'context.session';

    /**
     * The fence around the rendered block.
     *
     * A caller that ever puts facts() into a prompt must place it in the USER
     * turn, never the system prompt, and the fence is what lets the system
     * prompt refer to it as a region. It is also why every rendered value has
     * its newlines collapsed and any occurrence of these markers escaped: a
     * value that could emit a line break could close the fence and continue as
     * if it were the prompt's own text, which is the whole mechanism of a
     * context-as-instruction attack.
     */
    public const FENCE_OPEN = '<<<RESOLVED_CONTEXT_FACTS';

    public const FENCE_CLOSE = 'RESOLVED_CONTEXT_FACTS>>>';

    /** A single rendered value is truncated here. Long enough for a signal
     *  description, short enough that no one field can dominate the block. */
    private const VALUE_LIMIT = 500;

    /** The most signals carried as grounding, matching ContextEngine's own cap. */
    private const SIGNAL_LIMIT = 50;

    /**
     * Present facts, as grounding rows.
     *
     * @return array<int, array{id: string, kind: string, row: array<string, mixed>}>
     */
    public function rows(ResolvedContext $context): array
    {
        $rows = [];

        if ($context->organization->present) {
            $rows[] = $this->row(
                self::KIND_ORGANIZATION,
                'context.organization:'.$this->scalar($context->organization->data['id'] ?? ''),
                $this->clean($context->organization->data['data'] ?? []),
            );
        }

        if ($context->object->present) {
            $type = $this->scalar($context->object->data['type'] ?? '');
            $id = $this->scalar($context->object->data['id'] ?? '');

            $rows[] = $this->row(
                self::KIND_OBJECT,
                sprintf('context.object:%s:%s', $type, $id),
                ['type' => $type, 'id' => $id] + $this->clean($context->object->data['data'] ?? []),
            );
        }

        if ($context->user->present) {
            $rows[] = $this->row(
                self::KIND_USER,
                'context.user:'.$this->scalar($context->user->data['id'] ?? ''),
                [
                    'id'   => $this->scalar($context->user->data['id'] ?? ''),
                    // The verified token claim, verbatim. Reported because "who
                    // is asking, with what authority" is a fact about the
                    // request; never normalised, so an unroutable role stays
                    // visible rather than looking valid.
                    'role' => $this->scalar($context->user->data['role'] ?? null),
                ] + $this->clean($context->user->data['data'] ?? []),
            );
        }

        /*
          EACH SIGNAL IS ITS OWN ROW, CARRYING ITS OWN REAL ID.

          Not one "signals" row holding a list. A signal id is a genuine
          hpbrain_signals primary key, so one row per signal means the grounding
          refs a consumer records are real, checkable rows — which is what makes
          a claim citing one verifiable. A single collapsed row would give every
          signal the same synthetic reference and destroy that.
        */
        if ($context->signals->present) {
            foreach (array_slice((array) ($context->signals->data['items'] ?? []), 0, self::SIGNAL_LIMIT) as $signal) {
                if (! is_array($signal)) {
                    continue;
                }

                $rows[] = $this->row(
                    self::KIND_SIGNAL,
                    $this->scalar($signal['id'] ?? ''),
                    $this->clean($signal),
                );
            }
        }

        if ($context->session->present) {
            $screen = $this->scalar($context->session->data['screen'] ?? null);

            // Only when there is a screen to name. The session layer resolves
            // with a token identity alone, and a row saying nothing but "a
            // session exists" is not a fact about anything.
            if ($screen !== null && $screen !== '') {
                $rows[] = $this->row(self::KIND_SESSION, 'context.session', ['screen' => $screen]);
            }
        }

        // A row whose id came back empty cannot be cited or audited, so it is
        // dropped rather than carried with a blank reference.
        return array_values(array_filter($rows, fn (array $r): bool => $r['id'] !== ''));
    }

    /**
     * Why each absent layer is absent, in the gap vocabulary.
     *
     * The reason strings are ContextEngine's own — `object_not_found`,
     * `entity_not_mapped_for_tenant`, `no_object_requested` and the rest — so a
     * consumer reading a gap learns the same thing the context endpoint would
     * have told it. Prefixed by layer, because "not found" means something
     * different about an object than about an organization.
     *
     * @return array<int, string>
     */
    public function gaps(ResolvedContext $context): array
    {
        $gaps = [];

        foreach ([
            'object'       => $context->object,
            'user'         => $context->user,
            'organization' => $context->organization,
            'signals'      => $context->signals,
        ] as $layer => $resolved) {
            if (! $resolved->present) {
                $gaps[] = sprintf('context_%s_absent:%s', $layer, (string) $resolved->reason);
            }
        }

        // A resolved object with no signals is NOT a gap. The question was
        // asked of the real table and the answer was zero, which is a fact
        // about the organization — see ContextEngine's note on the same point.
        return $gaps;
    }

    /**
     * The facts as one clearly separated, fenced block.
     *
     * DATA, NOT INSTRUCTIONS. There is no imperative sentence in here and no
     * preamble addressed to a model: this build calls no model on the
     * conversation path, and writing prompt directions for a reader that does
     * not exist would be inventing an interface. What the block does provide is
     * the structural half of the guarantee — a labelled region whose values
     * cannot break out of it:
     *
     *   - every value is a single line (newlines and carriage returns collapse
     *     to spaces), so no value can forge a section heading or a new fact;
     *   - control characters are stripped, so none can smuggle a line break;
     *   - either fence marker appearing inside a value is neutralised, so no
     *     value can close the region and continue as prose;
     *   - values are truncated, so no single field can flood the block.
     *
     * A future caller that does prompt with this must put it in the user turn
     * and keep its own instructions in the system prompt. Interpolating these
     * values into a system prompt would hand the block's author the
     * instructions, and nothing here can prevent that on the caller's behalf.
     */
    public function facts(ResolvedContext $context): string
    {
        $lines = [self::FENCE_OPEN];

        $lines[] = 'tenant: '.$this->render($context->tenantId);

        foreach ($this->rows($context) as $row) {
            $lines[] = '- '.$row['kind'].' ['.$this->render($row['id']).']';

            foreach ($row['row'] as $field => $value) {
                $lines[] = '    '.$this->render((string) $field).': '.$this->render($value);
            }
        }

        foreach ($this->gaps($context) as $gap) {
            $lines[] = '- context.gap: '.$this->render($gap);
        }

        $lines[] = self::FENCE_CLOSE;

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, kind: string, row: array<string, mixed>}
     */
    private function row(string $kind, string $id, array $row): array
    {
        return ['id' => $id, 'kind' => $kind, 'row' => $row];
    }

    /**
     * A layer's data, reduced to renderable scalars.
     *
     * Nested structures are kept but flattened one level into JSON, because a
     * signal's `metadata` is a real object carrying the finding's title and
     * description. Anything deeper than that is not something ContextEngine
     * produces, and encoding it wholesale would be the one place this class
     * could smuggle in a field nobody reviewed.
     *
     * @param  array<string, mixed>|mixed  $data
     * @return array<string, mixed>
     */
    private function clean(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $out = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $out[$key] = $encoded === false ? null : $encoded;
            }
        }

        return $out;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return $value === null ? null : '';
        }

        return (string) $value;
    }

    /**
     * One value, safe to sit inside the fenced block.
     *
     * The order matters: control characters go first (some ARE line breaks),
     * then the remaining newlines collapse, then the fence markers are
     * neutralised, then the result is truncated. Truncating first could cut a
     * multi-byte character in half; neutralising before collapsing would let a
     * value reassemble a marker across a line break.
     */
    private function render(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $value = $encoded === false ? '' : $encoded;
        }

        $text = (string) $value;

        // C0 and C1 control characters, DEL included. Keeps a value from
        // carrying a line break, a NUL or a terminal escape.
        $text = (string) preg_replace('/[\x00-\x1F\x7F-\x9F]+/u', ' ', $text);

        // Anything that survived as a line break — and the Unicode separators
        // a renderer may also treat as one.
        $text = str_replace(["\r", "\n", "\u{2028}", "\u{2029}"], ' ', $text);

        // A value must not be able to close the region it sits in.
        $text = str_replace(
            [self::FENCE_OPEN, self::FENCE_CLOSE],
            ['<<<redacted-fence', 'redacted-fence>>>'],
            $text,
        );

        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return mb_strlen($text) > self::VALUE_LIMIT
            ? mb_substr($text, 0, self::VALUE_LIMIT).'…'
            : $text;
    }

    /**
     * The grounding refs alone.
     *
     * What gets persisted into hpbrain_conversation_messages.citations, which
     * is a list of references rather than a copy of the facts — the same shape
     * AiWorkspaceService::explain() already reads back out of that column.
     *
     * @return array<int, string>
     */
    public function refs(ResolvedContext $context): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['id'],
            $this->rows($context),
        ));
    }
}
