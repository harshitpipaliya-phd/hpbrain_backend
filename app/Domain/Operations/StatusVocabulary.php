<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What a source system's status word means, in the four states every workflow has.
 *
 * WHY THIS IS UNIVERSAL AND NOT A PER-TENANT MAPPING TABLE. A telecom writes
 * "Closed", a school writes "Paid", a hospital writes "Discharged" — but the SHAPE
 * of a workflow status is the same everywhere: the work finished, the work was
 * abandoned, the work is being done, or the work is waiting. Nothing about that
 * taxonomy is industry specific, and every source system this product has ingested
 * spells its statuses in ordinary English tokens drawn from the same small
 * vocabulary. Matching on those tokens gets the classification right for a tenant
 * nobody has configured, which is the only way an intelligence engine can say
 * anything on the day an organization first connects its data.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * UNRECOGNISED IS A FIRST-CLASS ANSWER, AND THE MOST IMPORTANT ONE HERE.
 *
 * A status this class does not recognise is returned as `unknown`, counted
 * separately, and EXCLUDED from every rate — never bucketed into "open" to keep
 * the arithmetic tidy. The consumer publishes how much of the population it could
 * classify alongside the rate itself, so a completion figure computed over a third
 * of the records is visibly a completion figure computed over a third of the
 * records. Silently defaulting the unmatched to one bucket is how a scoring model
 * invents a finding: an organization whose statuses are all in Gujarati would
 * score 0% complete, and nothing on the screen would say why.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WORDS AND STEMS ARE MATCHED DIFFERENTLY, AND THE DISTINCTION IS LOAD-BEARING.
 *
 * The first version of this class matched every needle as a substring, and the
 * live data found the flaw immediately:
 *
 *     "Renewal"           →  contains "new"     →  reported as OPEN
 *     "Suspended Renewal" →  contains "new"     →  reported as OPEN
 *     "Inactive"          →  contains "active"  →  reported as IN PROGRESS
 *
 * All three are wrong, and two of them are wrong in the direction that inflates a
 * backlog: a telecom's 4,452 "Suspended Renewal" sales calls would have been
 * published as open work nobody had started.
 *
 * So the short, common words that embed inside longer unrelated ones are matched
 * ON WORD BOUNDARIES (`WORDS`), and only the long distinctive fragments that
 * cannot collide are matched as substrings (`STEMS`). "Renewal" now matches
 * nothing and is reported as unknown — which is correct, because a renewal is a
 * KIND of work, not a state it is in.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ORDER OF TESTS MATTERS, and the awkward cases are why the order is written out
 * rather than left to a loop over an unordered map:
 *
 *   "not started"  contains "start"    → must be tested as OPEN before PROGRESS
 *   "unresolved"   contains "resolved" → must be tested as OPEN before COMPLETED
 *   "reopened"     contains "open"     → OPEN, which the plain open test gets
 *
 * So the negated forms are checked first, as whole phrases, and only then do the
 * families run — abandonment before completion, because a source that writes
 * "cancelled and closed" means cancelled.
 *
 * TERMINAL-STATE COUNTS ARE THE ONLY THING DOWNSTREAM MAY ASSUME. This class says
 * nothing about whether finishing was good or abandoning was bad — a cancelled
 * sales call and a cancelled surgery are not comparable, and this class does not
 * try. It reports which of the four states a word denotes; what that means for the
 * organization is the scoring layer's judgement, stated there where it can be read.
 */
final class StatusVocabulary
{
    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const PROGRESS = 'progress';

    public const OPEN = 'open';

    public const UNKNOWN = 'unknown';

