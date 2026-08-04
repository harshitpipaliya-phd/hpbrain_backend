<?php

declare(strict_types=1);

/**
 * HP Enterprise Brain — product constants.
 *
 * These are not tuning knobs. Each one encodes a rule stated in the Product
 * Bible or the Architecture Invariants, and changing one changes the
 * product's behaviour, not just its configuration.
 */
return [

    /*
     | Reasoning confidence (Product Bible — "confidence is computed, never
     | asserted"). A lone signal with no corroborating evidence is a weak
     | hypothesis, not a claim. Each piece of evidence adds up to 0.15,
     | weighted by that evidence's own confidence AND its freshness — stale
     | evidence corroborates less even at high stated confidence. The cap is
     | below 1.0 because reasoning is never fully certain; that is what
     | Outcome capture is for.
     */
    'reasoning' => [
        'base_confidence'      => 0.30,
        'evidence_weight'      => 0.15,
        'confidence_ceiling'   => 0.95,
        'low_confidence_floor' => 0.40,
    ],

    /*
     | Evidence freshness: exponential half-life decay, NOT day-banding.
     | Evidence does not expire, it attenuates — freshness = 0.5^(ageDays/H).
     | At H days old a piece of evidence corroborates exactly half as
     | strongly as when it was observed. Ported verbatim from
     | FRESHNESS_HALF_LIFE_DAYS in evidence.service.ts.
     */
    'evidence' => [
        'freshness_half_life_days' => 90,
    ],

    /*
     | KASBA. Five dimensions, assessed 0-5. An unassessed dimension is null
     | and must never be defaulted to zero — "a fact the system hasn't
     | verified is null, never defaulted to 0" (Product Bible, Principles).
     */
    'kasba' => [
        'dimensions' => ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'],
        'max_level'  => 5,
    ],

    /*
     | The eight root-cause families a hypothesis is classified against.
     | Source of truth: contracts/taxonomy/root-cause.schema.yaml, which in
     | turn quotes the Product Bible's Diagnostic Intelligence section.
     */
    'root_cause_families' => [
        'Capability', 'Capacity', 'Process', 'Information',
        'Motivation', 'Coordination', 'External', 'Policy',
    ],

    /*
     | Case lifecycle. A case may return to investigating when a hypothesis
     | is rejected and a new one is needed; it may only resolve with a
     | confirmed hypothesis attached.
     */
    'case_transitions' => [
        'open'         => ['investigating'],
        'investigating'=> ['hypothesized'],
        'hypothesized' => ['investigating', 'resolved'],
        'resolved'     => ['closed'],
        'closed'       => [],
    ],

    /*
     | AI (ADR-004). The provider is selected by NAME here and resolved to a
     | driver in AiServiceProvider; business logic never names a vendor.
     |
     | Default is '' — no provider. That is not a placeholder waiting to be
     | filled in: with no provider the reasoning verbs return UNDETERMINED
     | naming the gap, which is the honest answer and the one the golden
     | intelligence-flow test asserts. A missing key must degrade to "I don't
     | know", never to a 500 and never to invented text.
     |
     | The API key is read from the environment HERE, in config, so that
     | `php artisan config:cache` captures it. A provider that called env()
     | at request time would work locally and return null in production.
     | It is never committed: .env.example carries an empty value.
     */
    'ai' => [
        'provider' => env('AI_PROVIDER', ''),
        'model'    => env('AI_MODEL', 'claude-sonnet-5'),

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'timeout' => (int) env('AI_TIMEOUT_SECONDS', 30),
        ],

        /*
         | USD per MILLION tokens. Used only to estimate
         | hpbrain_ai_executions.estimated_cost_usd — a model absent from this
         | map records a NULL cost rather than zero, because "not priced" and
         | "free" are different claims.
         */
        'pricing' => [
            'claude-opus-5'   => ['input' => 15.00, 'output' => 75.00],
            'claude-sonnet-5' => ['input' => 3.00,  'output' => 15.00],
            'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
        ],
    ],

    /*
     | Thresholds for signal rules over IMPORTED operational data
     | (App\Domain\Signals\OperationalSignalRules).
     |
     | Like everything else in this file these are product decisions, not tuning
     | knobs — each states what the business considers acceptable, and changing
     | one changes which conditions the Brain calls a problem. They are here
     | rather than inline so a deployment can hold a different service target
     | without a code change.
     |
     | Every default below was set against the real FY2025-26 distribution, so
     | that each rule fires on a genuine outlier rather than on ordinary
     | operation. A threshold that fires on everything trains people to ignore
     | the screen.
     */
    'operational_signals' => [
        // Complaints: hours before a resolution counts as a breach, and the
        // minimum number of breaches worth raising a signal about.
        'complaint_sla_hours'         => 24,
        'complaint_sla_minimum'       => 25,

        // Share of closed complaints with no root cause recorded before the
        // gap is reportable. A quarter is generous; the observed figure is 68%.
        'root_cause_blank_share'      => 0.25,

        // A subscriber complaining this many times in one month is one
        // unresolved fault, not several incidents.
        'repeat_complaint_threshold'  => 4,
        'repeat_complaint_minimum'    => 5,

        // How many times an even split a single zone must carry before its
        // concentration is treated as a network hotspot.
        'zone_concentration_multiple' => 4.0,

        // Provisioning: days pending before a job order is stalled.
        'work_order_pending_days'     => 15,
        'work_order_minimum'          => 5,

        // Share of job orders cancelled before connection.
        'cancellation_share'          => 0.04,

        // Share of offered help-desk calls dropped rather than answered.
        'call_drop_rate'              => 0.20,
    ],

    /*
     | The institute's existing ERP tables. The Brain reads Organization,
     | Department and Person from these — it does not own them. Everything
     | the Brain reasons WITH lives in hpbrain_* tables.
     */
    'erp_tables' => [
        'organization'   => 'institute_detail',
        'organization_x' => 'org_details',
        'department'     => 'hrms_departments',
        'person'         => 'tbluser',
        'person_profile' => 'tbluserprofilemaster',
    ],

    /*
    |--------------------------------------------------------------------------
    | Universal Platform Foundation (Prompt 3.1)
    |--------------------------------------------------------------------------
    |
    | Industry codes, default terminology, feature flag keys, module keys,
    | navigation structure, default dashboard layouts, and default themes.
    |
    */
    'universal' => [
        'industries' => [
            'healthcare', 'k12_education', 'higher_education', 'corporate',
            'manufacturing', 'retail', 'government', 'bfsi', 'ngo', 'technology',
        ],

        'default_terminology' => [
            'Person'            => 'Employee',
            'OrganizationUnit'   => 'Department',
            'Role'               => 'Role',
            'Skill'              => 'Skill',
            'Competency'         => 'Competency',
            'Capability'         => 'Capability',
        ],

        'feature_flags' => [
            'ai_workspace',
            'graph_explorer',
            'advanced_analytics',
            'beta_features',
        ],

        'modules' => [
            'intelligence', 'capabilities', 'decisions', 'analytics',
            'ai_workspace', 'graph_explorer', 'learning', 'policies',
            'risks', 'notifications',
        ],

        'navigation' => [
            'dashboard',
            'intelligence',
            'capabilities',
            'decisions',
            'analytics',
            'settings',
        ],

        'default_dashboard_layouts' => [
            'default' => [
                'layout_type'  => 'grid',
                'grid_columns' => 12,
                'grid_rows'    => 12,
                'widgets'      => [],
            ],
        ],

        'default_themes' => [
            'light',
            'dark',
        ],
    ],
];
