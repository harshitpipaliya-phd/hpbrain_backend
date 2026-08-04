<?php

declare(strict_types=1);

namespace App\Domain\Industry;

/**
 * What each industry actually does, as data.
 *
 * The Brain can only produce intelligence about capabilities somebody has
 * defined. Until this file existed, onboarding an organization gave it an empty
 * capability register, so every capability screen was correctly but uselessly
 * blank, and the single seeded example — "Classroom Management" — was a school's
 * vocabulary applied to a telecoms operator and two hospitals.
 *
 * THE POINT OF THIS FILE IS THAT IT IS A FILE. Adding an industry is adding an
 * entry here plus a row in hpbrain_industries. No controller, repository or
 * screen changes, because none of them name a capability — they read whatever
 * the tenant's industry provisioned. That is the difference between onboarding
 * being configuration and onboarding being a release.
 *
 * WHAT A PACK IS NOT. It is not a claim about a particular customer. It is the
 * starting register that a new tenant is provisioned with, expected to be
 * edited: renamed, retired, extended, re-weighted. Criticality especially is a
 * judgement each organization makes for itself. Provisioning writes these once
 * and never overwrites an edit — see BrainProvision.
 *
 * TERMINOLOGY IS PART OF THE PACK because the words are not cosmetic. A hospital
 * that reads "Department" where it says "Ward", or a bank that reads "Employee"
 * where it says "Officer", is being asked to translate on every screen, and
 * translation is where trust in a number goes. The labels here feed
 * hpbrain_terminology and reach the UI through useConfig().
 *
 * KASBA descriptors say what each dimension MEANS for that capability. They are
 * carried on the capability row so an assessor is not left inventing a private
 * definition of "Behaviour" per person, which is the fastest way to make
 * assessment data incomparable across a department.
 */
final class IndustryPack
{
    /**
     * Person is the label for EVERYONE the organization employs, not for its
     * most visible profession. A hospital employs porters, accountants and
     * cleaners; calling them all "Clinician" would be both wrong and, on a
     * headcount screen, misleading.
     *
     * EVERY LABEL IS A SINGLE NOUN OR A NOUN PHRASE THAT PLURALISES BY ADDING
     * -S. These words are composed into sentences server-side — "18 wards
     * without a manager" — so a label like "Ward or Unit" produces "18 ward or
     * units", which reads as a bug and undermines the number next to it. Where
     * an industry genuinely uses two words for the same level, the broader one
     * is chosen: a hospital's pharmacy is a unit but not a ward, so "Unit".
     */
    private const GENERIC_TERMS = [
        'Person'           => 'Employee',
        'OrganizationUnit' => 'Department',
        'Organization'     => 'Organization',
        'Position'         => 'Role',
        'Capability'       => 'Capability',
        'Skill'            => 'Skill',
        'Competency'       => 'Competency',
    ];

