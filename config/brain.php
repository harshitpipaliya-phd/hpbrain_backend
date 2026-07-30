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
];
