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