    /**
     * Phrases whose meaning is the OPPOSITE of a family they contain.
     *
     * Tested first, because each one would otherwise be captured by a family
     * below it.
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        'not started'     => self::OPEN,
        'notstarted'      => self::OPEN,
        'yet to start'    => self::OPEN,
        'unresolved'      => self::OPEN,
        'not resolved'    => self::OPEN,
        'incomplete'      => self::OPEN,
        'not complete'    => self::OPEN,
        'not completed'   => self::OPEN,
        'reopened'        => self::OPEN,
        'not done'        => self::OPEN,
        'undelivered'     => self::OPEN,
        'unpaid'          => self::OPEN,
        'not approved'    => self::OPEN,
        'partially paid'  => self::PROGRESS,
        'partly paid'     => self::PROGRESS,
        'part paid'       => self::PROGRESS,
    ];

    /**
     * Whole-word (or whole-phrase) needles, in the order the families are tested.
     *
     * Everything here is short enough, or common enough, to appear inside an
     * unrelated word. See the class docblock for the three live examples that
     * forced the split.
     *
     * @var array<string, array<int, string>>
     */
    private const WORDS = [
        self::CANCELLED => [
            'void', 'voided', 'lapsed', 'lapse', 'fail', 'failed', 'failure',
            'dropped', 'drop', 'lost', 'returned', 'return', 'no show', 'noshow',
            'not required', 'na',
        ],
        self::COMPLETED => [
            'closed', 'close', 'done', 'paid', 'approved', 'passed', 'received',
            'handed over', 'settled', 'ok', 'yes',
        ],
        self::PROGRESS => [
            'started', 'start', 'wip', 'assigned', 'running', 'active',
            'partial', 'partially', 'in transit', 'under review', 'attending',
            'visiting', 'doing',
        ],
        self::OPEN => [
            'open', 'new', 'todo', 'to do', 'queued', 'queue', 'draft', 'hold',
            'on hold', 'raised', 'created', 'logged', 'booked', 'planned',
            'proposed', 'await', 'unassigned', 'backlog',
        ],
    ];

    /**
     * Substring needles — long enough that they cannot appear inside a word of
     * unrelated meaning.
     *
     * @var array<string, array<int, string>>
     */
    private const STEMS = [
        self::CANCELLED => [
            'cancel', 'reject', 'declin', 'abandon', 'withdraw', 'expire',
            'invalid', 'duplicate', 'refus', 'terminat',
        ],
        self::COMPLETED => [
            'complet', 'resolv', 'finish', 'deliver', 'success', 'fulfil',
            'install', 'commission', 'discharg', 'verifi', 'accept',
        ],
        self::PROGRESS => [
            'progress', 'working', 'ongoing', 'process', 'allocat', 'executing',
            'reviewing', 'dispatch',
        ],
        self::OPEN => [
            'pending', 'awaiting', 'scheduled', 'submitted', 'requested',
            'registered', 'waiting',
        ],
    ];

    /**
     * Which of the four states a source system's status word denotes.
     *
     * Returns `unknown` for anything unmatched, including null and the empty
     * string. Every caller must handle that; see the class docblock.
     */
    public static function classify(?string $status): string
    {
        if ($status === null) {
            return self::UNKNOWN;
        }

        $normalised = self::normalise($status);

        if ($normalised === '') {
            return self::UNKNOWN;
        }

        foreach (self::OVERRIDES as $phrase => $state) {
            if ($normalised === $phrase || str_contains($normalised, $phrase)) {
                return $state;
            }
        }

        // Families in order: abandonment, completion, in flight, waiting.
        foreach (self::WORDS as $state => $words) {
            if (self::containsWord($normalised, $words)) {
                return $state;
            }

            foreach (self::STEMS[$state] as $stem) {
                if (str_contains($normalised, $stem)) {
                    return $state;
                }
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Whether a state means the work is no longer in flight.
     *
     * Completed and cancelled are both terminal; the distinction between them is
     * about outcome, not about whether the record is still consuming capacity.
     */
    public static function isTerminal(string $state): bool
    {
        return $state === self::COMPLETED || $state === self::CANCELLED;
    }

    /**
     * Whole-word containment against an already-normalised string.
     *
     * The normalised form is space-separated tokens, so a phrase needle matches
     * when it appears between token boundaries and a single-word needle matches
     * only a whole token. `str_contains(' renewal ', ' new ')` is false, which is
     * the entire point.
     *
     * @param  array<int, string>  $words
     */
    private static function containsWord(string $normalised, array $words): bool
    {
        $padded = ' '.$normalised.' ';

        foreach ($words as $word) {
            if (str_contains($padded, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, punctuation to spaces, runs of whitespace collapsed. */
    private static function normalise(string $status): string
    {
        $lower = mb_strtolower(trim($status));
        $spaced = preg_replace('/[^a-z0-9]+/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/', ' ', $spaced) ?? $spaced);
    }
}
