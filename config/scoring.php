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

        // Execution and operational performance carry the most because they
        // measure what the unit DID; data confidence carries least because it
        // measures how well the organization records, not how well the unit
        // works. Read by DepartmentProfile::dimensions() — verbatim values a
        // prior pass here hardcoded in the class itself (R7).
        'dimensions' => [
            'operational' => ['label' => 'Operational performance', 'weight' => 1.5],
            'workload'    => ['label' => 'Workload health',         'weight' => 1.25],
            'execution'   => ['label' => 'Execution reliability',   'weight' => 1.25],
            'people'      => ['label' => 'People coverage',         'weight' => 1.0],
            'service'     => ['label' => 'Service health',          'weight' => 1.0],
            'signal'      => ['label' => 'Signal health',           'weight' => 1.0],
            'confidence'  => ['label' => 'Data confidence',         'weight' => 0.75],
        ],

        // score >= 85 => healthy, 70-84 => good, 50-69 => watch, <50 => critical.
        'bands' => [
            'healthy' => 85,
            'good'    => 70,
            'watch'   => 50,
        ],
    ],

    'organization' => [

        // Per-dimension weight in OrganizationScorecard's renormalised mean.
        // Execution carries the most because it measures whether work that
        // starts gets finished; deliberation carries the least because it
        // measures the investigation loop rather than the operation itself.
        // Read by OrganizationScorecard — verbatim values a prior pass here
        // hardcoded in the class itself (R7).
        'weights' => [
            'dataCoverage'       => 1.0,
            'executionHealth'    => 1.4,
            'workloadHealth'     => 1.2,
            'responsiveness'     => 1.0,
            'serviceHealth'      => 1.2,
            'departmentHealth'   => 0.9,
            'signalHealth'       => 1.0,
            'evidenceStrength'   => 1.0,
            'deliberationHealth' => 0.8,
            'capabilityCoverage' => 1.0,
        ],

        // score >= 85 => excellent, 70-84 => healthy, 55-69 => watch, <55 => needs attention.
        'bands' => [
            'excellent' => 85,
            'healthy'   => 70,
            'watch'     => 55,
        ],
    ],
];
