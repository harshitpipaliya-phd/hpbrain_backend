<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CapabilityController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\GraphController;
use App\Http\Controllers\Api\ReasoningEngineController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\KnowledgeLibraryController;
use App\Http\Controllers\Api\MentalModelController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MeasurementPlanController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ObservabilityController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\CaseController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EsoExecutionController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\ExecutorController;
use App\Http\Controllers\Api\HypothesisController;
use App\Http\Controllers\Api\KasbaController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OutcomeController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\ReasoningController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

/**
 * REST contract. These paths and shapes are load-bearing: web/src/api/*.ts
 * calls them literally, so a rename here breaks the SPA. They reproduce the
 * Express router mounts from api/src/app.ts exactly (ADR-007).
 *
 * Convention inherited from the Node build: collection reads are
 * /{resource}/{tenantId}, item reads are /{resource}/{tenantId}/{id}.
 * Express route-ordering bugs (a literal segment registered after a wildcard
 * being swallowed) do not apply to Laravel's router, but the literal-before-
 * wildcard ordering is kept anyway so the two files stay diffable.
 *
 * AUTHORIZATION CONVENTION — read this before adding a route.
 *
 * The enclosing group requires `permission:read`, which every role including
 * Viewer holds. `permission:read` is therefore an authentication floor, NOT an
 * authorization decision: a route that only carries the group default is
 * readable AND writable by a Viewer. Every mutating route must state its own
 * verb explicitly:
 *
 *   POST that creates a resource      -> permission:create
 *   PATCH/PUT that modifies one       -> permission:update
 *   DELETE                            -> permission:delete
 *   governance acts                   -> decision.approve / eso.execute /
 *                                        settings.manage / events.manage /
 *                                        tenant.manage
 *
 * Middleware composes additively, so a route carrying `permission:create` is
 * checked for read AND create. Per Role.php, Viewer holds only `read`, which
 * is what makes the explicit verb the thing that actually excludes Viewer.
 *
 * Two deliberate exceptions, both POSTs that write nothing (see notes at the
 * routes themselves): policy evaluation and evidence summarisation. They are
 * POST only because they take a request body.
 */

