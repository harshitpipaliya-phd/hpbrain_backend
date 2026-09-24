/* eslint-disable */
/**
 * AUTO-GENERATED from eso/eso.schema.yaml
 * DO NOT EDIT BY HAND.
 * Change the schema, then run: npm run generate
 */

/**
 * The versioned, governed, auditable specification of an ESO at rest (§5.5). In motion the ESO becomes a cognitive runtime (see eso-runtime.schema.yaml); the runtime may adapt only WITHIN the envelope this contract authorizes — it may never silently rewrite the procedure, exceed its trust level, or skip evidence hooks.
 *
 */
export interface ESOContract {
  /**
   * Governance and traceability. Every ESO points at exactly one graph node it operationalizes.
   */
  identity: {
    /**
     * Stable unique identifier.
     */
    esoId: string;
    name: string;
    /**
     * Semver. A runtime wanting different behaviour must propose a NEW version (§5.5).
     */
    version: string;
    status: "draft" | "published" | "deprecated";
    /**
     * Accountable owner.
     */
    owner: string;
    provenance: "authored" | "mined" | "imported";
    /**
     * Exactly one graph node this ESO operationalizes.
     */
    kasbaBinding: {
      nodeId: string;
      nodeType: "Knowledge" | "Ability" | "Skill" | "Attitude" | "Behaviour" | "Task" | "Role" | "Capability";
    };
  };
  /**
   * Programmatic invocation. The description is a TRIGGER CONDITION, not documentation — written for the router, not for humans (§5.2).
   *
   */
  trigger: {
    /**
     * Matching conditions for the router.
     */
    descriptionForMachine: string;
    applicableContexts: string[];
    /**
     * Gap types this ESO addresses.
     */
    gapTypes: string[];
  };
  /**
   * The 'what'. Makes the ESO composable: outputs of one can satisfy inputs/preconditions of another. NOTE: this block is NAMED "Contract" but is only 1 of 12 blocks — it is NOT the ESO contract. This naming is the likely origin of the "nine-field contract" error. Consider renaming to `specification`.
   *
   */
  contract: {
    /**
     * DEVELOP a capability | PERFORM a task | ASSESS a capability | DECIDE among options.
     *
     */
    objective: "DEVELOP" | "PERFORM" | "ASSESS" | "DECIDE";
    inputs: TypedParam[];
    outputs: TypedParam[];
    preconditions?: string[];
    /**
     * Graph refs that must hold before this ESO can run.
     */
    prerequisites?: string[];
    constraintsPolicies?: string[];
  };
  /**
   * The 'how'. Ordered steps. EXECUTOR IS PER-STEP, NOT PER-ESO — most real work is hybrid at step granularity (§5.2). In motion the procedure becomes "the skeleton the cognition animates" (§5.5).
   *
   */
  procedure: {
    /**
     * @minItems 1
     */
    steps: [
      {
        stepId: string;
        order: number;
        executorClass: "human" | "agent" | "software" | "hybrid";
        method: string;
        expectedArtifact: string;
      },
      ...{
        stepId: string;
        order: number;
        executorClass: "human" | "agent" | "software" | "hybrid";
        method: string;
        expectedArtifact: string;
      }[]
    ];
  };
  /**
   * The 'who'. The executor-orchestration layer expressed as data (§5.2). THIS IS THE AUTONOMY MODEL — use these trust levels; do not invent others.
   *
   */
  executorPolicy: {
    allowedExecutorClasses: ("human" | "agent" | "software" | "hybrid")[];
    /**
     * Current trust level PER EXECUTOR.
     */
    trustLevels: {
      executorRef: string;
      /**
       * The authorised autonomy ladder (§5.2). Effective autonomy at runtime is bounded by this ceiling.
       *
       */
      trustLevel: "observe" | "suggest" | "approve" | "autonomous";
    }[];
    routingCriteria: string[];
    escalationPath: string[];
  };
  /**
   * Supports for developing executors. Only meaningful when objective=DEVELOP. For PERFORM objectives this block holds job aids (§5.2).
   *
   */
  scaffolding?: {
    templates?: string[];
    workedExamples?: string[];
    /**
     * Venn/T-chart and similar thinking tools.
     */
    tools?: string[];
    sentenceStems?: string[];
    checklists?: string[];
    /**
     * Used when objective=PERFORM.
     */
    jobAids?: string[];
  };
  /**
   * THE HIGHEST-SIGNAL BLOCK (§5.2). Known failure modes, misconceptions, edge cases, anti-patterns. "Anticipating failure is the difference between a tutor and a manual, and between a safe agent and a liability." At runtime the Reasoner interprets signals against these (§5.5).
   *
   *
   * @minItems 1
   */
  gotchas: [
    {
      /**
       * Stable id — the runtime reports which gotcha fired.
       */
      gotchaId: string;
      kind: "failure-mode" | "misconception" | "edge-case" | "anti-pattern";
      description: string;
      detectionSignal?: string;
      response?: string;
    },
    ...{
      /**
       * Stable id — the runtime reports which gotcha fired.
       */
      gotchaId: string;
      kind: "failure-mode" | "misconception" | "edge-case" | "anti-pattern";
      description: string;
      detectionSignal?: string;
      response?: string;
    }[]
  ];
  /**
   * Success must be observable and machine-checkable wherever possible (§5.2).
   */
  assessment: {
    rubric?: string;
    /**
     * Mastery/acceptance threshold.
     */
    masteryThreshold?: number;
    /**
     * For objective=DEVELOP.
     */
    bloomLevel?: string;
    /**
     * Depth of Knowledge — for objective=DEVELOP.
     */
    dokLevel?: string;
    /**
     * Eval cases — for objective=PERFORM.
     */
    acceptanceTests?: string[];
    evaluator: "human" | "agent" | "software";
  };
  /**
   * THE WRITE-BACK CONTRACT. "This is what makes intelligence compound (Principle P6)" (§5.2). The runtime may NEVER skip evidence hooks (§5.5).
   *
   */
  evidenceHooks: {
    /**
     * What each execution must log.
     */
    mustLog: ("executor" | "context" | "artifacts" | "score" | "duration" | "exceptions")[];
    /**
     * Which graph properties this ESO's execution updates.
     */
    graphPropertiesUpdated: string[];
  };
  /**
   * ESOs are folders, not files — they carry or reference everything needed to run (§5.2).
   */
  resources?: {
    knowledgeNodes?: string[];
    documents?: string[];
    scripts?: string[];
    datasets?: string[];
    externalTools?: string[];
  };
  /**
   * Per-person / per-context state. "Longitudinal personalization; the system gets smarter about each executor over time" (§5.2). Feeds the runtime's episodic Memory faculty (§5.5).
   *
   */
  memory?: {
    scope?: "per-person" | "per-context";
    attemptHistory?: string[];
    adaptationsThatWorked?: string[];
    lastOutcome?: string;
  };
  /**
   * "ESOs compose like functions: Leadership = Diagnose ∘ Prioritize ∘ Decide ∘ Communicate" (§5.2).
   *
   */
  lineage: {
    /**
     * Sub-ESO refs.
     */
    composedOf?: string[];
    composesInto?: string[];
    supersedes?: string;
    supersededBy?: string;
    /**
     * Evidence for the design of the ESO itself.
     */
    sourceEvidence?: string[];
  };
}
export interface TypedParam {
  name: string;
  type: string;
  required?: boolean;
}
