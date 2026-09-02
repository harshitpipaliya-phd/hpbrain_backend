<?php

declare(strict_types=1);

/*
 * One source of truth for the standing/band/weight model. Read by the person
 * intelligence service AND the department scorecard so the formula lives in
 * exactly one place (R7).
 *
 * Adding or re-weighting a dimension here changes every screen that renders a
 * verdict or a standing — including the new Person Profile.
 */
return [

    'person' => [

        // Components: label, weight, what it reads, what unmeasurable means.
        'components' => [
            'presence' => [
                'label'  => 'Presence reliability',
                'weight' => 1.4,
                'reads'  => 'attendance records with a status and (optionally) hours amount',
                'unmeasurable_reason' => 'No attendance records in the tenant window — presence is unmeasured, not zero.',
                'fix'    => 'Import an attendance dataset with a present/absent status to start measuring.',
                'fix_route' => '/settings/integrations',
            ],
            'contribution_consistency' => [
                'label'  => 'Contribution consistency',
                'weight' => 1.2,
                'reads'  => 'weekly handled-record count over the last 8 weeks; low variance = consistent',
                'unmeasurable_reason' => 'Less than 3 weeks of handled volume — not enough data to score consistency.',
                'fix'    => 'Wait for the rolling 8-week window to fill, or attach records by name match.',
                'fix_route' => '/people',
            ],
            'capability' => [
                'label'  => 'Capability level',
                'weight' => 1.6,
                'reads'  => 'the most recent capability assessment for this person (KASBA overall, 0..5)',
                'unmeasurable_reason' => 'No capability assessment has been recorded for this person.',
                'fix'    => 'Schedule a KASBA assessment to start measuring capability.',
                'fix_route' => '/capabilities',
            ],
        ],

        // Penalty applied per recent contradiction (e.g. mismatch day). The cap
        // ensures one noisy dataset cannot drive the band by itself.
        'mismatch' => [
            'per_day'   => 1.5,
            'cap'       => 12.0,
        ],

        // Threshold for the long-hours check (D2).
        'long_hours' => [
            'threshold'   => 9.5,
            'weeks_min'   => 3,
        ],

        // Thresholds for the team-high-load top-decile check (D1).
        'top_decile' => 0.10,

        // Bands. score >= 85 => steady, 70-84 => watch, 55-69 => support, <55 => support.
        'bands' => [
            'steady'   => 85,
            'watch'    => 70,
            'support'  => 55,
        ],

        // Total measurable dimensions for the confidence ring (D6). Order
        // matters for the ring caption "X of Y dimensions measurable".
        'confidence_dimensions' => [
            'presence',
            'contribution',
            'consistency',
            'capability-level',
            'capability-trajectory',
            'role-relative',
            'loop-involvement',
        ],
    ],

    'department' => [
        // Kept here as a stub so the same file documents both models. The
        // OrganizationScorecard class still hardcodes the values; once that
        // moves here the band/weight lookups become config-driven.
    ],
];
