<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\ContextGrounding;
use App\Domain\Context\ContextEngine;
use App\Domain\Context\ContextLayer;
use App\Domain\Context\ContextQuery;
use App\Domain\Context\ResolvedContext;
use App\Domain\Undetermined\VerbResult;
use App\Domain\Verbs\CoachVerb;
use App\Domain\Verbs\Verb;
use App\Http\Controllers\Concerns\ResolvesCurrentContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class ConversationController extends Controller
{
    use ResolvesCurrentContext;

    public function __construct(private readonly ContextGrounding $grounding)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_conversation_sessions')->where('tenant_id', $this->tenantId($request))
                ->orderByDesc('pinned')->orderByDesc('updated_date')->limit(200)->get()
        );
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            DB::table('hpbrain_conversation_sessions')->where('tenant_id', $this->tenantId($request))
                ->where('title', 'like', "%{$term}%")->limit(100)->get()
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->first();

        return $row ? response()->json($row) : response()->json(['error' => 'session_not_found'], 404);
    }

    /**
     * Open a conversation — optionally over the object the user was looking at.
     *
     * THIS IS THE SURFACE THE AI ASSISTANT SCREEN ACTUALLY CALLS.
     * web/src/components/workspace/AIAssistant.tsx posts to
     * /v1/conversations/sessions through conversationApi, not to the
     * ai/workspace routes — AiWorkspaceController serves the same table for
     * clients using that API. Both had to learn about context or the
     * integration would have been wired to the half of the product nothing
     * opens; the shared logic lives in ResolvesCurrentContext so the two
     * cannot drift.
     *
     * CONTEXT IS RESOLVED, NOT ACCEPTED. The caller names a screen and an id;
     * ContextEngine decides what that actually is, scoped to the tenant the
     * token resolved. Only the object it FOUND is recorded as the session's
     * subject — an id from another organization, or one that does not exist,
     * leaves the conversation with no subject rather than with a false one.
     *
     * `screen` and `objectId` are optional and absent from every existing
     * client, which is why this endpoint behaves exactly as it did without
     * them. context_type and context_entity_id have existed on this table
     * since 2026_01_01_001600 and nothing has ever written them.
     *
     * NOTHING IS REASONED OVER HERE and no model is called. The resolved
     * context is returned and recorded; interpreting it stays with Enterprise
     * Brain.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['title' => ['required', 'string', 'min:1', 'max:255']] + $this->contextRules()
        );

        $context = $this->resolveContext($request, $data);
        $object = $context->object;

        $now = now()->format('Y-m-d H:i:s');

        $row = [
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $this->tenantId($request),
            'title' => $data['title'], 'pinned' => 0, 'created_by' => $this->actorId($request),
            // Null when the conversation was not opened over an object, or when
            // the object it named did not resolve. Both are honest nulls.
            'context_type' => $object->present ? (string) $object->data['type'] : null,
            'context_entity_id' => $object->present ? (string) $object->data['id'] : null,
            'created_date' => $now, 'updated_date' => $now,
        ];

        DB::table('hpbrain_conversation_sessions')->insert($row);

        // The context travels back with the session so the Assistant does not
        // need a second call to learn what it was opened over — and so what it
        // displays is the same resolution that was persisted, rather than a
        // separate lookup that could disagree with it.
        return response()->json($row + ['context' => $context->toArray()], 201);
    }

    public function messages(Request $request, string $tenantId, string $id): JsonResponse
    {
        if (! $this->sessionExists($request, $id)) {
            return response()->json(['error' => 'session_not_found'], 404);
        }

        return response()->json(
            DB::table('hpbrain_conversation_messages')
                ->where('tenant_id', $this->tenantId($request))->where('session_id', $id)
                ->orderBy('created_date')->get()
        );
    }

    /**
     * Append a message, grounded in the session's own resolved context.
     *
     * WHERE THE CONTEXT ENGINE MEETS THE REASONING LAYER. The session records
     * which object it was opened over (context_type / context_entity_id, both
     * written by the server after ContextEngine resolved them). At message time
     * that pair is RE-RESOLVED through the same read-only engine and the result
     * is attached to the message as grounding.
     *
     * RE-RESOLVED, NOT TRUSTED. The stored pair is a reference, not a snapshot:
     * the person may have been deleted, the department archived, the tenant's
     * mapping withdrawn since the conversation opened. ContextEngine looks the
     * row up by primary key AND tenant key every time, so a reference that no
     * longer resolves yields no facts rather than stale ones — and the gap says
     * which of those happened.
     *
     * THE TENANT COMES FROM THE TOKEN, TWICE OVER. sessionExists() already
     * confines the session to tenantId(), and the ContextQuery below is built
     * from the same attribute. Nothing in the request body or the path can
     * reach the engine: `context_type` and `context_entity_id` are columns, not
     * inputs, and a row written under one tenant is unreadable from another.
     *
     * INTERPRETATION GOES THROUGH COACH, NOT THROUGH THIS METHOD. ADR-004
     * rejected free-form model calls and names seven verbs as the only
     * cognitive operations in the system; COACH is the one of those seven that
     * interprets an entity's context for whoever asked about it. This method
     * assembles grounding, hands it to the verb, and records what came back —
     * it builds no prompt, calls no provider and draws no conclusion, so every
     * governance, citation and sufficiency guarantee lives where ADR-004 put
     * it. See interpret() below.
     *
     * THE ENVELOPE NOW MATCHES WHAT THE SPA ALREADY READS. AIAssistant.tsx and
     * ConversationWorkspace.tsx both consume `result.message`, and this
     * endpoint returned a bare row — so `result.message` was undefined and
     * every sent message appended nothing to the transcript. `message` is the
     * row those two clients were always written against.
     */
    public function sendMessage(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'min:1'],
            'role'    => ['nullable', 'in:user,assistant,system'],
        ]);

        if (! $this->sessionExists($request, $id)) {
            return response()->json(['error' => 'session_not_found'], 404);
        }

        $now = now()->format('Y-m-d H:i:s');
        $tenant = $this->tenantId($request);

        [$context, $grounding, $gaps, $refs] = $this->groundingForSession($request, $id);

        $row = [
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant, 'session_id' => $id,
            'role' => $data['role'] ?? 'user', 'content' => $data['content'],
            // The refs the facts came from, on the message they were resolved
            // for. The column has existed since 2026_01_01_001600 and has only
            // ever held '[]'; a conversation whose grounding is recorded per
            // message is one whose answers can be checked later against what
            // was actually known at the time.
            'citations' => json_encode($refs),
            /*
              NO created_by, BECAUSE THE COLUMN DOES NOT EXIST — and writing it
              is why this endpoint has never worked.

              hpbrain_conversation_messages is id, tenant_id, session_id, role,
              content, citations, created_date. Confirmed against BOTH the
              migration (2026_01_01_001600_conversation_engine) and the live
              MariaDB table on 2026-09-14. This method has always inserted
              created_by, so every send from the AI Assistant answered

                  SQLSTATE[42S22]: Column not found: 'created_by'

              as a 500. The single message row in the live table came from
              AiWorkspaceService, which writes the correct column list.

              The author of a message is recoverable without the column: a
              session records created_by, and `role` separates the person from
              the assistant. Adding a column to carry what the schema already
              implies would be the wrong half of this fix.
            */
            'created_date' => $now,
        ];

        DB::transaction(function () use ($row, $tenant, $id, $now) {
            DB::table('hpbrain_conversation_messages')->insert($row);
            // Keep the session's ordering key current, or the list view drifts
            // out of recency order as soon as anyone replies to an old thread.
            DB::table('hpbrain_conversation_sessions')
                ->where('tenant_id', $tenant)->where('id', $id)
                ->update(['updated_date' => $now]);
        });

        /*
          INTERPRETATION RUNS ONLY FOR A READER'S OWN QUESTION.

          A message posted as `assistant` or `system` is a transcript write, not
          a question — running COACH on one would answer the Brain's own words
          back to it. Only a `user` turn with resolved context is interpreted.
        */
        $answer = $row['role'] === 'user' && $context !== null
            ? $this->interpret($request, $id, $context, (string) $data['content'], $now)
            : null;

        return response()->json([
            'message' => $row,
            'grounding' => [
                'rows'  => $grounding,
                'gaps'  => $gaps,
                'refs'  => $refs,
                // The same facts as one fenced block — what a caller that ever
                // prompts a model would place in the USER turn, verbatim. See
                // ContextGrounding::facts() for why it carries no directives.
                'facts' => $this->grounding->facts(
                    $context ?? $this->emptyContext($tenant)
                ),
            ],
            'context' => $context?->toArray(),
            // The COACH result, verbatim as VerbResult serialises it — DECIDED
            // with the three sections, or UNDETERMINED naming its gaps. Null
            // when there was nothing to interpret.
            'answer' => $answer,
        ], 201);
    }

    /**
     * Run COACH over the session's resolved context and record what it said.
     *
     * THE CONTROLLER DOES NO REASONING AND BUILDS NO PROMPT. It hands the
     * already-resolved context and the reader's text to the verb, and writes
     * down the result. Governance, grounding, the model call, the citation
     * guardrail and the sufficiency gate all live in CoachVerb → VerbPipeline,
     * which is where ADR-004 puts them.
     *
     * AN UNDETERMINED ANSWER IS STILL RECORDED, and that is the point of
     * recording at all. "The evidence does not settle this, and here is what is
     * missing" is the honest reply to a great many real questions, and a
     * transcript that silently omitted it would leave the reader looking at
     * their own question with no response — indistinguishable from a failure.
     *
     * A REFUSAL IS NOT A FAULT. VerbPipeline throws for a denied governance
     * pre-check; ReasoningEngineController answers 403 for that, but here the
     * reader's message has already been stored and a 403 would discard a
     * successful write. It is recorded as an undetermined answer naming the
     * refusal instead.
     *
     * @return array<string, mixed>
     */
    private function interpret(
        Request $request,
        string $sessionId,
        ResolvedContext $context,
        string $question,
        string $now,
    ): array {
        try {
            $result = app(CoachVerb::class)->run(
                $context,
                $question,
                $this->actorId($request),
                (string) $request->attributes->get('auth.role'),
            );
        } catch (RuntimeException $e) {
            $result = VerbResult::undetermined(['verb_refused:'.$e->getMessage()]);
        }

        $serialised = $result->jsonSerialize();

        DB::table('hpbrain_conversation_messages')->insert([
            'id'         => Uuid::uuid4()->toString(),
            'tenant_id'  => $context->tenantId,
            'session_id' => $sessionId,
            'role'       => 'assistant',
            // The transcript needs text; the structure travels in the response.
            // Rendered by the verb's own sections, never by a sentence written
            // in this file — see CoachVerb::transcript().
            'content'    => CoachVerb::transcript($result),
            // The ids the surviving claims actually cited, intersected with the
            // grounding by GroundedClaims before they got here. An undetermined
            // result still records what it DID have.
            'citations'  => json_encode($result->evidenceRefs),
            'created_date' => $now,
        ]);

        return ['verb' => Verb::COACH->value] + $serialised;
    }

    /**
     * Resolve the grounding for one session, from what the SERVER stored on it.
     *
     * Returns a null context — and a single gap — when the session was not
     * opened over an object. That is the unchanged case: a conversation started
     * from the home screen has no subject, and inventing one to fill the field
     * is the failure this whole path exists to avoid.
     *
     * @return array{0: ?ResolvedContext, 1: array<int, array<string, mixed>>, 2: array<int, string>, 3: array<int, string>}
     */
    private function groundingForSession(Request $request, string $sessionId): array
    {
        $tenant = $this->tenantId($request);

        // Tenant-scoped, like every other read of this table. A session id is a
        // UUID, not a capability.
        $session = DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $tenant)
            ->where('id', $sessionId)
            ->select('context_type', 'context_entity_id')
            ->first();

        $type = $session?->context_type === null ? null : (string) $session->context_type;
        $entityId = $session?->context_entity_id === null ? null : (string) $session->context_entity_id;

        if ($type === null || $type === '' || $entityId === null || $entityId === '') {
            return [null, [], ['context_not_bound_to_session'], []];
        }

        $context = app(ContextEngine::class)->resolve(new ContextQuery(
            // Server-resolved, from EnsureTenantScope.
            tenantId: $tenant,
            userId: $this->actorId($request),
            role: is_string($r = $request->attributes->get('auth.role')) ? $r : null,
            // No screen: this resolution is driven by a conversation, not by a
            // screen the caller is on. The stored type is explicit and needs no
            // screen binding to interpret.
            screen: null,
            objectId: $entityId,
            objectType: $type,
        ));

        return [
            $context,
            $this->grounding->rows($context),
            $this->grounding->gaps($context),
            $this->grounding->refs($context),
        ];
    }

    /**
     * A context with nothing in it, for rendering the block when the session
     * has no subject.
     *
     * Built rather than skipped so the response shape is the same either way —
     * a client branching on the presence of a key learns less than one reading
     * an explicit `context_not_bound_to_session` gap.
     */
    private function emptyContext(string $tenantId): ResolvedContext
    {
        $absent = fn (string $reason) => ContextLayer::absent($reason);

        return new ResolvedContext(
            tenantId: $tenantId,
            object: $absent('context_not_bound_to_session'),
            user: $absent('context_not_bound_to_session'),
            organization: $absent('context_not_bound_to_session'),
            signals: $absent('context_not_bound_to_session'),
            session: $absent('context_not_bound_to_session'),
        );
    }

    public function setPinned(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);

        $n = DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['pinned' => $data['pinned'] ? 1 : 0]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'session_not_found'], 404);
    }

    public function rename(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:1', 'max:255']]);

        $n = DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['title' => $data['title'], 'updated_date' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'session_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);

        return DB::transaction(function () use ($tenant, $id) {
            // Messages first — orphaned message rows would otherwise accumulate
            // invisibly, since nothing lists messages without a session.
            DB::table('hpbrain_conversation_messages')
                ->where('tenant_id', $tenant)->where('session_id', $id)->delete();

            $n = DB::table('hpbrain_conversation_sessions')
                ->where('tenant_id', $tenant)->where('id', $id)->delete();

            return $n
                ? response()->json(['ok' => true])
                : response()->json(['error' => 'session_not_found'], 404);
        });
    }

    public function promptTemplates(Request $request): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_prompt_templates')->where('tenant_id', $this->tenantId($request))
                ->orderBy('name')->get()
        );
    }

    public function storePromptTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'min:1', 'max:190'],
            'template' => ['required', 'string', 'min:1'],
            'version'  => ['nullable', 'string', 'max:50'],
        ]);

        $now = now()->format('Y-m-d H:i:s');

        // Prompts are versioned artifacts (ADR-004): changing one is a reviewed,
        // version-bumped change, never an in-place edit.
        $row = [
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $this->tenantId($request),
            'name' => $data['name'], 'template' => $data['template'],
            'version' => $data['version'] ?? 'v1',
            'created_by' => $this->actorId($request), 'created_date' => $now,
        ];

        DB::table('hpbrain_prompt_templates')->insert($row);

        return response()->json($row, 201);
    }

    private function sessionExists(Request $request, string $id): bool
    {
        return DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->exists();
    }
}
