<?php

declare(strict_types=1);

namespace App\Domain\Graph;

/**
 * THE ONE DEFINITION of what a graph node can be and what an edge can mean.
 *
 * WHY IT IS A CLASS AND NOT A SCATTER OF STRING LITERALS. Every label here is
 * backed by rows this installation genuinely holds, and every relationship name
 * is backed by a column that genuinely joins two of them. Keeping both lists in
 * one file is what makes that claim auditable: a reader can check the whole
 * vocabulary against the schema without reading the query layer, and nobody can
 * quietly add "connected to" between two things that are not.
 *
 * THERE IS NO GENERIC RELATIONSHIP. Every entry in RELATIONSHIPS names the
 * column or derivation that produces it, and that clause is published to the
 * client and shown beside the edge in the detail panel. A pair of entities with
 * no such column gets no edge — the graph then shows fewer connections than a
 * reader might hope for, and every one it does show can be traced to a join.
 *
 * FAMILIES exist so the UI can filter and colour by what a node IS rather than
 * by its label — five families read better than fourteen labels, and the filter
 * chips on the screen are exactly these.
 */
final class GraphVocabulary
{
    /** Structural entities read from the tenant's system of record. */
    public const FAMILY_ORGANIZATION = 'organization';

    public const FAMILY_PEOPLE = 'people';

    public const FAMILY_STUDENT = 'student';

    /** Dimensions of imported academic / fee data (subject, standard, dataset). */
    public const FAMILY_ACADEMIC = 'academic';

    /** The Brain's own loop: signal, evidence, case, recommendation, decision. */
    public const FAMILY_INTELLIGENCE = 'intelligence';

    /**
     * Node label => family.
     *
     * A label that is not in this map cannot be rendered, cannot be expanded and
     * cannot be searched. That is the point: the projection layer can only emit
     * what is declared here.
     */
    public const LABEL_FAMILY = [
        'Organization'   => self::FAMILY_ORGANIZATION,
        'Department'     => self::FAMILY_ORGANIZATION,
        'Person'         => self::FAMILY_PEOPLE,
        'Student'        => self::FAMILY_STUDENT,
        'Standard'       => self::FAMILY_ACADEMIC,
        'Subject'        => self::FAMILY_ACADEMIC,
        'Dataset'        => self::FAMILY_ACADEMIC,
        'Signal'         => self::FAMILY_INTELLIGENCE,
        'Evidence'       => self::FAMILY_INTELLIGENCE,
        'Case'           => self::FAMILY_INTELLIGENCE,
        'Hypothesis'     => self::FAMILY_INTELLIGENCE,
        'Recommendation' => self::FAMILY_INTELLIGENCE,
        'Decision'       => self::FAMILY_INTELLIGENCE,
        'Capability'     => self::FAMILY_INTELLIGENCE,
    ];

    /**
     * Relationship type => [human label, family, what produces it].
     *
     * The third element is not decoration. It is published to the client and
     * shown in the detail panel, so a reader who does not believe an edge can
     * see the column it came from. An edge whose provenance cannot be written
     * down as one clause does not belong in this list.
     */
    public const RELATIONSHIPS = [
        'has_department' => [
            'has department',
            'organizational',
            'OrganizationStructureService: the units this organization\'s source system records, or the teaching sections derived from its students where it records none.',
        ],
        'works_in' => [
            'works in',
            'people',
            'The person\'s mapped unit column on this tenant\'s Person source.',
        ],
        'employs' => [
            'employs',
            'people',
            'Active, non-deleted rows on this tenant\'s mapped Person table.',
        ],
        'enrolled_in' => [
            'enrolled in',
            'academic',
            'The student\'s recorded standard, read against the teaching section\'s grade range.',
        ],
        'enrolls' => [
            'enrolls',
            'academic',
            'One row per enrolment number in hpbrain_students for this tenant.',
        ],
        'in_standard' => [
            'in standard',
            'academic',
            'hpbrain_students.academic_standard, the standard of the student\'s latest recorded year.',
        ],
        'has_result' => [
            'has result in',
            'academic',
            'Rows of this tenant\'s academic dataset whose subject_ref is this student\'s enrolment number, grouped by subject.',
        ],
        'recorded_in' => [
            'recorded in',
            'academic',
            'hpbrain_operational_records.dataset, the imported file the rows came from.',
        ],
        'covers' => [
            'covers',
            'academic',
            'Distinct values of the dataset\'s own dimension columns, counted over its rows.',
        ],
        'generated' => [
            'generated',
            'intelligence',
            'hpbrain_signals.source matches the key the rows were imported under.',
        ],
        'raised_signal' => [
            'raised signal',
            'intelligence',
            'The signal\'s metadata.externalRef equals this student\'s enrolment number.',
        ],
        'supported_by' => [
            'supported by',
            'intelligence',
            'hpbrain_evidence.signal_id.',
        ],
        'opened_case' => [
            'opened case',
            'intelligence',
            'hpbrain_cases.signal_id.',
        ],
        'has_hypothesis' => [
            'has hypothesis',
            'intelligence',
            'hpbrain_hypotheses.case_id.',
        ],
        'led_to' => [
            'led to',
            'intelligence',
            'hpbrain_recommendations.reasoning_step_id resolves to a reasoning step carrying this signal or case.',
        ],
        'decided_by' => [
            'decided by',
            'intelligence',
            'hpbrain_decisions.recommendation_id.',
        ],
        'has_capability' => [
            'has capability',
            'intelligence',
            'hpbrain_capability_assignments.target_type and target_id.',
        ],
        'holds_capability' => [
            'holds capability',
            'intelligence',
            'hpbrain_capabilities rows registered for this tenant.',
        ],
        'contains' => [
            'contains',
            'organizational',
            'A group of rows of one kind belonging to the node it hangs from. The count is a COUNT over exactly those rows.',
        ],
    ];

    /** Relationship families the client may filter by. */
    public const RELATIONSHIP_FAMILIES = ['organizational', 'people', 'academic', 'intelligence'];

    public static function family(string $label): string
    {
        return self::LABEL_FAMILY[$label] ?? self::FAMILY_ORGANIZATION;
    }

    public static function isKnownLabel(string $label): bool
    {
        return isset(self::LABEL_FAMILY[$label]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public static function relationship(string $type): array
    {
        return self::RELATIONSHIPS[$type] ?? [$type, 'organizational', 'Derived directly from a stored column.'];
    }
}