    /**
     * industry code => [label, terminology overrides, capabilities]
     *
     * Eight capabilities per industry, deliberately spread across a clinical or
     * operational core, a compliance obligation, a customer-facing duty and an
     * internal one — because an organization measured only on its core function
     * discovers its gaps in exactly the places it never looked.
     *
     * @var array<string, array{label: string, terminology: array<string, string>, capabilities: array<int, array<string, mixed>>}>
     */
    public const PACKS = [

        /* ───────────────────────────── HEALTHCARE ───────────────────────────── */
        'healthcare' => [
            'label'       => 'Healthcare',
            'terminology' => ['OrganizationUnit' => 'Unit', 'Person' => 'Staff member', 'Position' => 'Post'],
            'capabilities' => [
                ['code' => 'HC_CLINICAL_ASSESSMENT', 'name' => 'Clinical assessment', 'category' => 'Clinical practice',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Examining a patient, forming a differential and escalating appropriately.',
                 'kasba' => [
                     'knowledge' => 'Anatomy, physiology and the presentation of conditions common to this unit.',
                     'ability'   => 'Reaches a defensible differential from incomplete information under time pressure.',
                     'skill'     => 'Performs examination and interpretation accurately and repeatably.',
                     'behaviour' => 'Escalates early rather than late; documents findings contemporaneously.',
                     'attitude'  => 'Treats diagnostic uncertainty as something to state, not to conceal.',
                 ]],
                ['code' => 'HC_MEDICATION_SAFETY', 'name' => 'Medication safety', 'category' => 'Patient safety',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Prescribing, checking and administering medicines without avoidable harm.',
                 'kasba' => [
                     'knowledge' => 'Formulary, interactions, contraindications and this unit\'s high-alert list.',
                     'ability'   => 'Calculates doses correctly, including paediatric and renal adjustment.',
                     'skill'     => 'Executes the checking procedure fully even when the ward is busy.',
                     'behaviour' => 'Stops the line to query an order that looks wrong, regardless of who wrote it.',
                     'attitude'  => 'Reports own near-misses without being asked.',
                 ]],
                ['code' => 'HC_INFECTION_CONTROL', 'name' => 'Infection prevention and control', 'category' => 'Patient safety',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Preventing transmission through consistent practice, not occasional compliance.',
                 'kasba' => [
                     'knowledge' => 'Transmission routes, isolation categories and local outbreak policy.',
                     'ability'   => 'Selects correct precautions for an unfamiliar presentation.',
                     'skill'     => 'Hand hygiene and PPE technique to audit standard.',
                     'behaviour' => 'Maintains practice unobserved and challenges lapses by senior colleagues.',
                     'attitude'  => 'Treats the protocol as protecting the next patient, not as paperwork.',
                 ]],
                ['code' => 'HC_SAFEGUARDING', 'name' => 'Safeguarding', 'category' => 'Governance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Recognising and acting on risk to vulnerable patients.',
                 'kasba' => [
                     'knowledge' => 'Statutory duties, referral thresholds and local reporting routes.',
                     'ability'   => 'Distinguishes a concern that must be referred from one that must be monitored.',
                     'skill'     => 'Records a concern in terms that survive later scrutiny.',
                     'behaviour' => 'Raises a concern promptly and follows it up rather than assuming it was handled.',
                     'attitude'  => 'Acts on suspicion without needing certainty.',
                 ]],
                ['code' => 'HC_CLINICAL_DOCUMENTATION', 'name' => 'Clinical documentation', 'category' => 'Governance',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Records that are complete, contemporaneous and usable by the next clinician.',
                 'kasba' => [
                     'knowledge' => 'Record-keeping standards, coding requirements and retention rules.',
                     'ability'   => 'Summarises a complex encounter without losing the decisive detail.',
                     'skill'     => 'Uses the record system accurately and at working speed.',
                     'behaviour' => 'Writes at the time rather than at the end of the shift.',
                     'attitude'  => 'Writes for the clinician who inherits the patient, not for the auditor.',
                 ]],
                ['code' => 'HC_PATIENT_COMMUNICATION', 'name' => 'Patient and family communication', 'category' => 'Care experience',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Explaining condition, risk and choice so a patient can actually decide.',
                 'kasba' => [
                     'knowledge' => 'Consent requirements and the evidence behind the options being offered.',
                     'ability'   => 'Adjusts explanation to the person in front of them.',
                     'skill'     => 'Delivers difficult news clearly and checks it has been understood.',
                     'behaviour' => 'Invites questions and waits for them.',
                     'attitude'  => 'Treats the patient as the decision-maker.',
                 ]],
                ['code' => 'HC_EMERGENCY_RESPONSE', 'name' => 'Emergency response', 'category' => 'Clinical practice',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Performing a defined role competently during a deteriorating patient or arrest.',
                 'kasba' => [
                     'knowledge' => 'Current resuscitation algorithms and this site\'s escalation structure.',
                     'ability'   => 'Prioritises correctly when several things are wrong at once.',
                     'skill'     => 'Executes assigned interventions to protocol.',
                     'behaviour' => 'Communicates using closed loops; takes and gives direction cleanly.',
                     'attitude'  => 'Participates in the debrief honestly, including own errors.',
                 ]],
                ['code' => 'HC_ROSTER_PLANNING', 'name' => 'Roster and capacity planning', 'category' => 'Operations',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Matching staffing to demand within safe-staffing constraints.',
                 'kasba' => [
                     'knowledge' => 'Safe-staffing ratios, skill-mix requirements and working-time limits.',
                     'ability'   => 'Forecasts demand from historical and seasonal patterns.',
                     'skill'     => 'Builds a roster that survives predictable absence.',
                     'behaviour' => 'Flags an unsafe roster rather than filling it and hoping.',
                     'attitude'  => 'Treats staffing gaps as a clinical risk, not an administrative one.',
                 ]],
            ],
        ],

        /* ─────────────────────────────── BFSI ─────────────────────────────── */
        'bfsi' => [
            'label'       => 'Banking, Financial Services and Insurance',
            'terminology' => ['OrganizationUnit' => 'Division', 'Person' => 'Employee', 'Position' => 'Designation'],
            'capabilities' => [
                ['code' => 'FS_CREDIT_ASSESSMENT', 'name' => 'Credit assessment', 'category' => 'Risk',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Judging whether a borrower can and will repay, and pricing that judgement.',
                 'kasba' => [
                     'knowledge' => 'Credit policy, sector risk, security valuation and the current rate environment.',
                     'ability'   => 'Reads a set of financials and identifies what the borrower has not said.',
                     'skill'     => 'Builds and stress-tests a serviceability model.',
                     'behaviour' => 'Records the reasoning behind a marginal approval, not just the decision.',
                     'attitude'  => 'Declines business that does not meet policy, including from a valued relationship.',
                 ]],
                ['code' => 'FS_AML_KYC', 'name' => 'AML and KYC compliance', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Knowing who the customer is and recognising what should be reported.',
                 'kasba' => [
                     'knowledge' => 'Regulatory obligations, red-flag typologies and beneficial-ownership rules.',
                     'ability'   => 'Distinguishes unusual activity from suspicious activity.',
                     'skill'     => 'Completes due diligence to a standard that survives regulatory review.',
                     'behaviour' => 'Files a report on suspicion without waiting for proof.',
                     'attitude'  => 'Treats the obligation as owed to the system, not to the employer.',
                 ]],
                ['code' => 'FS_REGULATORY_REPORTING', 'name' => 'Regulatory reporting', 'category' => 'Compliance',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Producing returns that are accurate, reconciled and on time.',
                 'kasba' => [
                     'knowledge' => 'Applicable reporting standards, submission calendar and definitions.',
                     'ability'   => 'Traces a reported figure back to source and explains a variance.',
                     'skill'     => 'Reconciles across systems and resolves breaks before submission.',
                     'behaviour' => 'Raises a restatement immediately rather than at the next cycle.',
                     'attitude'  => 'Treats a late or wrong return as a failure regardless of cause.',
                 ]],
                ['code' => 'FS_FRAUD_DETECTION', 'name' => 'Fraud detection and response', 'category' => 'Risk',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Spotting and containing fraudulent activity across channels.',
                 'kasba' => [
                     'knowledge' => 'Current fraud typologies, channel-specific vulnerabilities and case law.',
                     'ability'   => 'Separates a false positive from a genuine attack quickly.',
                     'skill'     => 'Runs containment without destroying evidence.',
                     'behaviour' => 'Escalates on pattern, not only on confirmed loss.',
                     'attitude'  => 'Shares typologies across teams rather than holding them.',
                 ]],
                ['code' => 'FS_CUSTOMER_ADVISORY', 'name' => 'Customer advisory', 'category' => 'Customer',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Recommending products that suit the customer rather than the target.',
                 'kasba' => [
                     'knowledge' => 'Product terms, suitability rules and the customer\'s own circumstances.',
                     'ability'   => 'Establishes real need through questioning rather than assumption.',
                     'skill'     => 'Explains cost, risk and alternative in plain language.',
                     'behaviour' => 'Records the suitability rationale on file.',
                     'attitude'  => 'Declines a sale that would not benefit the customer.',
                 ]],
                ['code' => 'FS_OPERATIONAL_RESILIENCE', 'name' => 'Operational resilience', 'category' => 'Operations',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Keeping critical services running through disruption.',
                 'kasba' => [
                     'knowledge' => 'Important business services, impact tolerances and dependency maps.',
                     'ability'   => 'Identifies the single points of failure behind a service.',
                     'skill'     => 'Executes a tested continuity plan under live conditions.',
                     'behaviour' => 'Tests rather than assumes; records what the test actually showed.',
                     'attitude'  => 'Treats an untested plan as no plan.',
                 ]],
                ['code' => 'FS_DATA_PROTECTION', 'name' => 'Data protection', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Handling customer data lawfully and proportionately.',
                 'kasba' => [
                     'knowledge' => 'Lawful bases, retention limits, subject rights and breach thresholds.',
                     'ability'   => 'Recognises when a routine request is actually a subject-rights request.',
                     'skill'     => 'Applies minimisation in day-to-day work, not only in projects.',
                     'behaviour' => 'Reports a possible breach within the statutory window.',
                     'attitude'  => 'Treats access as something to justify rather than to enjoy.',
                 ]],
                ['code' => 'FS_PORTFOLIO_MONITORING', 'name' => 'Portfolio monitoring', 'category' => 'Risk',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Detecting deterioration in a book before it becomes loss.',
                 'kasba' => [
                     'knowledge' => 'Early-warning indicators, covenant structures and sector cycles.',
                     'ability'   => 'Reads a trend across accounts rather than one account at a time.',
                     'skill'     => 'Builds and maintains a watchlist that is acted on.',
                     'behaviour' => 'Downgrades promptly rather than at the next formal review.',
                     'attitude'  => 'Reports deterioration in own portfolio without prompting.',
                 ]],
            ],
        ],

        /* ───────────────────────────── TECHNOLOGY ───────────────────────────── */
        'technology' => [
            'label'       => 'Technology',
            'terminology' => ['OrganizationUnit' => 'Team', 'Person' => 'Team member', 'Position' => 'Role'],
            'capabilities' => [
                ['code' => 'TC_SOFTWARE_DESIGN', 'name' => 'Software design', 'category' => 'Engineering',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Choosing a structure that survives the change it will actually receive.',
                 'kasba' => [
                     'knowledge' => 'Design patterns, the existing architecture and its constraints.',
                     'ability'   => 'Anticipates which parts of a system will change and isolates them.',
                     'skill'     => 'Produces designs others can implement without re-deriving them.',
                     'behaviour' => 'Writes down the trade-off and what was rejected.',
                     'attitude'  => 'Prefers the boring solution when it is sufficient.',
                 ]],
                ['code' => 'TC_CODE_QUALITY', 'name' => 'Code quality and review', 'category' => 'Engineering',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Writing and reviewing code so defects are found before release.',
                 'kasba' => [
                     'knowledge' => 'Language idioms, the codebase conventions and common defect classes.',
                     'ability'   => 'Spots the failure mode a change introduces, not only its style.',
                     'skill'     => 'Reviews at a useful depth within a reasonable time.',
                     'behaviour' => 'Gives specific, actionable feedback and accepts it in return.',
                     'attitude'  => 'Treats a review comment as help rather than as criticism.',
                 ]],
                ['code' => 'TC_TESTING', 'name' => 'Testing and verification', 'category' => 'Engineering',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Establishing that something works, and that it will keep working.',
                 'kasba' => [
                     'knowledge' => 'Test levels, coverage limits and what the test infrastructure cannot see.',
                     'ability'   => 'Designs a test that would actually fail if the behaviour were wrong.',
                     'skill'     => 'Writes tests that are fast, deterministic and readable.',
                     'behaviour' => 'Adds a regression test with every fix.',
                     'attitude'  => 'Treats a passing suite as evidence, not as proof.',
                 ]],
                ['code' => 'TC_SECURITY_ENGINEERING', 'name' => 'Security engineering', 'category' => 'Security',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Building systems that resist misuse rather than patching them afterwards.',
                 'kasba' => [
                     'knowledge' => 'Common vulnerability classes, the threat model and the trust boundaries.',
                     'ability'   => 'Reasons about what an attacker controls in a given path.',
                     'skill'     => 'Applies authentication, authorisation and validation correctly.',
                     'behaviour' => 'Raises a security concern even when it delays a release.',
                     'attitude'  => 'Treats a near-miss as worth reporting.',
                 ]],
                ['code' => 'TC_INCIDENT_RESPONSE', 'name' => 'Incident response', 'category' => 'Operations',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Restoring service quickly and learning from the failure.',
                 'kasba' => [
                     'knowledge' => 'System topology, dependencies, runbooks and escalation paths.',
                     'ability'   => 'Forms and discards hypotheses quickly under pressure.',
                     'skill'     => 'Diagnoses from telemetry rather than from intuition.',
                     'behaviour' => 'Communicates status on a predictable cadence during an incident.',
                     'attitude'  => 'Writes a blameless post-incident review that names the real cause.',
                 ]],
                ['code' => 'TC_DATA_ENGINEERING', 'name' => 'Data engineering', 'category' => 'Data',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Moving and modelling data so downstream users can trust it.',
                 'kasba' => [
                     'knowledge' => 'Storage models, consistency guarantees and the cost of each.',
                     'ability'   => 'Designs a schema that answers the questions actually asked of it.',
                     'skill'     => 'Builds pipelines that fail loudly rather than silently.',
                     'behaviour' => 'Documents lineage and known quality limits.',
                     'attitude'  => 'Reports a data quality problem rather than working around it.',
                 ]],
                ['code' => 'TC_REQUIREMENTS', 'name' => 'Requirements and product framing', 'category' => 'Delivery',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Establishing what the work is for before deciding how to build it.',
                 'kasba' => [
                     'knowledge' => 'The domain, the users and the constraints that are genuinely fixed.',
                     'ability'   => 'Separates a stated request from the underlying need.',
                     'skill'     => 'Writes acceptance criteria that are testable.',
                     'behaviour' => 'Confirms understanding with the requester before building.',
                     'attitude'  => 'Willing to argue that the requested thing is the wrong thing.',
                 ]],
                ['code' => 'TC_TECHNICAL_COMMUNICATION', 'name' => 'Technical communication', 'category' => 'Delivery',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Making technical reality legible to people who must act on it.',
                 'kasba' => [
                     'knowledge' => 'What each audience already knows and needs.',
                     'ability'   => 'Compresses detail without distorting the conclusion.',
                     'skill'     => 'Writes documents and updates that get read and used.',
                     'behaviour' => 'States uncertainty and risk explicitly rather than burying it.',
                     'attitude'  => 'Treats being understood as part of the job.',
                 ]],
            ],
        ],

        /* ───────────────────────────── TELECOM ───────────────────────────── */
        'telecom' => [
            'label'       => 'Telecommunications',
            'terminology' => ['OrganizationUnit' => 'Business Unit', 'Person' => 'Employee', 'Position' => 'Role'],
            'capabilities' => [
                ['code' => 'TE_NETWORK_OPERATIONS', 'name' => 'Network operations', 'category' => 'Network',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Keeping the network available and within its performance envelope.',
                 'kasba' => [
                     'knowledge' => 'Topology, protocols, capacity limits and the current change calendar.',
                     'ability'   => 'Correlates alarms into a single underlying fault.',
                     'skill'     => 'Operates the management systems accurately under load.',
                     'behaviour' => 'Follows change control even when a fix looks trivial.',
                     'attitude'  => 'Treats an unexplained recovery as an unresolved fault.',
                 ]],
                ['code' => 'TE_FIELD_INSTALLATION', 'name' => 'Field installation and maintenance', 'category' => 'Field',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Installing and repairing plant to standard, first time.',
                 'kasba' => [
                     'knowledge' => 'Build standards, equipment specifications and site constraints.',
                     'ability'   => 'Adapts a standard build to a non-standard site without compromising it.',
                     'skill'     => 'Terminations, splicing and testing to specification.',
                     'behaviour' => 'Records as-built accurately, including deviations.',
                     'attitude'  => 'Returns to correct own work rather than leaving it for the next visit.',
                 ]],
                ['code' => 'TE_SERVICE_ASSURANCE', 'name' => 'Service assurance', 'category' => 'Customer',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Meeting the service levels actually sold to the customer.',
                 'kasba' => [
                     'knowledge' => 'SLA terms, measurement methods and penalty structures.',
                     'ability'   => 'Distinguishes a customer-affecting fault from a network-visible one.',
                     'skill'     => 'Manages a fault to closure across multiple teams.',
                     'behaviour' => 'Keeps the customer informed at agreed intervals.',
                     'attitude'  => 'Reports an SLA breach rather than reclassifying it.',
                 ]],
                ['code' => 'TE_SPECTRUM_COMPLIANCE', 'name' => 'Regulatory and spectrum compliance', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Operating within licence conditions and regulatory obligation.',
                 'kasba' => [
                     'knowledge' => 'Licence terms, emission limits, interference rules and reporting duties.',
                     'ability'   => 'Recognises when a planned change touches a licence condition.',
                     'skill'     => 'Produces compliant records and submissions.',
                     'behaviour' => 'Halts work that would breach a condition.',
                     'attitude'  => 'Treats the licence as the operating boundary, not as advice.',
                 ]],
                ['code' => 'TE_CAPACITY_PLANNING', 'name' => 'Capacity planning', 'category' => 'Network',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Provisioning ahead of demand without stranding capital.',
                 'kasba' => [
                     'knowledge' => 'Traffic patterns, growth drivers and lead times on equipment.',
                     'ability'   => 'Forecasts from real usage rather than from sales projection.',
                     'skill'     => 'Builds a plan that reconciles engineering need with budget.',
                     'behaviour' => 'Revisits the forecast against outturn rather than filing it.',
                     'attitude'  => 'States forecast uncertainty rather than presenting a single number.',
                 ]],
                ['code' => 'TE_CUSTOMER_SUPPORT', 'name' => 'Customer support', 'category' => 'Customer',
                 'type' => 'behavioural', 'difficulty' => 'basic', 'criticality' => 'medium',
                 'description' => 'Resolving customer problems at first contact where possible.',
                 'kasba' => [
                     'knowledge' => 'Product set, common faults and the diagnostic tools available.',
                     'ability'   => 'Establishes the real problem from a non-technical description.',
                     'skill'     => 'Diagnoses and resolves within the contact where the tools allow.',
                     'behaviour' => 'Hands over with full context rather than transferring blind.',
                     'attitude'  => 'Owns the outcome rather than the ticket.',
                 ]],
                ['code' => 'TE_SAFETY_WORKING', 'name' => 'Safe working practice', 'category' => 'Safety',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Working at height, on the roadside and near power without harm.',
                 'kasba' => [
                     'knowledge' => 'Risk assessments, permit systems and equipment inspection requirements.',
                     'ability'   => 'Recognises when conditions have changed enough to stop.',
                     'skill'     => 'Uses access equipment and PPE correctly every time.',
                     'behaviour' => 'Stops unsafe work including a colleague\'s.',
                     'attitude'  => 'Reports own near-misses.',
                 ]],
                ['code' => 'TE_VENDOR_MANAGEMENT', 'name' => 'Vendor and contractor management', 'category' => 'Operations',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Getting the contracted standard out of third parties.',
                 'kasba' => [
                     'knowledge' => 'Contract terms, acceptance criteria and escalation routes.',
                     'ability'   => 'Judges whether delivered work meets specification.',
                     'skill'     => 'Runs acceptance and manages remediation to closure.',
                     'behaviour' => 'Documents non-conformance at the time.',
                     'attitude'  => 'Holds the standard rather than accepting to keep the schedule.',
                 ]],
            ],
        ],

        /* ───────────────────────────── MANUFACTURING ───────────────────────────── */
        'manufacturing' => [
            'label'       => 'Manufacturing',
            'terminology' => ['OrganizationUnit' => 'Plant', 'Person' => 'Employee', 'Position' => 'Role'],
            'capabilities' => [
                ['code' => 'MF_MACHINE_OPERATION', 'name' => 'Machine operation', 'category' => 'Production',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Running equipment to specification, rate and quality.',
                 'kasba' => [
                     'knowledge' => 'Machine capability, tolerances, materials and setup parameters.',
                     'ability'   => 'Recognises drift before it becomes scrap.',
                     'skill'     => 'Sets up and runs within cycle time and tolerance.',
                     'behaviour' => 'Stops the line on a quality doubt.',
                     'attitude'  => 'Reports own scrap accurately.',
                 ]],
                ['code' => 'MF_QUALITY_CONTROL', 'name' => 'Quality control', 'category' => 'Quality',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Detecting non-conformance before it reaches the customer.',
                 'kasba' => [
                     'knowledge' => 'Specifications, sampling plans and measurement system limits.',
                     'ability'   => 'Distinguishes special cause from common cause variation.',
                     'skill'     => 'Uses gauges and measurement equipment correctly and repeatably.',
                     'behaviour' => 'Quarantines suspect stock immediately.',
                     'attitude'  => 'Treats a customer complaint as a process failure to find.',
                 ]],
                ['code' => 'MF_SAFETY', 'name' => 'Health and safety', 'category' => 'Safety',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Working without injury to self or others.',
                 'kasba' => [
                     'knowledge' => 'Hazards, isolation procedures, permits and emergency arrangements.',
                     'ability'   => 'Assesses a non-routine task before starting it.',
                     'skill'     => 'Applies lock-out and guarding correctly every time.',
                     'behaviour' => 'Challenges unsafe practice regardless of seniority.',
                     'attitude'  => 'Reports near-misses without prompting.',
                 ]],
                ['code' => 'MF_MAINTENANCE', 'name' => 'Preventive maintenance', 'category' => 'Engineering',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Keeping equipment available through planned rather than reactive work.',
                 'kasba' => [
                     'knowledge' => 'Failure modes, maintenance schedules and spares availability.',
                     'ability'   => 'Diagnoses root cause rather than replacing until it works.',
                     'skill'     => 'Executes planned work within the window.',
                     'behaviour' => 'Records what was actually found, not what was expected.',
                     'attitude'  => 'Treats repeat failure as unfinished work.',
                 ]],
                ['code' => 'MF_LEAN_IMPROVEMENT', 'name' => 'Continuous improvement', 'category' => 'Operations',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Removing waste from a process and making the gain stick.',
                 'kasba' => [
                     'knowledge' => 'Improvement methods, the process baseline and its measurement.',
                     'ability'   => 'Identifies the constraint rather than the most visible problem.',
                     'skill'     => 'Runs a structured improvement to a measured result.',
                     'behaviour' => 'Standardises the change so it survives the shift that made it.',
                     'attitude'  => 'Reports an improvement that did not work.',
                 ]],
                ['code' => 'MF_SUPPLY_CHAIN', 'name' => 'Supply chain and inventory', 'category' => 'Operations',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Having the right material available without carrying excess.',
                 'kasba' => [
                     'knowledge' => 'Lead times, supplier reliability and demand variability.',
                     'ability'   => 'Anticipates a shortage from a signal rather than a stockout.',
                     'skill'     => 'Maintains accurate inventory records and reorder points.',
                     'behaviour' => 'Escalates a supply risk before it stops the line.',
                     'attitude'  => 'Reports true stock rather than the expected figure.',
                 ]],
                ['code' => 'MF_ENVIRONMENTAL', 'name' => 'Environmental compliance', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Operating within permit limits for emissions, discharge and waste.',
                 'kasba' => [
                     'knowledge' => 'Permit conditions, monitoring duties and waste classification.',
                     'ability'   => 'Recognises when a process change affects a permit.',
                     'skill'     => 'Maintains monitoring records to auditable standard.',
                     'behaviour' => 'Reports an exceedance immediately.',
                     'attitude'  => 'Treats the permit limit as the limit, not as a target.',
                 ]],
                ['code' => 'MF_SHIFT_HANDOVER', 'name' => 'Shift handover', 'category' => 'Production',
                 'type' => 'behavioural', 'difficulty' => 'basic', 'criticality' => 'medium',
                 'description' => 'Transferring state so the incoming shift starts informed.',
                 'kasba' => [
                     'knowledge' => 'What the next shift needs to know to run safely and to rate.',
                     'ability'   => 'Judges which of the shift\'s events still matter.',
                     'skill'     => 'Produces a handover that is complete and quick to read.',
                     'behaviour' => 'Hands over in person on anything unresolved.',
                     'attitude'  => 'Passes on own mistakes as readily as others\'.',
                 ]],
            ],
        ],

        /* ───────────────────────────── RETAIL ───────────────────────────── */
        'retail' => [
            'label'       => 'Retail',
            'terminology' => ['OrganizationUnit' => 'Store', 'Person' => 'Colleague', 'Position' => 'Role'],
            'capabilities' => [
                ['code' => 'RT_CUSTOMER_SERVICE', 'name' => 'Customer service', 'category' => 'Customer',
                 'type' => 'behavioural', 'difficulty' => 'basic', 'criticality' => 'high',
                 'description' => 'Helping a customer leave satisfied, including when the answer is no.',
                 'kasba' => [
                     'knowledge' => 'Range, policies and what can actually be offered.',
                     'ability'   => 'Establishes what the customer needs rather than what they asked for.',
                     'skill'     => 'Resolves a complaint within policy and at the counter.',
                     'behaviour' => 'Stays with the customer until it is resolved or handed over.',
                     'attitude'  => 'Treats a complaint as information.',
                 ]],
                ['code' => 'RT_MERCHANDISING', 'name' => 'Merchandising', 'category' => 'Trading',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Presenting range and space so it sells.',
                 'kasba' => [
                     'knowledge' => 'Planograms, category performance and seasonal patterns.',
                     'ability'   => 'Recognises when a layout is not working from sales data.',
                     'skill'     => 'Executes a layout change accurately and quickly.',
                     'behaviour' => 'Feeds back what the plan does not fit in this store.',
                     'attitude'  => 'Measures rather than assumes.',
                 ]],
                ['code' => 'RT_STOCK_ACCURACY', 'name' => 'Stock accuracy', 'category' => 'Operations',
                 'type' => 'skill', 'difficulty' => 'basic', 'criticality' => 'high',
                 'description' => 'Keeping the recorded position equal to the real one.',
                 'kasba' => [
                     'knowledge' => 'Stock processes, shrink causes and count disciplines.',
                     'ability'   => 'Traces a discrepancy to its cause.',
                     'skill'     => 'Counts and adjusts accurately.',
                     'behaviour' => 'Records a loss rather than absorbing it.',
                     'attitude'  => 'Treats an inaccuracy as a problem to solve, not to hide.',
                 ]],
                ['code' => 'RT_LOSS_PREVENTION', 'name' => 'Loss prevention', 'category' => 'Operations',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Reducing shrink from theft, error and process failure.',
                 'kasba' => [
                     'knowledge' => 'Shrink drivers, procedure and the limits of lawful intervention.',
                     'ability'   => 'Distinguishes error-driven loss from theft.',
                     'skill'     => 'Applies controls without damaging customer experience.',
                     'behaviour' => 'Reports incidents fully and on time.',
                     'attitude'  => 'Applies the same standard to colleagues and to customers.',
                 ]],
                ['code' => 'RT_CASH_HANDLING', 'name' => 'Cash and payment handling', 'category' => 'Operations',
                 'type' => 'skill', 'difficulty' => 'basic', 'criticality' => 'high',
                 'description' => 'Handling money and payment data without loss or breach.',
                 'kasba' => [
                     'knowledge' => 'Cash procedure, payment security rules and fraud indicators.',
                     'ability'   => 'Recognises a suspicious transaction.',
                     'skill'     => 'Reconciles accurately at every handover.',
                     'behaviour' => 'Declares a discrepancy immediately.',
                     'attitude'  => 'Follows the procedure when unobserved.',
                 ]],
                ['code' => 'RT_TEAM_LEADERSHIP', 'name' => 'Shop-floor leadership', 'category' => 'People',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Running a shift so the team knows what good looks like.',
                 'kasba' => [
                     'knowledge' => 'The day\'s priorities, the team\'s capability and the policies that bind it.',
                     'ability'   => 'Reallocates people as the day changes.',
                     'skill'     => 'Briefs, delegates and follows up.',
                     'behaviour' => 'Gives feedback in the moment rather than at review.',
                     'attitude'  => 'Takes responsibility for the shift\'s result.',
                 ]],
                ['code' => 'RT_FOOD_SAFETY', 'name' => 'Product and food safety', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Ensuring what is sold is safe to use or eat.',
                 'kasba' => [
                     'knowledge' => 'Handling requirements, temperature control, allergens and recall procedure.',
                     'ability'   => 'Recognises a product that must be withdrawn.',
                     'skill'     => 'Maintains checks and records to audit standard.',
                     'behaviour' => 'Withdraws stock on doubt rather than on confirmation.',
                     'attitude'  => 'Treats a missed check as a real failure.',
                 ]],
                ['code' => 'RT_DIGITAL_FULFILMENT', 'name' => 'Digital and omnichannel fulfilment', 'category' => 'Trading',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Delivering online promises from a physical estate.',
                 'kasba' => [
                     'knowledge' => 'Fulfilment models, cut-offs and the systems involved.',
                     'ability'   => 'Resolves a conflict between store demand and online demand.',
                     'skill'     => 'Picks, packs and dispatches accurately within the window.',
                     'behaviour' => 'Communicates a shortfall before the customer discovers it.',
                     'attitude'  => 'Treats an online order as a real customer standing in the store.',
                 ]],
            ],
        ],

        /* ───────────────────────────── GOVERNMENT ───────────────────────────── */
        'government' => [
            'label'       => 'Government and Public Sector',
            'terminology' => ['OrganizationUnit' => 'Directorate', 'Person' => 'Officer', 'Position' => 'Post'],
            'capabilities' => [
                ['code' => 'GV_CASEWORK', 'name' => 'Casework and determination', 'category' => 'Service delivery',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Deciding individual cases correctly, consistently and in time.',
                 'kasba' => [
                     'knowledge' => 'The governing legislation, guidance and precedent.',
                     'ability'   => 'Applies a rule to facts that do not fit it neatly.',
                     'skill'     => 'Produces a decision and reasons that withstand appeal.',
                     'behaviour' => 'Seeks advice on a marginal case rather than guessing.',
                     'attitude'  => 'Applies the same standard regardless of applicant.',
                 ]],
                ['code' => 'GV_PROCUREMENT', 'name' => 'Public procurement', 'category' => 'Commercial',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Buying lawfully and demonstrably in the public interest.',
                 'kasba' => [
                     'knowledge' => 'Procurement regulations, thresholds and challenge grounds.',
                     'ability'   => 'Specifies a requirement without designing in a supplier.',
                     'skill'     => 'Runs an evaluation that is defensible on the record.',
                     'behaviour' => 'Declares interests promptly and fully.',
                     'attitude'  => 'Treats the audit trail as part of the decision, not a by-product.',
                 ]],
                ['code' => 'GV_INFORMATION_GOVERNANCE', 'name' => 'Information governance', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Handling public and personal information lawfully.',
                 'kasba' => [
                     'knowledge' => 'Access regimes, exemptions, retention and breach duties.',
                     'ability'   => 'Recognises a request that engages a statutory right.',
                     'skill'     => 'Applies redaction and disclosure correctly.',
                     'behaviour' => 'Reports a possible breach within the statutory window.',
                     'attitude'  => 'Defaults to openness where the law allows it.',
                 ]],
                ['code' => 'GV_POLICY_ANALYSIS', 'name' => 'Policy analysis', 'category' => 'Policy',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Producing advice that is evidenced and states its own uncertainty.',
                 'kasba' => [
                     'knowledge' => 'The evidence base, the delivery landscape and the fiscal constraint.',
                     'ability'   => 'Separates what the evidence shows from what is preferred.',
                     'skill'     => 'Writes advice that a decision-maker can act on.',
                     'behaviour' => 'Presents the option that is unwelcome as well as the one that is not.',
                     'attitude'  => 'States the limits of the evidence.',
                 ]],
                ['code' => 'GV_CITIZEN_SERVICE', 'name' => 'Citizen service', 'category' => 'Service delivery',
                 'type' => 'behavioural', 'difficulty' => 'basic', 'criticality' => 'high',
                 'description' => 'Serving people who often have no alternative provider.',
                 'kasba' => [
                     'knowledge' => 'Entitlements, processes and the routes to specialist help.',
                     'ability'   => 'Recognises vulnerability and adjusts accordingly.',
                     'skill'     => 'Explains a decision or process in plain language.',
                     'behaviour' => 'Signposts accurately rather than deflecting.',
                     'attitude'  => 'Treats access to the service as a right.',
                 ]],
                ['code' => 'GV_SAFEGUARDING', 'name' => 'Safeguarding', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Recognising and escalating risk to vulnerable people.',
                 'kasba' => [
                     'knowledge' => 'Statutory duties, thresholds and referral routes.',
                     'ability'   => 'Distinguishes a concern to refer from one to monitor.',
                     'skill'     => 'Records a concern in terms that survive scrutiny.',
                     'behaviour' => 'Follows up rather than assuming the referral was actioned.',
                     'attitude'  => 'Acts on suspicion without needing certainty.',
                 ]],
                ['code' => 'GV_PROGRAMME_DELIVERY', 'name' => 'Programme delivery', 'category' => 'Delivery',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Delivering funded commitments to time and benefit.',
                 'kasba' => [
                     'knowledge' => 'Governance frameworks, benefit definitions and assurance gates.',
                     'ability'   => 'Recognises when a plan has become undeliverable.',
                     'skill'     => 'Manages dependency, risk and reporting.',
                     'behaviour' => 'Reports a red status when it is red.',
                     'attitude'  => 'Treats optimism bias as something to correct for.',
                 ]],
                ['code' => 'GV_EQUALITY_IMPACT', 'name' => 'Equality and impact assessment', 'category' => 'Policy',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Understanding who a decision affects, and how differently.',
                 'kasba' => [
                     'knowledge' => 'The statutory duty, protected characteristics and available data.',
                     'ability'   => 'Identifies differential impact that is not obvious.',
                     'skill'     => 'Produces an assessment that changes the proposal where it should.',
                     'behaviour' => 'Completes it before the decision rather than after.',
                     'attitude'  => 'Treats it as analysis rather than as a form.',
                 ]],
            ],
        ],

        /* ───────────────────────────── CORPORATE ───────────────────────────── */
        'corporate' => [
            'label'       => 'Corporate and Professional Services',
            'terminology' => [],
            'capabilities' => [
                ['code' => 'CO_CLIENT_DELIVERY', 'name' => 'Client delivery', 'category' => 'Delivery',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Delivering the engagement that was actually sold.',
                 'kasba' => [
                     'knowledge' => 'Scope, commercial terms and the client\'s decision structure.',
                     'ability'   => 'Recognises scope drift early enough to act on it.',
                     'skill'     => 'Produces work to standard within budget and time.',
                     'behaviour' => 'Raises a delivery risk with the client rather than absorbing it.',
                     'attitude'  => 'Treats the client\'s outcome as the measure of success.',
                 ]],
                ['code' => 'CO_COMMERCIAL_ACUMEN', 'name' => 'Commercial acumen', 'category' => 'Commercial',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Understanding how the business makes and loses money.',
                 'kasba' => [
                     'knowledge' => 'Margin structure, cost drivers and the competitive position.',
                     'ability'   => 'Judges whether a proposal is worth doing commercially.',
                     'skill'     => 'Builds a case that survives finance review.',
                     'behaviour' => 'Argues against unprofitable work.',
                     'attitude'  => 'Treats revenue and margin as different things.',
                 ]],
                ['code' => 'CO_PEOPLE_LEADERSHIP', 'name' => 'People leadership', 'category' => 'People',
                 'type' => 'behavioural', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Getting results through a team and developing it while doing so.',
                 'kasba' => [
                     'knowledge' => 'The team\'s capability, motivation and the policies that apply.',
                     'ability'   => 'Matches work to development need as well as to competence.',
                     'skill'     => 'Sets expectations, delegates and holds to account.',
                     'behaviour' => 'Gives difficult feedback early and directly.',
                     'attitude'  => 'Treats a team member\'s growth as part of the job.',
                 ]],
                ['code' => 'CO_STAKEHOLDER_MANAGEMENT', 'name' => 'Stakeholder management', 'category' => 'Delivery',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Keeping the people who can stop the work informed and aligned.',
                 'kasba' => [
                     'knowledge' => 'Who decides, who influences and what each of them needs.',
                     'ability'   => 'Anticipates objection before it becomes blockage.',
                     'skill'     => 'Communicates at the right level and cadence.',
                     'behaviour' => 'Delivers bad news early.',
                     'attitude'  => 'Engages with disagreement rather than routing around it.',
                 ]],
                ['code' => 'CO_DATA_LITERACY', 'name' => 'Data literacy', 'category' => 'Analysis',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Reading, questioning and using data without being misled by it.',
                 'kasba' => [
                     'knowledge' => 'What the organization measures, how, and where it is unreliable.',
                     'ability'   => 'Distinguishes correlation from cause and sample from population.',
                     'skill'     => 'Builds an analysis that answers the question asked.',
                     'behaviour' => 'States the caveat alongside the number.',
                     'attitude'  => 'Changes position when the data says so.',
                 ]],
                ['code' => 'CO_RISK_COMPLIANCE', 'name' => 'Risk and compliance', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Working within the obligations the organization carries.',
                 'kasba' => [
                     'knowledge' => 'Applicable regulation, internal policy and the risk appetite.',
                     'ability'   => 'Recognises when routine work engages an obligation.',
                     'skill'     => 'Applies controls without stopping the work.',
                     'behaviour' => 'Escalates a breach rather than remediating quietly.',
                     'attitude'  => 'Treats policy as the floor, not the ceiling.',
                 ]],
                ['code' => 'CO_PROJECT_MANAGEMENT', 'name' => 'Project management', 'category' => 'Delivery',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Bringing work to a defined end within a defined envelope.',
                 'kasba' => [
                     'knowledge' => 'Method, governance and the real constraints.',
                     'ability'   => 'Sequences work so dependency does not stall it.',
                     'skill'     => 'Plans, tracks and re-plans with evidence.',
                     'behaviour' => 'Reports status accurately including when it is poor.',
                     'attitude'  => 'Treats a slipped date as information rather than failure.',
                 ]],
                ['code' => 'CO_WRITTEN_COMMUNICATION', 'name' => 'Written communication', 'category' => 'Delivery',
                 'type' => 'skill', 'difficulty' => 'basic', 'criticality' => 'medium',
                 'description' => 'Writing so the reader can act without asking for clarification.',
                 'kasba' => [
                     'knowledge' => 'Audience, purpose and the conventions of the document type.',
                     'ability'   => 'Leads with the conclusion and supports it.',
                     'skill'     => 'Writes clearly and concisely to deadline.',
                     'behaviour' => 'Edits before sending.',
                     'attitude'  => 'Treats clarity as respect for the reader\'s time.',
                 ]],
            ],
        ],

        /* ───────────────────────────── NGO ───────────────────────────── */
        'ngo' => [
            'label'       => 'Non-Governmental and Non-Profit',
            'terminology' => ['OrganizationUnit' => 'Programme', 'Person' => 'Team member', 'Position' => 'Role'],
            'capabilities' => [
                ['code' => 'NG_PROGRAMME_DESIGN', 'name' => 'Programme design', 'category' => 'Programmes',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Designing an intervention with a defensible theory of change.',
                 'kasba' => [
                     'knowledge' => 'The context, the evidence base and what has failed here before.',
                     'ability'   => 'Distinguishes an activity from an outcome.',
                     'skill'     => 'Produces a design with measurable, attributable results.',
                     'behaviour' => 'Consults intended beneficiaries during design, not after.',
                     'attitude'  => 'Willing to conclude the intervention is not needed.',
                 ]],
                ['code' => 'NG_SAFEGUARDING', 'name' => 'Safeguarding', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Protecting the people the organization exists to serve.',
                 'kasba' => [
                     'knowledge' => 'Policy, reporting routes and the obligations of the operating context.',
                     'ability'   => 'Recognises a concern in an unfamiliar cultural setting.',
                     'skill'     => 'Records and reports without compromising the person at risk.',
                     'behaviour' => 'Reports regardless of who is implicated.',
                     'attitude'  => 'Places beneficiary safety above organizational reputation.',
                 ]],
                ['code' => 'NG_MEAL', 'name' => 'Monitoring, evaluation and learning', 'category' => 'Programmes',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Establishing whether the programme actually worked.',
                 'kasba' => [
                     'knowledge' => 'Indicator design, sampling and attribution methods.',
                     'ability'   => 'Recognises when a result cannot be attributed to the programme.',
                     'skill'     => 'Collects and analyses data of usable quality in the field.',
                     'behaviour' => 'Publishes negative findings.',
                     'attitude'  => 'Treats a failed programme as the most valuable data available.',
                 ]],
                ['code' => 'NG_DONOR_COMPLIANCE', 'name' => 'Donor compliance and reporting', 'category' => 'Compliance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Meeting the conditions attached to restricted funding.',
                 'kasba' => [
                     'knowledge' => 'Grant terms, eligible cost rules and reporting calendars.',
                     'ability'   => 'Recognises when spend has drifted outside eligibility.',
                     'skill'     => 'Produces accurate narrative and financial reports on time.',
                     'behaviour' => 'Discloses a variance to the donor promptly.',
                     'attitude'  => 'Treats restricted funds as held in trust.',
                 ]],
                ['code' => 'NG_FIELD_SAFETY', 'name' => 'Field safety and security', 'category' => 'Safety',
                 'type' => 'behavioural', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Operating in insecure environments without avoidable harm.',
                 'kasba' => [
                     'knowledge' => 'Context analysis, security protocols and evacuation triggers.',
                     'ability'   => 'Reassesses risk as conditions change.',
                     'skill'     => 'Follows movement, communication and incident procedures.',
                     'behaviour' => 'Stands down activity when the threshold is met.',
                     'attitude'  => 'Reports incidents even when nothing happened.',
                 ]],
                ['code' => 'NG_PARTNERSHIP', 'name' => 'Partnership management', 'category' => 'Programmes',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Working through local partners without undermining them.',
                 'kasba' => [
                     'knowledge' => 'Partner capacity, agreements and the local operating environment.',
                     'ability'   => 'Judges what to support versus what to take over.',
                     'skill'     => 'Manages an agreement to delivery and to capacity growth.',
                     'behaviour' => 'Raises a performance concern directly and early.',
                     'attitude'  => 'Treats the partner as the lead, not the subcontractor.',
                 ]],
                ['code' => 'NG_FUNDRAISING', 'name' => 'Fundraising', 'category' => 'Income',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Raising unrestricted and restricted income sustainably.',
                 'kasba' => [
                     'knowledge' => 'Funder landscape, application requirements and the case for support.',
                     'ability'   => 'Matches a programme to a funder without distorting it.',
                     'skill'     => 'Writes proposals that are funded.',
                     'behaviour' => 'Declines funding that would distort the mission.',
                     'attitude'  => 'Represents impact honestly in fundraising material.',
                 ]],
                ['code' => 'NG_VOLUNTEER_MANAGEMENT', 'name' => 'Volunteer management', 'category' => 'People',
                 'type' => 'behavioural', 'difficulty' => 'basic', 'criticality' => 'medium',
                 'description' => 'Getting sustainable contribution from unpaid people.',
                 'kasba' => [
                     'knowledge' => 'What volunteers may and may not do, and what motivates them.',
                     'ability'   => 'Matches role to motivation and availability.',
                     'skill'     => 'Recruits, inducts and supports to retention.',
                     'behaviour' => 'Recognises contribution specifically rather than generically.',
                     'attitude'  => 'Treats volunteer time as a real cost to the volunteer.',
                 ]],
            ],
        ],

        /* ───────────────────────────── K-12 EDUCATION ───────────────────────────── */
        'k12_education' => [
            'label'       => 'School Education',
            'terminology' => ['OrganizationUnit' => 'Department', 'Person' => 'Staff member', 'Position' => 'Post'],
            'capabilities' => [
                ['code' => 'ED_LESSON_PLANNING', 'name' => 'Lesson planning', 'category' => 'Teaching',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Planning sequences that move a class from where it is to where it should be.',
                 'kasba' => [
                     'knowledge' => 'Curriculum, prior attainment and common misconceptions in the topic.',
                     'ability'   => 'Sequences content so each step is reachable from the last.',
                     'skill'     => 'Produces plans that work with the class actually in the room.',
                     'behaviour' => 'Adapts the plan mid-lesson on evidence.',
                     'attitude'  => 'Treats a plan that did not work as information.',
                 ]],
                ['code' => 'ED_ASSESSMENT_DESIGN', 'name' => 'Assessment design', 'category' => 'Teaching',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Designing assessment that shows what a learner can actually do.',
                 'kasba' => [
                     'knowledge' => 'Assessment principles, standards and the limits of each instrument.',
                     'ability'   => 'Distinguishes what an assessment measures from what it claims to.',
                     'skill'     => 'Writes tasks and rubrics that mark consistently across markers.',
                     'behaviour' => 'Moderates with colleagues rather than marking in isolation.',
                     'attitude'  => 'Treats a disappointing result as a question about teaching too.',
                 ]],
                ['code' => 'ED_CLASSROOM_MANAGEMENT', 'name' => 'Classroom management', 'category' => 'Teaching',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Establishing conditions in which learning is possible.',
                 'kasba' => [
                     'knowledge' => 'Behaviour policy, escalation routes and the individual needs in the room.',
                     'ability'   => 'De-escalates without losing the lesson.',
                     'skill'     => 'Applies routines consistently.',
                     'behaviour' => 'Follows through on stated consequences.',
                     'attitude'  => 'Separates the behaviour from the child.',
                 ]],
                ['code' => 'ED_SAFEGUARDING', 'name' => 'Safeguarding', 'category' => 'Governance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Recognising and acting on risk to a child.',
                 'kasba' => [
                     'knowledge' => 'Statutory duties, thresholds and the designated lead\'s role.',
                     'ability'   => 'Recognises a concern from indirect indicators.',
                     'skill'     => 'Records a disclosure accurately and without leading.',
                     'behaviour' => 'Reports the same day, every time.',
                     'attitude'  => 'Acts on suspicion without needing certainty.',
                 ]],
                ['code' => 'ED_INCLUSIVE_PRACTICE', 'name' => 'Inclusive practice', 'category' => 'Teaching',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Teaching so learners with additional needs actually access the curriculum.',
                 'kasba' => [
                     'knowledge' => 'Common needs, statutory entitlements and effective adjustments.',
                     'ability'   => 'Adapts a task without lowering what is expected.',
                     'skill'     => 'Deploys support staff effectively.',
                     'behaviour' => 'Reads and acts on individual plans.',
                     'attitude'  => 'Treats access as an entitlement rather than a favour.',
                 ]],
                ['code' => 'ED_PARENT_COMMUNICATION', 'name' => 'Parent and carer communication', 'category' => 'Community',
                 'type' => 'behavioural', 'difficulty' => 'basic', 'criticality' => 'medium',
                 'description' => 'Keeping families informed and engaged, including in difficulty.',
                 'kasba' => [
                     'knowledge' => 'What may be shared, with whom, and the school\'s communication policy.',
                     'ability'   => 'Judges when a concern warrants contact.',
                     'skill'     => 'Holds a difficult conversation without escalating it.',
                     'behaviour' => 'Contacts about progress as well as about problems.',
                     'attitude'  => 'Treats the family as a partner.',
                 ]],
                ['code' => 'ED_DATA_USE', 'name' => 'Use of attainment data', 'category' => 'Analysis',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Using assessment data to change teaching rather than to report it.',
                 'kasba' => [
                     'knowledge' => 'What each dataset measures and its known unreliability.',
                     'ability'   => 'Distinguishes a real gap from statistical noise in a small cohort.',
                     'skill'     => 'Turns an analysis into a specific instructional change.',
                     'behaviour' => 'Checks whether the change worked.',
                     'attitude'  => 'Resists reading too much into a single cohort.',
                 ]],
                ['code' => 'ED_PROFESSIONAL_DEVELOPMENT', 'name' => 'Professional development', 'category' => 'People',
                 'type' => 'behavioural', 'difficulty' => 'basic', 'criticality' => 'medium',
                 'description' => 'Improving practice deliberately rather than by accumulation of years.',
                 'kasba' => [
                     'knowledge' => 'Own development needs and the evidence on what improves teaching.',
                     'ability'   => 'Identifies a specific practice to change.',
                     'skill'     => 'Implements and sustains a change to practice.',
                     'behaviour' => 'Seeks observation and acts on the feedback.',
                     'attitude'  => 'Treats being observed as useful rather than as judgement.',
                 ]],
            ],
        ],

        /* ───────────────────────────── HIGHER EDUCATION ───────────────────────────── */
        'higher_education' => [
            'label'       => 'Higher Education',
            'terminology' => ['OrganizationUnit' => 'Faculty', 'Person' => 'Staff member', 'Position' => 'Post'],
            'capabilities' => [
                ['code' => 'HE_CURRICULUM_DESIGN', 'name' => 'Curriculum design', 'category' => 'Academic',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Designing programmes that are coherent, current and assessable.',
                 'kasba' => [
                     'knowledge' => 'Disciplinary developments, accreditation requirements and learning outcomes.',
                     'ability'   => 'Aligns outcomes, teaching and assessment genuinely rather than nominally.',
                     'skill'     => 'Produces validated programme documentation.',
                     'behaviour' => 'Consults students and external examiners during design.',
                     'attitude'  => 'Retires content that no longer earns its place.',
                 ]],
                ['code' => 'HE_TEACHING_PRACTICE', 'name' => 'Teaching practice', 'category' => 'Academic',
                 'type' => 'skill', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Teaching adults effectively at scale and in small groups.',
                 'kasba' => [
                     'knowledge' => 'Pedagogy for the discipline and the cohort\'s prior preparation.',
                     'ability'   => 'Adjusts to a cohort that is not where the module assumes.',
                     'skill'     => 'Delivers sessions that produce engagement and attainment.',
                     'behaviour' => 'Uses student feedback to change practice.',
                     'attitude'  => 'Treats teaching as scholarship.',
                 ]],
                ['code' => 'HE_RESEARCH_INTEGRITY', 'name' => 'Research integrity', 'category' => 'Research',
                 'type' => 'knowledge', 'difficulty' => 'advanced', 'criticality' => 'critical',
                 'description' => 'Conducting and reporting research honestly.',
                 'kasba' => [
                     'knowledge' => 'Ethics requirements, authorship conventions and data management duties.',
                     'ability'   => 'Recognises a conflict of interest that is not obvious.',
                     'skill'     => 'Maintains a record that supports reproduction.',
                     'behaviour' => 'Reports a null or contradictory result.',
                     'attitude'  => 'Treats correction of own published work as normal.',
                 ]],
                ['code' => 'HE_SUPERVISION', 'name' => 'Research supervision', 'category' => 'Research',
                 'type' => 'behavioural', 'difficulty' => 'advanced', 'criticality' => 'high',
                 'description' => 'Bringing research students to independent completion.',
                 'kasba' => [
                     'knowledge' => 'Regulations, progression requirements and the student\'s field.',
                     'ability'   => 'Judges when to direct and when to let the student struggle.',
                     'skill'     => 'Gives feedback that improves the work and the researcher.',
                     'behaviour' => 'Meets reliably and records the meeting.',
                     'attitude'  => 'Treats the student\'s development as the objective.',
                 ]],
                ['code' => 'HE_STUDENT_SUPPORT', 'name' => 'Student support and wellbeing', 'category' => 'Student experience',
                 'type' => 'behavioural', 'difficulty' => 'intermediate', 'criticality' => 'critical',
                 'description' => 'Recognising difficulty and connecting students to help.',
                 'kasba' => [
                     'knowledge' => 'Support services, referral routes and the limits of the academic role.',
                     'ability'   => 'Recognises disengagement as a signal rather than a choice.',
                     'skill'     => 'Holds a supportive conversation and refers appropriately.',
                     'behaviour' => 'Follows up rather than assuming the referral landed.',
                     'attitude'  => 'Treats wellbeing as within scope.',
                 ]],
                ['code' => 'HE_QUALITY_ASSURANCE', 'name' => 'Academic quality assurance', 'category' => 'Governance',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'high',
                 'description' => 'Maintaining standards that survive external scrutiny.',
                 'kasba' => [
                     'knowledge' => 'Regulatory framework, external examiner process and academic regulations.',
                     'ability'   => 'Recognises grade drift or standard erosion.',
                     'skill'     => 'Runs moderation and boards correctly.',
                     'behaviour' => 'Acts on external examiner comment rather than noting it.',
                     'attitude'  => 'Defends the standard against pressure to relax it.',
                 ]],
                ['code' => 'HE_RESEARCH_FUNDING', 'name' => 'Research funding', 'category' => 'Research',
                 'type' => 'skill', 'difficulty' => 'advanced', 'criticality' => 'medium',
                 'description' => 'Winning and managing external research income.',
                 'kasba' => [
                     'knowledge' => 'Funder priorities, costing rules and compliance conditions.',
                     'ability'   => 'Matches a research idea to a funder without distorting it.',
                     'skill'     => 'Writes fundable applications and manages awards.',
                     'behaviour' => 'Reports variance to the funder promptly.',
                     'attitude'  => 'Treats unsuccessful applications as learnable.',
                 ]],
                ['code' => 'HE_WIDENING_PARTICIPATION', 'name' => 'Widening participation', 'category' => 'Student experience',
                 'type' => 'knowledge', 'difficulty' => 'intermediate', 'criticality' => 'medium',
                 'description' => 'Making access and success real rather than nominal.',
                 'kasba' => [
                     'knowledge' => 'Access gaps in this discipline and what has been shown to close them.',
                     'ability'   => 'Distinguishes an access barrier from an attainment one.',
                     'skill'     => 'Designs and delivers interventions with measured effect.',
                     'behaviour' => 'Measures outcome rather than participation.',
                     'attitude'  => 'Treats an attainment gap as the institution\'s problem.',
                 ]],
            ],
        ],
    ];

    /** @return array<int, string> */
    public static function industries(): array
    {
        return array_keys(self::PACKS);
    }

    public static function has(string $industryCode): bool
    {
        return isset(self::PACKS[$industryCode]);
    }

    /**
     * The terminology an industry uses, with anything it does not override
     * falling back to the generic label.
     *
     * @return array<string, string>
     */
    public static function terminology(string $industryCode): array
    {
        return array_merge(self::GENERIC_TERMS, self::PACKS[$industryCode]['terminology'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public static function capabilities(string $industryCode): array
    {
        return self::PACKS[$industryCode]['capabilities'] ?? [];
    }

    public static function label(string $industryCode): string
    {
        return self::PACKS[$industryCode]['label'] ?? ucwords(str_replace('_', ' ', $industryCode));
    }
}
