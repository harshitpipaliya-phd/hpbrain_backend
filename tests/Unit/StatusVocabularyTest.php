<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\StatusVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * The status classifier, and the two ways it can quietly ruin a completion rate.
 *
 * ONE: A WORD IT DOES NOT RECOGNISE MUST NOT BECOME "OPEN". Every unrecognised
 * status silently bucketed as open would make an organization whose source
 * system writes in another language, or in codes, report a completion rate near
 * zero — a devastating finding about a business, produced entirely by a lookup
 * miss. `unknown` is returned, the consumer excludes it from the denominator,
 * and the share it could classify is published beside the rate.
 *
 * TWO: THE NEGATED FORMS. "Not started" contains "start" and "unresolved"
 * contains "resolved", so a naive substring match reports work that has not
 * begun as work in progress, and unresolved complaints as resolved. Both are
 * pinned below because both are ordinary words in real exports.
 */
final class StatusVocabularyTest extends TestCase
{
    /**
     * @dataProvider terminalCompletions
     */
    public function test_it_recognises_completion(string $status): void
    {
        self::assertSame(StatusVocabulary::COMPLETED, StatusVocabulary::classify($status), $status);
    }

    /** @return array<string, array{string}> */
    public static function terminalCompletions(): array
    {
        return [
            'plain' => ['Completed'],
            'closed' => ['Closed'],
            'resolved' => ['Resolved'],
            'lowercase' => ['done'],
            'with punctuation' => ['CLOSED - VERIFIED'],
            'domain word: paid' => ['Paid'],
            'domain word: installed' => ['Installed'],
            'domain word: handover' => ['Handed Over'],
        ];
    }

    /**
     * @dataProvider abandonments
     */
    public function test_it_recognises_abandonment(string $status): void
    {
        self::assertSame(StatusVocabulary::CANCELLED, StatusVocabulary::classify($status), $status);
    }

    /** @return array<string, array{string}> */
    public static function abandonments(): array
    {
        return [
            'cancelled' => ['Cancelled'],
            'american' => ['Canceled'],
            'rejected' => ['Rejected'],
            'failed' => ['Failed'],
            'duplicate' => ['Duplicate'],
        ];
    }

    /**
     * ABANDONMENT IS TESTED BEFORE COMPLETION, and this is why.
     *
     * A source that writes "Cancelled and closed" means cancelled. Ordering the
     * families the other way round would count every abandoned job as delivered
     * — which inflates the headline completion rate in exactly the situation
     * where it most needs to be honest.
     */
    public function test_abandonment_wins_over_completion_when_both_words_appear(): void
    {
        self::assertSame(StatusVocabulary::CANCELLED, StatusVocabulary::classify('Cancelled and closed'));
    }

    /**
     * @dataProvider negations
     */
    public function test_negated_forms_are_not_their_positives(string $status, string $expected): void
    {
        self::assertSame($expected, StatusVocabulary::classify($status), $status);
    }

    /** @return array<string, array{string, string}> */
    public static function negations(): array
    {
        return [
            // Contains "start"; a substring match would call this in progress.
            'not started' => ['Not Started', StatusVocabulary::OPEN],
            'not-started' => ['not-started', StatusVocabulary::OPEN],
            // Contains "resolved"; a substring match would call this complete.
            'unresolved' => ['Unresolved', StatusVocabulary::OPEN],
            'not resolved' => ['Not Resolved', StatusVocabulary::OPEN],
            // Contains "complete".
            'incomplete' => ['Incomplete', StatusVocabulary::OPEN],
            // Contains "open" and means it.
            'reopened' => ['Reopened', StatusVocabulary::OPEN],
            // Contains "paid" but is not settled.
            'unpaid' => ['Unpaid', StatusVocabulary::OPEN],
            'partially paid' => ['Partially Paid', StatusVocabulary::PROGRESS],
        ];
    }

    /**
     * @dataProvider unrecognisable
     */
    public function test_unrecognised_statuses_are_unknown_not_open(mixed $status): void
    {
        self::assertSame(
            StatusVocabulary::UNKNOWN,
            StatusVocabulary::classify($status),
            'An unrecognised status must never be bucketed into a real workflow state: '
            .'it would enter a completion rate as a failure and there would be nothing on the screen to say why.',
        );
    }

