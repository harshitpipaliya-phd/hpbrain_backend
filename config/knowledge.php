<?php

declare(strict_types=1);

/*
 * One source of truth for how the RETRIEVE surfaces judge what they hold.
 *
 * Knowledge Library and Organizational Memory both have to answer "how much of
 * this can we stand behind?". The thresholds that decide it live here rather
 * than in either service, so the two screens can never grade the same evidence
 * differently, and so changing what "stale" means is one edit.
 */
return [

    /*
     * FRESHNESS. Measured from the last update, falling back to creation.
     *
     * Knowledge does not expire on a schedule, but a procedure nobody has
     * touched in half a year is a different object from one revised last week,
     * and the reader deserves to be told which they are looking at before they
     * act on it.
     */
    'freshness' => [
        'fresh_days' => 90,
        'aging_days' => 180,
        // Beyond aging_days an asset is 'stale'. Not hidden — a stale SOP is
        // still the only SOP, and hiding it would leave the reader with
        // nothing and no explanation.
    ],

    /*
     * CONFIDENCE VOCABULARY. The words the UI is allowed to use, and what each
     * one requires. A claim may never be labelled above the tier its evidence
     * supports.
     *
     *   CONFIRMED    — high stated confidence AND at least one evidence row.
     *   SUPPORTED    — moderate confidence, or high confidence with no evidence.
     *   INFERRED     — a stated confidence too low to lean on.
     *   UNDETERMINED — nothing on file. Rendered as the word, never as 0%.
     */
    'confidence' => [
        'confirmed' => 0.85,
        'supported' => 0.65,
    ],

    /*
     * PROVENANCE. How a row came to exist, which is not the same question as
     * whether it is true.
     *
     * Rows written by a seeder are labelled and can be filtered out. They are
     * NOT silently presented as the organization's own experience: a demo
     * learning shown unlabelled beside a measured one teaches the reader to
     * trust both equally, and only one of them was earned.
     *
     * `seeded_actors` matches against created_by; `seeded_flag` is the key
     * seeders set inside a provenance JSON blob.
     */
    'provenance' => [
        'seeded_actors' => ['demo-seeder', 'fv-demo-seeder', 'loop-seeder'],
        'seeded_flag' => 'demo',
    ],

    'pagination' => [
        'page_size' => 24,
        'max_page_size' => 100,
    ],
];
