<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CapabilityController;
use App\Http\Controllers\Api\CaseController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EsoExecutionController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\ExecutorController;
use App\Http\Controllers\Api\GraphController;
use App\Http\Controllers\Api\HypothesisController;
use App\Http\Controllers\Api\KasbaController;
use App\Http\Controllers\Api\KnowledgeLibraryController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\MeasurementPlanController;
use App\Http\Controllers\Api\MentalModelController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ObservabilityController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OutcomeController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\ReasoningController;
use App\Http\Controllers\Api\ReasoningEngineController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\IntelligenceOverviewController;
use App\Http\Controllers\Api\OrganizationConfigController;
use App\Http\Controllers\Api\TerminologyController;
use App\Http\Controllers\Api\EntityMappingController;
use App\Http\Controllers\Api\FeatureFlagController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\OrganizationModuleController;
use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DashboardWidgetController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\ThemeController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\ConfigVersionController;
use App\Http\Controllers\Api\IndustryTemplateController;
use App\Http\Controllers\Api\OrganizationTypeController;
use App\Http\Controllers\Api\OrganizationUnitController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\CompetencyController;
use App\Http\Controllers\Api\PersonRoleController;
use App\Http\Controllers\Api\PersonSkillController;
use App\Http\Controllers\Api\PersonCompetencyController;
use App\Http\Controllers\Api\LocationTypeController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ReportingStructureController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\IngestionController;
use App\Http\Controllers\Api\ReadinessCheckController;
use App\Http\Controllers\Api\TemplateOverrideController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AiPromptTemplateController;
use App\Http\Controllers\Api\AiEvaluationController;
use App\Http\Controllers\Api\AiFeedbackController;
use App\Http\Controllers\Api\AiQuotaController;
use App\Http\Controllers\Api\AiWorkspaceController;
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
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');
    });

    // Self-service organization signup. Creates a TENANT, so it is throttled
    // harder than login: a login attempt costs one row read, while this one
    // writes across six ERP tables plus 39 mapping rows and mints an identity.
    // Five per ten minutes is generous for a human filling in a form once and
    // hostile to anything creating tenants in bulk.
    //
    // REGISTERED OUTSIDE THE GROUP ABOVE, NOT INSIDE IT WITH ITS OWN LIMIT.
    // Two unnamed `throttle:` middlewares on one route resolve to the SAME
    // signature key — route URI plus IP — so both increment the same counter and
    // every request is charged twice. Nested inside `throttle:10,1` this limit
    // fired on the third signup rather than the sixth, which is how it was
    // found. One throttle per route, and the number means what it says.
    Route::post('auth/signup', [AuthController::class, 'signup'])->middleware('throttle:5,10');

    // ---- Authenticated ----------------------------------------------------
    Route::middleware(['jwt', 'tenant', 'permission:read'])->group(function () {

        // Self-service: changes the caller's OWN credential, identified from
        // the token rather than the body. Gating this on `create`/`update`
        // would stop a Viewer from rotating their own password, which is a
        // security regression, not a control.
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Foundation — Organization/Department/Person are read from the
        // institute ERP, not owned by the Brain (see ADR-006 / README).
        Route::get('organizations/{tenantId}', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('permission:tenant.manage');
        Route::get('organizations/{tenantId}/{id}', [OrganizationController::class, 'show']);

        Route::get('departments/{tenantId}', [DepartmentController::class, 'index']);
        Route::post('departments', [DepartmentController::class, 'store'])->middleware('permission:create');
        Route::get('departments/{tenantId}/{id}', [DepartmentController::class, 'show']);

        Route::get('people/{tenantId}/search', [PersonController::class, 'search']);
        Route::get('people/{tenantId}', [PersonController::class, 'index']);
        Route::post('people', [PersonController::class, 'store'])->middleware('permission:create');
        Route::get('people/{tenantId}/{id}', [PersonController::class, 'show']);

        // NOTE ON ORDERING: `capabilities/{tenantId}/search` MUST stay above
        // `capabilities/{tenantId}/{id}`. Laravel matches in registration
        // order, so with /{id} first the literal /search was swallowed by
        // show() with id='search' and the search endpoint answered
        // 404 capability_not_found on every call. The file header claims this
        // Express hazard does not apply to Laravel — it does, and this was it.
        Route::get('capabilities/{tenantId}', [CapabilityController::class, 'index']);
        Route::get('capabilities/{tenantId}/search', [CapabilityController::class, 'search']);
        Route::post('capabilities', [CapabilityController::class, 'store'])->middleware('permission:create');
        Route::get('capabilities/{tenantId}/{id}', [CapabilityController::class, 'show']);

        // The Organizational Intelligence Loop, in stage order.
        Route::get('signals/{tenantId}', [SignalController::class, 'index']);
        Route::post('signals', [SignalController::class, 'store'])->middleware('permission:create');

        Route::get('evidence/{tenantId}', [EvidenceController::class, 'index']);
        Route::post('evidence', [EvidenceController::class, 'store'])->middleware('permission:create');

        Route::get('cases/{tenantId}', [CaseController::class, 'index']);
        Route::post('cases', [CaseController::class, 'store'])->middleware('permission:create');
        Route::patch('cases/{tenantId}/{id}/transition', [CaseController::class, 'transition'])->middleware('permission:update');
        Route::post('cases/{tenantId}/{id}/evidence', [CaseController::class, 'attachEvidence'])->middleware('permission:update');

        Route::get('hypotheses/{tenantId}/case/{caseId}', [HypothesisController::class, 'forCase']);
        Route::post('hypotheses', [HypothesisController::class, 'store'])->middleware('permission:create');
        Route::post('hypotheses/{tenantId}/{id}/status', [HypothesisController::class, 'setStatus'])->middleware('permission:update');

        Route::get('reasoning/{tenantId}/signal/{signalId}', [ReasoningController::class, 'forSignal']);
        Route::post('reasoning', [ReasoningController::class, 'store'])->middleware('permission:create');

        Route::get('recommendations/{tenantId}', [RecommendationController::class, 'index']);
        Route::post('recommendations', [RecommendationController::class, 'store'])->middleware('permission:create');

        Route::get('decisions/{tenantId}', [DecisionController::class, 'index']);
        Route::post('decisions', [DecisionController::class, 'store'])->middleware('permission:create');
        Route::post('decisions/{tenantId}/{id}/approve', [DecisionController::class, 'approve'])->middleware('permission:decision.approve');

        Route::get('eso-executions/{tenantId}', [EsoExecutionController::class, 'index']);
        Route::post('eso-executions', [EsoExecutionController::class, 'store'])->middleware('permission:eso.execute');
        Route::patch('eso-executions/{tenantId}/{id}/transition', [EsoExecutionController::class, 'complete'])->middleware('permission:eso.execute');
        Route::post('eso-executions/{tenantId}/{id}/rollback', [EsoExecutionController::class, 'rollback'])->middleware('permission:eso.execute');
        Route::get('eso-executions/{tenantId}/eso/{esoId}', [EsoExecutionController::class, 'history']);

        Route::post('measurement-plans', [MeasurementPlanController::class, 'store'])->middleware('permission:create');

        Route::get('outcomes/{tenantId}', [OutcomeController::class, 'index']);
        Route::post('outcomes', [OutcomeController::class, 'store'])->middleware('permission:create');

        Route::get('learnings/{tenantId}/reusable', [LearningController::class, 'reusable']);
        Route::get('learnings/{tenantId}', [LearningController::class, 'index']);
        Route::post('learnings', [LearningController::class, 'store'])->middleware('permission:create');

        // Supporting surfaces
        Route::get('kasba/assessment/{tenantId}/assignment/{assignmentId}/{capabilityId}', [KasbaController::class, 'assessment']);
        Route::post('kasba/proficiency', [KasbaController::class, 'recordProficiency'])->middleware('permission:create');

        Route::get('executors/{tenantId}', [ExecutorController::class, 'index']);
        Route::post('executors', [ExecutorController::class, 'store'])->middleware('permission:create');

        Route::get('policies/{tenantId}', [PolicyController::class, 'index']);
        // NOTE: `POST policies` was registered twice — here and again in the
        // policies block below. Laravel keeps the LAST registration for an
        // identical method+URI, so the earlier one was dead and a permission
        // added to it would have been silently discarded. Deduplicated: the
        // single surviving definition lives in the policies block.

        // ---- Observability, audit, analytics --------------------------------
        Route::get('audit', [AuditController::class, 'index']);
        Route::get('audit/activity', [AuditController::class, 'activity']);
        Route::get('audit/stats', [AuditController::class, 'stats']);

        Route::get('observability/health', [ObservabilityController::class, 'health']);
        Route::get('observability/health/database', [ObservabilityController::class, 'database']);
        Route::get('observability/health/neo4j', [ObservabilityController::class, 'neo4j']);
        Route::get('observability/health/events', [ObservabilityController::class, 'events']);
        Route::get('observability/health/system', [ObservabilityController::class, 'system']);
        Route::get('observability/metrics/system', [ObservabilityController::class, 'systemMetrics']);
        Route::get('observability/metrics/{tenantId}', [ObservabilityController::class, 'metrics']);

        Route::get('analytics/{tenantId}', [AnalyticsController::class, 'index']);
        Route::get('analytics/{tenantId}/signals', [AnalyticsController::class, 'signals']);
        Route::get('analytics/{tenantId}/executive-summary', [AnalyticsController::class, 'executiveSummary']);
        Route::get('analytics/{tenantId}/decision-intelligence', [AnalyticsController::class, 'decisionIntelligence']);
        Route::get('analytics/{tenantId}/trend', [AnalyticsController::class, 'trend']);
        // The Export CSV button on the Decision Intelligence screen has always
        // pointed here; the route did not exist, so it downloaded a 404.
        Route::get('analytics/{tenantId}/decisions/export.csv', [AnalyticsController::class, 'decisionsCsv']);
        Route::get('analytics/{tenantId}/reports/organization', [AnalyticsController::class, 'organizationReport']);
        Route::get('analytics/{tenantId}/reports/people', [AnalyticsController::class, 'peopleReport']);
        Route::get('analytics/{tenantId}/reports/intelligence', [AnalyticsController::class, 'intelligenceReport']);
        Route::get('analytics/{tenantId}/deliberation-overview', [IntelligenceOverviewController::class, 'deliberationOverview']);
        Route::get('analytics/{tenantId}/enterprise-overview', [IntelligenceOverviewController::class, 'enterpriseOverview']);
        Route::get('analytics/{tenantId}/execution-overview', [IntelligenceOverviewController::class, 'executionOverview']);

        // ---- Notifications and settings -------------------------------------
        // read-all / read are self-service: they flip read_date on the caller's
        // OWN notifications and create no domain state, so `read` is the right
        // floor and a Viewer must keep the ability to clear their own bell.
        // The self-scoping that makes this true is enforced in the controller
        // (WHERE user_id = caller); without it these would be tenant-wide
        // writes over other people's rows and `read` would be far too weak.
        Route::get('notifications/{tenantId}', [NotificationController::class, 'index']);
        Route::get('notifications/{tenantId}/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/{tenantId}/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('notifications/{tenantId}/{id}/read', [NotificationController::class, 'markRead']);

        Route::get('settings/{tenantId}', [SettingsController::class, 'index']);
        Route::put('settings/{tenantId}', [SettingsController::class, 'set'])->middleware('permission:settings.manage');

        // ---- Event backbone (ADR-002) ---------------------------------------
        // The collection read the Event Store screen calls. It was never
        // registered, so that screen could only ever 404. Must stay above
        // `events/{id}` for the same registration-order reason as capabilities.
        Route::get('events', [EventController::class, 'index']);
        Route::get('events/stats/summary', [EventController::class, 'stats']);
        Route::get('events/dlq', [EventController::class, 'dlq']);
        Route::get('events/consumers', [EventController::class, 'consumers']);
        Route::post('events/retry/failed', [EventController::class, 'retryFailed'])->middleware('permission:events.manage');
        Route::post('events/dlq/{id}/retry', [EventController::class, 'retryDlq'])->middleware('permission:events.manage');
        Route::delete('events/dlq/{id}', [EventController::class, 'deleteDlq'])->middleware('permission:events.manage');
        Route::post('events/{id}/replay', [EventController::class, 'replay'])->middleware('permission:events.manage');
        Route::get('events/{id}', [EventController::class, 'show']);

        // ---- Risks, mental models, knowledge library -------------------------
        Route::get('risks/{tenantId}', [RiskController::class, 'index']);
        Route::post('risks', [RiskController::class, 'assess'])->middleware('permission:create');
        Route::post('risks/{tenantId}/{id}/mitigate', [RiskController::class, 'mitigate'])->middleware('permission:update');

        Route::get('mental-models/{tenantId}', [MentalModelController::class, 'index']);
        Route::get('mental-models/{tenantId}/domain/{domain}', [MentalModelController::class, 'byDomain']);

        Route::get('knowledge-library/{tenantId}/search', [KnowledgeLibraryController::class, 'search']);
        Route::get('knowledge-library/{tenantId}', [KnowledgeLibraryController::class, 'index']);
        Route::post('knowledge-library', [KnowledgeLibraryController::class, 'store'])->middleware('permission:create');
        Route::post('knowledge-library/{tenantId}/{id}/reuse', [KnowledgeLibraryController::class, 'markReused'])->middleware('permission:update');

        // ---- Conversations ---------------------------------------------------
        Route::get('conversations/sessions/{tenantId}/search', [ConversationController::class, 'search']);
        Route::get('conversations/sessions/{tenantId}', [ConversationController::class, 'index']);
        Route::post('conversations/sessions', [ConversationController::class, 'store'])->middleware('permission:create');
        Route::get('conversations/sessions/{tenantId}/{id}/messages', [ConversationController::class, 'messages']);
        // sendMessage INSERTs a durable message row and bumps the session's
        // updated_date. It is content creation, not self-service: the session
        // is shared tenant state, and nothing ties it to the caller. Viewer
        // must not be able to write into a thread -> create.
        Route::post('conversations/sessions/{tenantId}/{id}/messages', [ConversationController::class, 'sendMessage'])->middleware('permission:create');
        Route::patch('conversations/sessions/{tenantId}/{id}/pin', [ConversationController::class, 'setPinned'])->middleware('permission:update');
        Route::patch('conversations/sessions/{tenantId}/{id}/rename', [ConversationController::class, 'rename'])->middleware('permission:update');
        Route::get('conversations/sessions/{tenantId}/{id}', [ConversationController::class, 'show']);
        Route::delete('conversations/sessions/{tenantId}/{id}', [ConversationController::class, 'destroy'])->middleware('permission:delete');
        Route::get('conversations/prompt-templates/{tenantId}', [ConversationController::class, 'promptTemplates']);
        Route::post('conversations/prompt-templates', [ConversationController::class, 'storePromptTemplate'])->middleware('permission:create');

        // ---- KASBA ----------------------------------------------------------
        Route::get('kasba/heatmap/{tenantId}', [KasbaController::class, 'heatmap']);
        Route::get('kasba/tasks/{tenantId}/capability/{capabilityId}', [KasbaController::class, 'tasksForCapability']);
        Route::post('kasba/tasks', [KasbaController::class, 'storeTask'])->middleware('permission:create');
        Route::get('kasba/proficiency/{tenantId}/assignment/{assignmentId}/history', [KasbaController::class, 'proficiencyHistory']);
        Route::get('kasba/proficiency/{tenantId}/assignment/{assignmentId}/trend', [KasbaController::class, 'proficiencyTrend']);

        // ---- Capabilities ----------------------------------------------------
        // `search` is registered in the Foundation block above, ahead of the
        // /{id} wildcard that was shadowing it.
        Route::patch('capabilities/{tenantId}/{id}', [CapabilityController::class, 'update'])->middleware('permission:update');
        Route::post('capabilities/{tenantId}/{id}/version', [CapabilityController::class, 'createVersion'])->middleware('permission:create');
        Route::get('capabilities/{tenantId}/{id}/versions', [CapabilityController::class, 'versions']);
        Route::post('capabilities/{tenantId}/{id}/archive', [CapabilityController::class, 'archive'])->middleware('permission:update');
        Route::post('capabilities/{tenantId}/{id}/assign', [CapabilityController::class, 'assign'])->middleware('permission:update');
        Route::get('capabilities/{tenantId}/{id}/assignments', [CapabilityController::class, 'assignments']);
        Route::get('capabilities/{tenantId}/{id}/audit', [CapabilityController::class, 'audit']);

        // ---- Search, chain, proactive reasoning, tasks ------------------------
        Route::get('search/{tenantId}', [SearchController::class, 'search']);
        Route::get('workspace/{tenantId}/signal/{signalId}/chain', [SearchController::class, 'signalChain']);

        Route::get('reasoning-engine/{tenantId}/missing-evidence', [ReasoningEngineController::class, 'missingEvidence']);
        Route::get('reasoning-engine/{tenantId}/duplicate-signals', [ReasoningEngineController::class, 'duplicateSignals']);
        Route::get('reasoning-engine/{tenantId}/early-warnings', [ReasoningEngineController::class, 'earlyWarnings']);

        // The verb pipeline (ADR-004). A THIRD deliberate exception to the
        // "POST that writes needs a write permission" rule, alongside policy
        // evaluation and evidence summarisation: these ask a question. They
        // write only LearningGrounded traceability events, never domain state,
        // and requiring `update` would forbid a Viewer from asking why — see
        // the note on ReasoningEngineController::explain(). The writes are
        // bounded by the event store's idempotency key.
        // COACH, SIMULATE, RECOMMEND and EVALUATE are not wired; EXECUTE ships
        // dark and VerbPipeline refuses it outright.
        Route::post('reasoning-engine/{tenantId}/explain', [ReasoningEngineController::class, 'explain']);
        Route::post('reasoning-engine/{tenantId}/assess', [ReasoningEngineController::class, 'assess']);
        Route::get('reasoning-engine/{tenantId}/memory-stats', [ReasoningEngineController::class, 'memoryStats']);
        // RECOMMEND and EVALUATE are NOT exceptions to the write rule: they
        // create rows (recommendations, reasoning steps) and they spend money
        // on a provider call, so they carry `create` explicitly. EXECUTE is
        // still absent and stays that way — no AI path may trigger an ESO run.
        Route::post('reasoning-engine/{tenantId}/recommend', [ReasoningEngineController::class, 'recommend'])->middleware('permission:create');
        Route::post('reasoning-engine/{tenantId}/evaluate', [ReasoningEngineController::class, 'evaluate'])->middleware('permission:create');

        Route::get('tasks/registry', [TaskController::class, 'registry']);
        // tasks/run is NOT read-adjacent. `expire-stale-evidence` issues a bulk
        // UPDATE rewriting evidence status across the whole tenant, and the
        // registry marks two of three tasks `mutates: true`. It needs write
        // authority -> update. It stops short of a governance permission
        // because it neither approves a decision nor executes an ESO; if the
        // registry ever gains a task that does, gate that task specifically.
        Route::post('tasks/run', [TaskController::class, 'run'])->middleware('permission:update');

        // ---- Signals, cases, hypotheses, policies -----------------------------
        Route::get('signals/{tenantId}/{id}', [SignalController::class, 'show']);
        Route::patch('signals/{tenantId}/{id}/status', [SignalController::class, 'changeStatus'])->middleware('permission:update');

        Route::get('cases/{tenantId}/{id}', [CaseController::class, 'show']);
        Route::get('cases/{tenantId}/{id}/evidence', [CaseController::class, 'evidence']);

        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/reject', [HypothesisController::class, 'reject'])->middleware('permission:update');
        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/support', [HypothesisController::class, 'support'])->middleware('permission:update');
        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/confirm', [HypothesisController::class, 'confirm'])->middleware('permission:update');

        Route::get('policies/{tenantId}/{id}/history', [PolicyController::class, 'history']);
        Route::post('policies/{tenantId}/{id}/version', [PolicyController::class, 'createVersion'])->middleware('permission:create');
        // evaluate() is a pure function of (stored policy, request body): it
        // loads the policy, runs the rules against the supplied context and
        // returns the verdict. No INSERT, no UPDATE, nothing persisted. POST
        // only because the context arrives as a body. Read is sufficient and
        // correct — gating it would stop a Viewer from asking "would this be
        // allowed?", which is a read question.
        Route::post('policies/{tenantId}/{id}/evaluate', [PolicyController::class, 'evaluate']);
        Route::get('policies/{tenantId}/{id}', [PolicyController::class, 'show']);
        Route::post('policies', [PolicyController::class, 'store'])->middleware('permission:create');

        Route::get('evidence/{tenantId}/signal/{signalId}', [EvidenceController::class, 'forSignal']);

        // ---- ERP-entity lifecycle --------------------------------------------
        Route::patch('organizations/{tenantId}/{id}', [OrganizationController::class, 'update'])->middleware('permission:update');
        Route::post('organizations/{tenantId}/{id}/archive', [OrganizationController::class, 'archive'])->middleware('permission:update');
        Route::get('organizations/{tenantId}/{id}/audit', [OrganizationController::class, 'audit']);
        Route::get('organizations/{tenantId}/{id}/structure', [OrganizationController::class, 'structure']);
        Route::get('organizations/{tenantId}/{id}/data-quality', [OrganizationController::class, 'dataQuality']);

        Route::patch('departments/{tenantId}/{id}', [DepartmentController::class, 'update'])->middleware('permission:update');
        Route::post('departments/{tenantId}/{id}/archive', [DepartmentController::class, 'archive'])->middleware('permission:update');
        Route::get('departments/{tenantId}/{id}/audit', [DepartmentController::class, 'audit']);
        Route::get('departments/{tenantId}/{id}/twin', [DepartmentController::class, 'twin']);

        Route::patch('people/{tenantId}/{id}', [PersonController::class, 'update'])->middleware('permission:update');
        Route::post('people/{tenantId}/{id}/archive', [PersonController::class, 'archive'])->middleware('permission:update');
        Route::get('people/{tenantId}/{id}/audit', [PersonController::class, 'audit']);
        Route::get('people/{tenantId}/{id}/twin', [PersonController::class, 'twin']);

        // ---- AI (model-agnostic, ADR-004) and graph reads (ADR-008 seam) -----
        Route::get('ai/providers', [AiController::class, 'providers']);
        Route::get('ai/executions/{tenantId}', [AiController::class, 'executions']);
        // summarizeEvidence currently SELECTs the evidence for a signal and
        // returns UNDETERMINED — no provider is wired, and it persists nothing
        // (it does not write hpbrain_ai_executions). As implemented it is a
        // read, so read is the honest gate.
        // REVISIT when a provider is actually wired: at that point this starts
        // spending money per call and is expected to record an execution row,
        // which makes it a create. The gate must change in the same commit as
        // the provider client, not after.
        Route::post('ai/evidence/summarize', [AiController::class, 'summarizeEvidence']);

        Route::get('graph/{tenantId}/search', [GraphController::class, 'search']);
        Route::get('graph/{tenantId}/entity/{label}/{id}/related', [GraphController::class, 'related']);
        Route::get('graph/{tenantId}/entity/{label}/{id}', [GraphController::class, 'entity']);

        Route::get('workspace/{tenantId}', [WorkspaceController::class, 'summary']);
        Route::get('workspace/{tenantId}/home-metrics', [WorkspaceController::class, 'homeMetrics']);
        Route::post('signals/generate', [SignalController::class, 'generate'])->middleware('permission:events.manage');

        // ---- Universal Platform Foundation ------------------------------------
        Route::get('industries/{tenantId}', [IndustryController::class, 'index']);
        Route::post('industries', [IndustryController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('industries/{tenantId}/{id}', [IndustryController::class, 'show']);
        Route::patch('industries/{tenantId}/{id}', [IndustryController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('industries/{tenantId}/{id}', [IndustryController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('organization-configs/{tenantId}', [OrganizationConfigController::class, 'index']);
        Route::post('organization-configs', [OrganizationConfigController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('organization-configs/{tenantId}/{id}', [OrganizationConfigController::class, 'show']);
        Route::patch('organization-configs/{tenantId}/{id}', [OrganizationConfigController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('organization-configs/{tenantId}/{id}', [OrganizationConfigController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('terminology/{tenantId}', [TerminologyController::class, 'index']);
        Route::post('terminology', [TerminologyController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('terminology/{tenantId}/{id}', [TerminologyController::class, 'show']);
        Route::patch('terminology/{tenantId}/{id}', [TerminologyController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('terminology/{tenantId}/{id}', [TerminologyController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('entity-mappings/{tenantId}', [EntityMappingController::class, 'index']);
        Route::post('entity-mappings', [EntityMappingController::class, 'store'])->middleware('permission:tenant.manage');
        Route::get('entity-mappings/{tenantId}/{id}', [EntityMappingController::class, 'show']);
        Route::patch('entity-mappings/{tenantId}/{id}', [EntityMappingController::class, 'update'])->middleware('permission:tenant.manage');
        Route::delete('entity-mappings/{tenantId}/{id}', [EntityMappingController::class, 'destroy'])->middleware('permission:tenant.manage');

        Route::get('feature-flags/{tenantId}', [FeatureFlagController::class, 'index']);
        Route::post('feature-flags', [FeatureFlagController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('feature-flags/{tenantId}/{id}', [FeatureFlagController::class, 'show']);
        Route::patch('feature-flags/{tenantId}/{id}', [FeatureFlagController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('feature-flags/{tenantId}/{id}', [FeatureFlagController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('modules/{tenantId}', [ModuleController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('modules', [ModuleController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('modules/{tenantId}/{id}', [ModuleController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('modules/{tenantId}/{id}', [ModuleController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('modules/{tenantId}/{id}', [ModuleController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('organization-modules/{tenantId}', [OrganizationModuleController::class, 'index']);
        Route::post('organization-modules', [OrganizationModuleController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('organization-modules/{tenantId}/{id}', [OrganizationModuleController::class, 'show']);
        Route::patch('organization-modules/{tenantId}/{id}', [OrganizationModuleController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('organization-modules/{tenantId}/{id}', [OrganizationModuleController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('navigation/{tenantId}', [NavigationController::class, 'index']);
        Route::post('navigation', [NavigationController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('navigation/{tenantId}/{id}', [NavigationController::class, 'show']);
        Route::patch('navigation/{tenantId}/{id}', [NavigationController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('navigation/{tenantId}/{id}', [NavigationController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('dashboards/{tenantId}', [DashboardController::class, 'index']);
        Route::post('dashboards', [DashboardController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('dashboards/{tenantId}/{id}', [DashboardController::class, 'show']);
        Route::patch('dashboards/{tenantId}/{id}', [DashboardController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('dashboards/{tenantId}/{id}', [DashboardController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('dashboard-widgets/{tenantId}', [DashboardWidgetController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('dashboard-widgets', [DashboardWidgetController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('dashboard-widgets/{tenantId}/{id}', [DashboardWidgetController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('dashboard-widgets/{tenantId}/{id}', [DashboardWidgetController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('dashboard-widgets/{tenantId}/{id}', [DashboardWidgetController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('branding/{tenantId}', [BrandingController::class, 'index']);
        Route::post('branding', [BrandingController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('branding/{tenantId}/{id}', [BrandingController::class, 'show']);
        Route::patch('branding/{tenantId}/{id}', [BrandingController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('branding/{tenantId}/{id}', [BrandingController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('themes/{tenantId}', [ThemeController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('themes', [ThemeController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('themes/{tenantId}/{id}', [ThemeController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('themes/{tenantId}/{id}', [ThemeController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('themes/{tenantId}/{id}', [ThemeController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('forms/{tenantId}', [FormController::class, 'index']);
        Route::post('forms', [FormController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('forms/{tenantId}/{id}', [FormController::class, 'show']);
        Route::patch('forms/{tenantId}/{id}', [FormController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('forms/{tenantId}/{id}', [FormController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('config-versions/{tenantId}', [ConfigVersionController::class, 'index']);
        Route::post('config-versions', [ConfigVersionController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('config-versions/{tenantId}/{id}', [ConfigVersionController::class, 'show']);
        Route::post('config-versions/{tenantId}/{id}/activate', [ConfigVersionController::class, 'activate'])->middleware('permission:settings.manage');
        Route::post('config-versions/{tenantId}/{id}/rollback', [ConfigVersionController::class, 'rollback'])->middleware('permission:settings.manage');

        Route::get('industry-templates/{tenantId}', [IndustryTemplateController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('industry-templates', [IndustryTemplateController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('industry-templates/{tenantId}/{id}', [IndustryTemplateController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('industry-templates/{tenantId}/{id}', [IndustryTemplateController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('industry-templates/{tenantId}/{id}', [IndustryTemplateController::class, 'destroy'])->middleware('permission:settings.manage');

        // ---- Universal Organization Engine (Prompt 3.2) -----------------------
        Route::get('organization-types/{tenantId}', [OrganizationTypeController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('organization-types', [OrganizationTypeController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('organization-types/{tenantId}/{id}', [OrganizationTypeController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('organization-types/{tenantId}/{id}', [OrganizationTypeController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('organization-types/{tenantId}/{id}', [OrganizationTypeController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('organization-units/{tenantId}', [OrganizationUnitController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('organization-units', [OrganizationUnitController::class, 'store'])->middleware('permission:settings.manage');

        /*
         | LITERAL SEGMENTS BEFORE {id}. Laravel matches first-registered-wins,
         | so while these two sat below organization-units/{tenantId}/{id} they
         | were unreachable: /organization-units/7/hierarchy resolved to show()
         | with $id = 'hierarchy' and answered 404 organization_unit_not_found.
         | hierarchy() and tree() had therefore never executed
         | (docs/API-FUNCTIONAL-AUDIT.md F3).
         |
         | Keep any future literal route in this block, above the {id} lines.
         */
        Route::get('organization-units/{tenantId}/hierarchy', [OrganizationUnitController::class, 'hierarchy'])->middleware('permission:settings.manage');
        Route::get('organization-units/{tenantId}/tree', [OrganizationUnitController::class, 'tree'])->middleware('permission:settings.manage');

        Route::get('organization-units/{tenantId}/{id}', [OrganizationUnitController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('organization-units/{tenantId}/{id}', [OrganizationUnitController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('organization-units/{tenantId}/{id}', [OrganizationUnitController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('roles/{tenantId}', [RoleController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('roles/{tenantId}/{id}', [RoleController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('roles/{tenantId}/{id}', [RoleController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('roles/{tenantId}/{id}', [RoleController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('positions/{tenantId}', [PositionController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('positions', [PositionController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('positions/{tenantId}/{id}', [PositionController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('positions/{tenantId}/{id}', [PositionController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('positions/{tenantId}/{id}', [PositionController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('skills/{tenantId}', [SkillController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('skills', [SkillController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('skills/{tenantId}/{id}', [SkillController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('skills/{tenantId}/{id}', [SkillController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('skills/{tenantId}/{id}', [SkillController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('competencies/{tenantId}', [CompetencyController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('competencies', [CompetencyController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('competencies/{tenantId}/{id}', [CompetencyController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('competencies/{tenantId}/{id}', [CompetencyController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('competencies/{tenantId}/{id}', [CompetencyController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('person-roles/{tenantId}', [PersonRoleController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('person-roles', [PersonRoleController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('person-roles/{tenantId}/{id}', [PersonRoleController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('person-roles/{tenantId}/{id}', [PersonRoleController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('person-roles/{tenantId}/{id}', [PersonRoleController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('person-skills/{tenantId}', [PersonSkillController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('person-skills', [PersonSkillController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('person-skills/{tenantId}/{id}', [PersonSkillController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('person-skills/{tenantId}/{id}', [PersonSkillController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('person-skills/{tenantId}/{id}', [PersonSkillController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('person-competencies/{tenantId}', [PersonCompetencyController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('person-competencies', [PersonCompetencyController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('person-competencies/{tenantId}/{id}', [PersonCompetencyController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('person-competencies/{tenantId}/{id}', [PersonCompetencyController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('person-competencies/{tenantId}/{id}', [PersonCompetencyController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('location-types/{tenantId}', [LocationTypeController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('location-types', [LocationTypeController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('location-types/{tenantId}/{id}', [LocationTypeController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('location-types/{tenantId}/{id}', [LocationTypeController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('location-types/{tenantId}/{id}', [LocationTypeController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('locations/{tenantId}', [LocationController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('locations', [LocationController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('locations/{tenantId}/{id}', [LocationController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('locations/{tenantId}/{id}', [LocationController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('locations/{tenantId}/{id}', [LocationController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('reporting-structures/{tenantId}', [ReportingStructureController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('reporting-structures', [ReportingStructureController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('reporting-structures/{tenantId}/{id}', [ReportingStructureController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('reporting-structures/{tenantId}/{id}', [ReportingStructureController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('reporting-structures/{tenantId}/{id}', [ReportingStructureController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('onboarding/{tenantId}', [OnboardingController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('onboarding/{tenantId}/start', [OnboardingController::class, 'start'])->middleware('permission:settings.manage');
        Route::get('onboarding/{tenantId}/{id}', [OnboardingController::class, 'show'])->middleware('permission:settings.manage');
        Route::post('onboarding/{tenantId}/{id}/complete-step', [OnboardingController::class, 'completeStep'])->middleware('permission:settings.manage');
        Route::get('onboarding/{tenantId}/{id}/next-step', [OnboardingController::class, 'getNextStep'])->middleware('permission:settings.manage');
        Route::post('onboarding/{tenantId}/{id}/validate-step', [OnboardingController::class, 'validateStep'])->middleware('permission:settings.manage');
        Route::post('onboarding/{tenantId}/{id}/activate', [OnboardingController::class, 'activate'])->middleware('permission:settings.manage');
        Route::post('onboarding/{tenantId}/{id}/abandon', [OnboardingController::class, 'abandon'])->middleware('permission:settings.manage');
        Route::get('onboarding/{tenantId}/{id}/readiness', [OnboardingController::class, 'readiness'])->middleware('permission:settings.manage');
        Route::post('onboarding/{tenantId}/{id}/readiness/run', [OnboardingController::class, 'runReadinessChecks'])->middleware('permission:settings.manage');

        Route::get('imports/{tenantId}', [ImportController::class, 'index'])->middleware('permission:settings.manage');
        Route::get('imports/{tenantId}/{id}', [ImportController::class, 'show'])->middleware('permission:settings.manage');
        Route::post('imports/validate', [ImportController::class, 'validate'])->middleware('permission:settings.manage');
        Route::post('imports/preview', [ImportController::class, 'preview'])->middleware('permission:settings.manage');
        Route::post('imports/detect-duplicates', [ImportController::class, 'detectDuplicates'])->middleware('permission:settings.manage');
        Route::post('imports', [ImportController::class, 'start'])->middleware('permission:settings.manage');
        Route::post('imports/{tenantId}/{id}/process', [ImportController::class, 'process'])->middleware('permission:settings.manage');
        Route::post('imports/{tenantId}/{id}/rollback', [ImportController::class, 'rollback'])->middleware('permission:settings.manage');
        Route::get('imports/{tenantId}/{id}/logs', [ImportController::class, 'logs'])->middleware('permission:settings.manage');

        // ---- Ingestion (external upload + internal ERP read) ------------------
        // Sits with the imports routes because it writes the same
        // hpbrain_import_jobs / hpbrain_import_logs tables and is undone by the
        // same POST /imports/{tenantId}/{id}/rollback.
        Route::get('ingestion/sources/{tenantId}', [IngestionController::class, 'sources'])->middleware('permission:settings.manage');
        Route::post('ingestion/upload', [IngestionController::class, 'upload'])->middleware('permission:settings.manage');
        Route::post('ingestion/internal', [IngestionController::class, 'internal'])->middleware('permission:settings.manage');
        Route::post('ingestion/{tenantId}/{jobId}/commit', [IngestionController::class, 'commit'])->middleware('permission:settings.manage');

        Route::get('readiness-checks/{tenantId}', [ReadinessCheckController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('readiness-checks', [ReadinessCheckController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('readiness-checks/{tenantId}/{id}', [ReadinessCheckController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('readiness-checks/{tenantId}/{id}', [ReadinessCheckController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('readiness-checks/{tenantId}/{id}', [ReadinessCheckController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('template-overrides/{tenantId}', [TemplateOverrideController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('template-overrides', [TemplateOverrideController::class, 'store'])->middleware('permission:settings.manage');
        Route::get('template-overrides/{tenantId}/{id}', [TemplateOverrideController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('template-overrides/{tenantId}/{id}', [TemplateOverrideController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('template-overrides/{tenantId}/{id}', [TemplateOverrideController::class, 'destroy'])->middleware('permission:settings.manage');

        // ---- Universal AI Brain (Prompt 3.3) ---------------------------------
        Route::prefix('ai')->group(function () {
            // TENANT-SCOPED, like every other index in this group.
            //
            // This was declared as a bare `providers`, which is the same
            // method+URI as `ai/providers` above (AiController@providers).
            // Laravel's route collection is keyed on method+URI, so the later
            // registration REPLACED the earlier one outright — the capability
            // endpoint stopped existing, and callers expecting its
            // {providers:[{name,available}]} payload silently received this
            // controller's name-keyed {name,class} map instead. That is what
            // crashed the home screen on `providers.filter`.
            //
            // The two are different resources that happened to collide on one
            // URL: AiController@providers reports which providers the
            // environment has keys for, while this lists a tenant's configured
            // provider records. Matching the {tenantId} pattern its four
            // siblings already use separates them.
            Route::get('providers/{tenantId}', [AiProviderController::class, 'index']);
            Route::post('providers', [AiProviderController::class, 'store'])->middleware('permission:settings.manage');
            Route::get('providers/{tenantId}/{id}', [AiProviderController::class, 'show']);
            Route::patch('providers/{tenantId}/{id}', [AiProviderController::class, 'update'])->middleware('permission:settings.manage');
            Route::delete('providers/{tenantId}/{id}', [AiProviderController::class, 'destroy'])->middleware('permission:settings.manage');
            Route::post('providers/{tenantId}/{id}/test', [AiProviderController::class, 'test'])->middleware('permission:settings.manage');
            Route::post('providers/{tenantId}/{id}/activate', [AiProviderController::class, 'setActive'])->middleware('permission:settings.manage');

            Route::get('prompt-templates/{tenantId}', [AiPromptTemplateController::class, 'index'])->middleware('permission:settings.manage');
            Route::post('prompt-templates', [AiPromptTemplateController::class, 'store'])->middleware('permission:settings.manage');
            Route::get('prompt-templates/{tenantId}/{id}', [AiPromptTemplateController::class, 'show'])->middleware('permission:settings.manage');
            Route::patch('prompt-templates/{tenantId}/{id}', [AiPromptTemplateController::class, 'update'])->middleware('permission:settings.manage');
            Route::delete('prompt-templates/{tenantId}/{id}', [AiPromptTemplateController::class, 'destroy'])->middleware('permission:settings.manage');
            Route::get('prompt-templates/{tenantId}/{id}/versions', [AiPromptTemplateController::class, 'versions']);
            Route::get('prompt-templates/{tenantId}/{id}/render', [AiPromptTemplateController::class, 'render']);

            Route::get('evaluations/{tenantId}', [AiEvaluationController::class, 'index'])->middleware('permission:settings.manage');
            Route::post('evaluations', [AiEvaluationController::class, 'store'])->middleware('permission:settings.manage');
            Route::get('evaluations/{tenantId}/{id}', [AiEvaluationController::class, 'show'])->middleware('permission:settings.manage');
            Route::post('evaluations/{tenantId}/{id}/run', [AiEvaluationController::class, 'run'])->middleware('permission:settings.manage');
            Route::get('evaluations/{tenantId}/{id}/results', [AiEvaluationController::class, 'results'])->middleware('permission:settings.manage');

            Route::get('feedback/{tenantId}', [AiFeedbackController::class, 'index']);
            Route::post('feedback', [AiFeedbackController::class, 'store']);
            Route::get('feedback/{tenantId}/{id}', [AiFeedbackController::class, 'show']);

            Route::get('quotas/{tenantId}', [AiQuotaController::class, 'index'])->middleware('permission:settings.manage');
            Route::post('quotas', [AiQuotaController::class, 'store'])->middleware('permission:settings.manage');
            Route::get('quotas/{tenantId}/{id}', [AiQuotaController::class, 'show'])->middleware('permission:settings.manage');
            Route::patch('quotas/{tenantId}/{id}', [AiQuotaController::class, 'update'])->middleware('permission:settings.manage');
            Route::post('quotas/{tenantId}/{id}/reset', [AiQuotaController::class, 'reset'])->middleware('permission:settings.manage');

            Route::get('workspace/sessions', [AiWorkspaceController::class, 'sessions']);
            Route::post('workspace/sessions', [AiWorkspaceController::class, 'store'])->middleware('permission:create');
            Route::get('workspace/sessions/{sessionId}/messages', [AiWorkspaceController::class, 'messages']);
            Route::post('workspace/sessions/{sessionId}/messages', [AiWorkspaceController::class, 'send']);
            Route::post('workspace/sessions/{sessionId}/messages/{messageId}/regenerate', [AiWorkspaceController::class, 'regenerate']);
            Route::post('workspace/sessions/{sessionId}/messages/{messageId}/explain', [AiWorkspaceController::class, 'explain']);
            Route::get('workspace/sessions/{sessionId}/messages/{messageId}/follow-up', [AiWorkspaceController::class, 'followUp']);
            Route::get('workspace/sessions/{sessionId}/history', [AiWorkspaceController::class, 'history']);
        });
    });
});

