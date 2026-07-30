<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * The events of the Organizational Intelligence Loop (Foundation Build
 * Reference §3.2), as they are written to hpbrain_event_store.type.
 *
 * WHY AN ENUM AND NOT STRING LITERALS. An event type is matched, never parsed:
 * consumers select on it, the replay tool groups by it, the Event Store screen
 * filters on it. A typo therefore produces a row that is written successfully,
 * counted in the totals, and matched by nobody — a hole in the loop that no
 * test catches, because nothing failed. That is not hypothetical here:
 * MemoryGrounding writes 'LearningGrounded' into a column named `event_type`
 * that does not exist, and nothing noticed until the events surface was read.
 *
 * The values are the wire format. They are consumed by the SPA and stored in
 * rows that outlive this code, so they are PascalCase past-tense facts and must
 * not be renamed without a migration of the existing rows.
 */
enum LoopEvent: string
{
    /** (1) Reality — a principal opens a session. */
    case SESSION_STARTED = 'SessionStarted';

    /** (3) Reality — the principal picks what they are reasoning about. */
    case SUBJECT_SELECTED = 'SubjectSelected';

    /** (5) Sense — something was noticed and recorded as a signal. */
    case OBSERVATION_MADE = 'ObservationMade';

    /** (6) Sense — the observation was corroborated with provenance. */
    case EVIDENCE_RECORDED = 'EvidenceRecorded';

    /** (7) Reason — a reasoning step was taken over that evidence. */
    case DELIBERATED = 'Deliberated';

    /** (8) Decide — the human governance gate opened. */
    case DECISION_REACHED = 'DecisionReached';

    /** (9) Execute — an ESO started running against the decision. */
    case EXECUTION_STARTED = 'ExecutionStarted';

    /** (10) Measure — what actually happened, cited to evidence. */
    case OUTCOME_RECORDED = 'OutcomeRecorded';

    /** (11) Learn — the outcome became a reusable learning. */
    case LEARNING_WRITTEN = 'LearningWritten';

    /** (11) Learn — organizational memory absorbed it. */
    case MEMORY_UPDATED = 'MemoryUpdated';

    /**
     * Not a golden-path stage: the traceability record ADR-005 requires, saying
     * which prior learning shaped a later result.
     */
    case LEARNING_GROUNDED = 'LearningGrounded';

    /**
     * The loop in order, first stage to last. Module 6's flow test walks
     * exactly this list, so the order here IS the specification of the loop —
     * reordering these cases changes what "the loop closed" means.
     *
     * LEARNING_GROUNDED is deliberately absent: it can be emitted at any stage
     * that consults memory, so it has no position in a linear walk.
     *
     * @return array<int, self>
     */
    public static function goldenPath(): array
    {
        return [
            self::SESSION_STARTED,
            self::SUBJECT_SELECTED,
            self::OBSERVATION_MADE,
            self::EVIDENCE_RECORDED,
            self::DELIBERATED,
            self::DECISION_REACHED,
            self::EXECUTION_STARTED,
            self::OUTCOME_RECORDED,
            self::LEARNING_WRITTEN,
            self::MEMORY_UPDATED,
        ];
    }
}