Route::prefix('v1')->group(function () {

    // ---- Public -----------------------------------------------------------
    // Throttled: credential stuffing against an unlimited login endpoint is
    // the cheapest attack available against a token-based API.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/login',   [AuthController::class, 'login']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
    });

    if (! app()->environment('production')) {
        Route::post('auth/dev-token', [AuthController::class, 'devToken']);
    }

    // ---- Authenticated ----------------------------------------------------
    Route::middleware(['jwt', 'tenant', 'permission:read'])->group(function () {

        // Self-service: changes the caller's OWN credential, identified from
        // the token rather than the body. Gating this on `create`/`update`
        // would stop a Viewer from rotating their own password, which is a
        // security regression, not a control.
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Foundation — Organization/Department/Person are read from the
        // institute ERP, not owned by the Brain (see ADR-006 / README).
        Route::get ('organizations/{tenantId}',      [OrganizationController::class, 'index']);
        Route::post('organizations',                 [OrganizationController::class, 'store'])->middleware('permission:tenant.manage');
        Route::get ('organizations/{tenantId}/{id}', [OrganizationController::class, 'show']);

        Route::get ('departments/{tenantId}',        [DepartmentController::class, 'index']);
        Route::post('departments',                   [DepartmentController::class, 'store'])->middleware('permission:create');
        Route::get ('departments/{tenantId}/{id}',   [DepartmentController::class, 'show']);

        Route::get ('people/{tenantId}/search',      [PersonController::class, 'search']);
        Route::get ('people/{tenantId}',             [PersonController::class, 'index']);
        Route::post('people',                        [PersonController::class, 'store'])->middleware('permission:create');
        Route::get ('people/{tenantId}/{id}',        [PersonController::class, 'show']);

        // NOTE ON ORDERING: `capabilities/{tenantId}/search` MUST stay above
        // `capabilities/{tenantId}/{id}`. Laravel matches in registration
        // order, so with /{id} first the literal /search was swallowed by
        // show() with id='search' and the search endpoint answered
        // 404 capability_not_found on every call. The file header claims this
        // Express hazard does not apply to Laravel — it does, and this was it.
        Route::get ('capabilities/{tenantId}',        [CapabilityController::class, 'index']);
        Route::get ('capabilities/{tenantId}/search', [CapabilityController::class, 'search']);
        Route::post('capabilities',                   [CapabilityController::class, 'store'])->middleware('permission:create');
        Route::get ('capabilities/{tenantId}/{id}',   [CapabilityController::class, 'show']);

        // The Organizational Intelligence Loop, in stage order.
        Route::get ('signals/{tenantId}',            [SignalController::class, 'index']);
        Route::post('signals',                       [SignalController::class, 'store'])->middleware('permission:create');

        Route::get ('evidence/{tenantId}',           [EvidenceController::class, 'index']);
        Route::post('evidence',                      [EvidenceController::class, 'store'])->middleware('permission:create');

        Route::get ('cases/{tenantId}',              [CaseController::class, 'index']);
        Route::post('cases',                         [CaseController::class, 'store'])->middleware('permission:create');
        Route::patch('cases/{tenantId}/{id}/transition', [CaseController::class, 'transition'])->middleware('permission:update');
        Route::post('cases/{tenantId}/{id}/evidence',   [CaseController::class, 'attachEvidence'])->middleware('permission:update');

        Route::get ('hypotheses/{tenantId}/case/{caseId}', [HypothesisController::class, 'forCase']);
        Route::post('hypotheses',                    [HypothesisController::class, 'store'])->middleware('permission:create');
        Route::post('hypotheses/{tenantId}/{id}/status', [HypothesisController::class, 'setStatus'])->middleware('permission:update');

        Route::get ('reasoning/{tenantId}/signal/{signalId}', [ReasoningController::class, 'forSignal']);
        Route::post('reasoning',                     [ReasoningController::class, 'store'])->middleware('permission:create');

        Route::get ('recommendations/{tenantId}',    [RecommendationController::class, 'index']);
        Route::post('recommendations',               [RecommendationController::class, 'store'])->middleware('permission:create');

        Route::get ('decisions/{tenantId}',          [DecisionController::class, 'index']);
        Route::post('decisions',                     [DecisionController::class, 'store'])->middleware('permission:create');
        Route::post('decisions/{tenantId}/{id}/approve', [DecisionController::class, 'approve'])->middleware('permission:decision.approve');

        Route::get ('eso-executions/{tenantId}',     [EsoExecutionController::class, 'index']);
        Route::post('eso-executions',                [EsoExecutionController::class, 'store'])->middleware('permission:eso.execute');
        Route::patch('eso-executions/{tenantId}/{id}/transition', [EsoExecutionController::class, 'complete'])->middleware('permission:eso.execute');
        Route::post('eso-executions/{tenantId}/{id}/rollback',   [EsoExecutionController::class, 'rollback'])->middleware('permission:eso.execute');
        Route::get ('eso-executions/{tenantId}/eso/{esoId}',      [EsoExecutionController::class, 'history']);

        Route::post('measurement-plans',                          [MeasurementPlanController::class, 'store'])->middleware('permission:create');

        Route::get ('outcomes/{tenantId}',           [OutcomeController::class, 'index']);
        Route::post('outcomes',                      [OutcomeController::class, 'store'])->middleware('permission:create');

        Route::get ('learnings/{tenantId}/reusable', [LearningController::class, 'reusable']);
        Route::get ('learnings/{tenantId}',          [LearningController::class, 'index']);
        Route::post('learnings',                     [LearningController::class, 'store'])->middleware('permission:create');

        // Supporting surfaces
        Route::get ('kasba/assessment/{tenantId}/assignment/{assignmentId}/{capabilityId}', [KasbaController::class, 'assessment']);
        Route::post('kasba/proficiency',             [KasbaController::class, 'recordProficiency'])->middleware('permission:create');

        Route::get ('executors/{tenantId}',          [ExecutorController::class, 'index']);
        Route::post('executors',                     [ExecutorController::class, 'store'])->middleware('permission:create');

        Route::get ('policies/{tenantId}',           [PolicyController::class, 'index']);
        // NOTE: `POST policies` was registered twice — here and again in the
        // policies block below. Laravel keeps the LAST registration for an
        // identical method+URI, so the earlier one was dead and a permission
        // added to it would have been silently discarded. Deduplicated: the
        // single surviving definition lives in the policies block.


        // ---- Observability, audit, analytics --------------------------------
        Route::get ('audit',                         [AuditController::class, 'index']);
        Route::get ('audit/activity',                [AuditController::class, 'activity']);
        Route::get ('audit/stats',                   [AuditController::class, 'stats']);

        Route::get ('observability/health',          [ObservabilityController::class, 'health']);
        Route::get ('observability/health/database', [ObservabilityController::class, 'database']);
        Route::get ('observability/health/neo4j',    [ObservabilityController::class, 'neo4j']);
        Route::get ('observability/health/events',   [ObservabilityController::class, 'events']);
        Route::get ('observability/health/system',   [ObservabilityController::class, 'system']);
        Route::get ('observability/metrics/system',  [ObservabilityController::class, 'systemMetrics']);
        Route::get ('observability/metrics/{tenantId}', [ObservabilityController::class, 'metrics']);

        Route::get ('analytics/{tenantId}',                        [AnalyticsController::class, 'index']);
        Route::get ('analytics/{tenantId}/executive-summary',      [AnalyticsController::class, 'executiveSummary']);
        Route::get ('analytics/{tenantId}/decision-intelligence',  [AnalyticsController::class, 'decisionIntelligence']);
        // The Export CSV button on the Decision Intelligence screen has always
        // pointed here; the route did not exist, so it downloaded a 404.
        Route::get ('analytics/{tenantId}/decisions/export.csv',   [AnalyticsController::class, 'decisionsCsv']);

        // ---- Notifications and settings -------------------------------------
        // read-all / read are self-service: they flip read_date on the caller's
        // OWN notifications and create no domain state, so `read` is the right
        // floor and a Viewer must keep the ability to clear their own bell.
        // The self-scoping that makes this true is enforced in the controller
        // (WHERE user_id = caller); without it these would be tenant-wide
        // writes over other people's rows and `read` would be far too weak.
        Route::get   ('notifications/{tenantId}',              [NotificationController::class, 'index']);
        Route::get   ('notifications/{tenantId}/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post  ('notifications/{tenantId}/read-all',     [NotificationController::class, 'markAllRead']);
        Route::patch ('notifications/{tenantId}/{id}/read',    [NotificationController::class, 'markRead']);

        Route::get('settings/{tenantId}', [SettingsController::class, 'index']);
        Route::put('settings/{tenantId}', [SettingsController::class, 'set'])->middleware('permission:settings.manage');

        // ---- Event backbone (ADR-002) ---------------------------------------
        // The collection read the Event Store screen calls. It was never
        // registered, so that screen could only ever 404. Must stay above
        // `events/{id}` for the same registration-order reason as capabilities.
        Route::get   ('events',               [EventController::class, 'index']);
        Route::get   ('events/stats/summary', [EventController::class, 'stats']);
        Route::get   ('events/dlq',           [EventController::class, 'dlq']);
        Route::get   ('events/consumers',     [EventController::class, 'consumers']);
        Route::post  ('events/retry/failed',  [EventController::class, 'retryFailed'])->middleware('permission:events.manage');
        Route::post  ('events/dlq/{id}/retry',[EventController::class, 'retryDlq'])->middleware('permission:events.manage');
        Route::delete('events/dlq/{id}',      [EventController::class, 'deleteDlq'])->middleware('permission:events.manage');
        Route::post  ('events/{id}/replay',   [EventController::class, 'replay'])->middleware('permission:events.manage');
        Route::get   ('events/{id}',          [EventController::class, 'show']);


        // ---- Risks, mental models, knowledge library -------------------------
        Route::get ('risks/{tenantId}',                  [RiskController::class, 'index']);
        Route::post('risks',                             [RiskController::class, 'assess'])->middleware('permission:create');
        Route::post('risks/{tenantId}/{id}/mitigate',    [RiskController::class, 'mitigate'])->middleware('permission:update');

        Route::get('mental-models/{tenantId}',                   [MentalModelController::class, 'index']);
        Route::get('mental-models/{tenantId}/domain/{domain}',   [MentalModelController::class, 'byDomain']);

        Route::get ('knowledge-library/{tenantId}/search',    [KnowledgeLibraryController::class, 'search']);
        Route::get ('knowledge-library/{tenantId}',           [KnowledgeLibraryController::class, 'index']);
        Route::post('knowledge-library',                      [KnowledgeLibraryController::class, 'store'])->middleware('permission:create');
        Route::post('knowledge-library/{tenantId}/{id}/reuse',[KnowledgeLibraryController::class, 'markReused'])->middleware('permission:update');

        // ---- Conversations ---------------------------------------------------
        Route::get   ('conversations/sessions/{tenantId}/search',        [ConversationController::class, 'search']);
        Route::get   ('conversations/sessions/{tenantId}',               [ConversationController::class, 'index']);
        Route::post  ('conversations/sessions',                          [ConversationController::class, 'store'])->middleware('permission:create');
        Route::get   ('conversations/sessions/{tenantId}/{id}/messages', [ConversationController::class, 'messages']);
        // sendMessage INSERTs a durable message row and bumps the session's
        // updated_date. It is content creation, not self-service: the session
        // is shared tenant state, and nothing ties it to the caller. Viewer
        // must not be able to write into a thread -> create.
        Route::post  ('conversations/sessions/{tenantId}/{id}/messages', [ConversationController::class, 'sendMessage'])->middleware('permission:create');
        Route::patch ('conversations/sessions/{tenantId}/{id}/pin',      [ConversationController::class, 'setPinned'])->middleware('permission:update');
        Route::patch ('conversations/sessions/{tenantId}/{id}/rename',   [ConversationController::class, 'rename'])->middleware('permission:update');
        Route::get   ('conversations/sessions/{tenantId}/{id}',          [ConversationController::class, 'show']);
        Route::delete('conversations/sessions/{tenantId}/{id}',          [ConversationController::class, 'destroy'])->middleware('permission:delete');
        Route::get   ('conversations/prompt-templates/{tenantId}',       [ConversationController::class, 'promptTemplates']);
        Route::post  ('conversations/prompt-templates',                  [ConversationController::class, 'storePromptTemplate'])->middleware('permission:create');


        // ---- KASBA ----------------------------------------------------------
        Route::get ('kasba/heatmap/{tenantId}',                          [KasbaController::class, 'heatmap']);
        Route::get ('kasba/tasks/{tenantId}/capability/{capabilityId}',  [KasbaController::class, 'tasksForCapability']);
        Route::post('kasba/tasks',                                       [KasbaController::class, 'storeTask'])->middleware('permission:create');
        Route::get ('kasba/proficiency/{tenantId}/assignment/{assignmentId}/history', [KasbaController::class, 'proficiencyHistory']);
        Route::get ('kasba/proficiency/{tenantId}/assignment/{assignmentId}/trend',   [KasbaController::class, 'proficiencyTrend']);

        // ---- Capabilities ----------------------------------------------------
        // `search` is registered in the Foundation block above, ahead of the
        // /{id} wildcard that was shadowing it.
        Route::patch('capabilities/{tenantId}/{id}',              [CapabilityController::class, 'update'])->middleware('permission:update');
        Route::post ('capabilities/{tenantId}/{id}/version',      [CapabilityController::class, 'createVersion'])->middleware('permission:create');
        Route::get  ('capabilities/{tenantId}/{id}/versions',     [CapabilityController::class, 'versions']);
        Route::post ('capabilities/{tenantId}/{id}/archive',      [CapabilityController::class, 'archive'])->middleware('permission:update');
        Route::post ('capabilities/{tenantId}/{id}/assign',       [CapabilityController::class, 'assign'])->middleware('permission:update');
        Route::get  ('capabilities/{tenantId}/{id}/assignments',  [CapabilityController::class, 'assignments']);
        Route::get  ('capabilities/{tenantId}/{id}/audit',        [CapabilityController::class, 'audit']);


        // ---- Search, chain, proactive reasoning, tasks ------------------------
        Route::get('search/{tenantId}',                        [SearchController::class, 'search']);
        Route::get('workspace/{tenantId}/signal/{signalId}/chain', [SearchController::class, 'signalChain']);

        Route::get('reasoning-engine/{tenantId}/missing-evidence',   [ReasoningEngineController::class, 'missingEvidence']);
        Route::get('reasoning-engine/{tenantId}/duplicate-signals',  [ReasoningEngineController::class, 'duplicateSignals']);
        Route::get('reasoning-engine/{tenantId}/early-warnings',     [ReasoningEngineController::class, 'earlyWarnings']);

        // The verb pipeline (ADR-004). A THIRD deliberate exception to the
        // "POST that writes needs a write permission" rule, alongside policy
        // evaluation and evidence summarisation: these ask a question. They
        // write only LearningGrounded traceability events, never domain state,
        // and requiring `update` would forbid a Viewer from asking why — see
        // the note on ReasoningEngineController::explain(). The writes are
        // bounded by the event store's idempotency key.
        // COACH, SIMULATE, RECOMMEND and EVALUATE are not wired; EXECUTE ships
        // dark and VerbPipeline refuses it outright.
        Route::post('reasoning-engine/{tenantId}/explain',           [ReasoningEngineController::class, 'explain']);
        Route::post('reasoning-engine/{tenantId}/assess',            [ReasoningEngineController::class, 'assess']);
        Route::get ('reasoning-engine/{tenantId}/memory-stats',      [ReasoningEngineController::class, 'memoryStats']);
        // RECOMMEND and EVALUATE are NOT exceptions to the write rule: they
        // create rows (recommendations, reasoning steps) and they spend money
        // on a provider call, so they carry `create` explicitly. EXECUTE is
        // still absent and stays that way — no AI path may trigger an ESO run.
        Route::post('reasoning-engine/{tenantId}/recommend',         [ReasoningEngineController::class, 'recommend'])->middleware('permission:create');
        Route::post('reasoning-engine/{tenantId}/evaluate',          [ReasoningEngineController::class, 'evaluate'])->middleware('permission:create');

        Route::get ('tasks/registry', [TaskController::class, 'registry']);
        // tasks/run is NOT read-adjacent. `expire-stale-evidence` issues a bulk
        // UPDATE rewriting evidence status across the whole tenant, and the
        // registry marks two of three tasks `mutates: true`. It needs write
        // authority -> update. It stops short of a governance permission
        // because it neither approves a decision nor executes an ESO; if the
        // registry ever gains a task that does, gate that task specifically.
        Route::post('tasks/run',      [TaskController::class, 'run'])->middleware('permission:update');

        // ---- Signals, cases, hypotheses, policies -----------------------------
        Route::get  ('signals/{tenantId}/{id}',        [SignalController::class, 'show']);
        Route::patch('signals/{tenantId}/{id}/status', [SignalController::class, 'changeStatus'])->middleware('permission:update');

        Route::get('cases/{tenantId}/{id}',          [CaseController::class, 'show']);
        Route::get('cases/{tenantId}/{id}/evidence', [CaseController::class, 'evidence']);

        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/reject',  [HypothesisController::class, 'reject'])->middleware('permission:update');
        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/support', [HypothesisController::class, 'support'])->middleware('permission:update');
        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/confirm', [HypothesisController::class, 'confirm'])->middleware('permission:update');

        Route::get ('policies/{tenantId}/{id}/history',  [PolicyController::class, 'history']);
        Route::post('policies/{tenantId}/{id}/version',  [PolicyController::class, 'createVersion'])->middleware('permission:create');
        // evaluate() is a pure function of (stored policy, request body): it
        // loads the policy, runs the rules against the supplied context and
        // returns the verdict. No INSERT, no UPDATE, nothing persisted. POST
        // only because the context arrives as a body. Read is sufficient and
        // correct — gating it would stop a Viewer from asking "would this be
        // allowed?", which is a read question.
        Route::post('policies/{tenantId}/{id}/evaluate', [PolicyController::class, 'evaluate']);
        Route::get ('policies/{tenantId}/{id}',          [PolicyController::class, 'show']);
        Route::post('policies',                          [PolicyController::class, 'store'])->middleware('permission:create');

        Route::get('evidence/{tenantId}/signal/{signalId}', [EvidenceController::class, 'forSignal']);

        // ---- ERP-entity lifecycle --------------------------------------------
        Route::patch('organizations/{tenantId}/{id}',         [OrganizationController::class, 'update'])->middleware('permission:update');
        Route::post ('organizations/{tenantId}/{id}/archive', [OrganizationController::class, 'archive'])->middleware('permission:update');
        Route::get  ('organizations/{tenantId}/{id}/audit',   [OrganizationController::class, 'audit']);

        Route::patch('departments/{tenantId}/{id}',         [DepartmentController::class, 'update'])->middleware('permission:update');
        Route::post ('departments/{tenantId}/{id}/archive', [DepartmentController::class, 'archive'])->middleware('permission:update');
        Route::get  ('departments/{tenantId}/{id}/audit',   [DepartmentController::class, 'audit']);
        Route::get  ('departments/{tenantId}/{id}/twin',    [DepartmentController::class, 'twin']);

        Route::patch('people/{tenantId}/{id}',         [PersonController::class, 'update'])->middleware('permission:update');
        Route::post ('people/{tenantId}/{id}/archive', [PersonController::class, 'archive'])->middleware('permission:update');
        Route::get  ('people/{tenantId}/{id}/audit',   [PersonController::class, 'audit']);
        Route::get  ('people/{tenantId}/{id}/twin',    [PersonController::class, 'twin']);


        // ---- AI (model-agnostic, ADR-004) and graph reads (ADR-008 seam) -----
        Route::get ('ai/providers',            [AiController::class, 'providers']);
        Route::get ('ai/executions/{tenantId}',[AiController::class, 'executions']);
        // summarizeEvidence currently SELECTs the evidence for a signal and
        // returns UNDETERMINED — no provider is wired, and it persists nothing
        // (it does not write hpbrain_ai_executions). As implemented it is a
        // read, so read is the honest gate.
        // REVISIT when a provider is actually wired: at that point this starts
        // spending money per call and is expected to record an execution row,
        // which makes it a create. The gate must change in the same commit as
        // the provider client, not after.
        Route::post('ai/evidence/summarize',   [AiController::class, 'summarizeEvidence']);

        Route::get('graph/{tenantId}/search',                        [GraphController::class, 'search']);
        Route::get('graph/{tenantId}/entity/{label}/{id}/related',   [GraphController::class, 'related']);
        Route::get('graph/{tenantId}/entity/{label}/{id}',           [GraphController::class, 'entity']);

        Route::get ('workspace/{tenantId}',          [WorkspaceController::class, 'summary']);
    });
});