    /** @return array<string, array{string|null}> */
    public static function unrecognisable(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'a code' => ['ST-4471'],
            'another language' => ['સંપૂર્ણ'],
            'a bare number' => ['0'],
        ];
    }

    public function test_terminality_covers_both_finished_and_abandoned(): void
    {
        self::assertTrue(StatusVocabulary::isTerminal(StatusVocabulary::COMPLETED));
        self::assertTrue(StatusVocabulary::isTerminal(StatusVocabulary::CANCELLED));
        self::assertFalse(StatusVocabulary::isTerminal(StatusVocabulary::OPEN));
        self::assertFalse(StatusVocabulary::isTerminal(StatusVocabulary::PROGRESS));
        self::assertFalse(
            StatusVocabulary::isTerminal(StatusVocabulary::UNKNOWN),
            'An unknown status says nothing about whether the work is still in flight.',
        );
    }

    /**
     * The real vocabulary from the connected telecom operator's exports, so a
     * change to the lexicon that breaks the live data fails here rather than on
     * a dashboard.
     */
    public function test_it_classifies_the_status_words_present_in_live_exports(): void
    {
        $observed = [
            // hpbrain_operational_records, tenant 1000018, read from the live
            // database: these are the actual strings, with their actual volumes.
            'Not Started' => StatusVocabulary::OPEN,          // complaint, 6,972
            'Completed' => StatusVocabulary::COMPLETED,       // complaint, 5,630
            'First Call Resolve' => StatusVocabulary::COMPLETED, // complaint, 2,514
            'Closed' => StatusVocabulary::COMPLETED,          // job_order, 14,986
            'Cancel' => StatusVocabulary::CANCELLED,          // job_order, 1,410
            'Open' => StatusVocabulary::OPEN,                 // job_order, 109
            'In Progress' => StatusVocabulary::PROGRESS,
            'Pending' => StatusVocabulary::OPEN,
        ];

        foreach ($observed as $status => $expected) {
            self::assertSame($expected, StatusVocabulary::classify($status), $status);
        }
    }

    /**
     * THE SHORT-WORD COLLISIONS THAT SUBSTRING MATCHING GOT WRONG.
     *
     * Every one of these was misclassified by the first version of this class,
     * and every one was found in live data rather than imagined:
     *
     *   "Suspended Renewal"  4,452 sales calls, reported as OPEN work
     *   "Renewal"              711 sales calls, reported as OPEN work
     *   "Inactive"                                reported as IN PROGRESS
     *
     * A renewal is a KIND of work, not a state it is in, so `unknown` is the
     * correct answer and the consumer excludes it from every rate.
     *
     * @dataProvider embeddedWords
     */
    public function test_a_short_word_inside_a_longer_one_does_not_match(string $status, string $expected): void
    {
        self::assertSame($expected, StatusVocabulary::classify($status), $status);
    }

    /** @return array<string, array{string, string}> */
    public static function embeddedWords(): array
    {
        return [
            // "renewal" contains "new"
            'renewal' => ['Renewal', StatusVocabulary::UNKNOWN],
            'suspended renewal' => ['Suspended Renewal', StatusVocabulary::UNKNOWN],
            // ... but "new" on its own still means new work.
            'new connection' => ['New Connection', StatusVocabulary::OPEN],
            // "inactive" contains "active"
            'inactive' => ['Inactive', StatusVocabulary::UNKNOWN],
            'active' => ['Active', StatusVocabulary::PROGRESS],
            // Sales-call types that are not workflow states at all.
            'shifting' => ['Shifting', StatusVocabulary::UNKNOWN],
            'upgrade' => ['Upgrade', StatusVocabulary::UNKNOWN],
            'static' => ['Static', StatusVocabulary::UNKNOWN],
            // Bare flags an ERP writes where a status belongs.
            'numeric flag' => ['0', StatusVocabulary::UNKNOWN],
        ];
    }
}
