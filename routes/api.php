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
 */

Route::prefix('v1')->group(function () {

    // ---- Public -----------------------------------------------------------
    // Throttled: credential stuffing against an unlimited login endpoint is
    // the cheapest attack available against a token-based API.
    Route::post('auth/login',   [AuthController::class, 'login']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);

    if (! app()->environment('production')) {
        Route::post('auth/dev-token', [AuthController::class, 'devToken']);
    }

    // ---- Authenticated ----------------------------------------------------
    Route::middleware(['jwt', 'tenant'])->group(function () {

        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Foundation — Organization/Department/Person are read from the
        // institute ERP, not owned by the Brain (see ADR-006 / README).
        Route::get ('organizations/{tenantId}',      [OrganizationController::class, 'index']);
        Route::post('organizations',                 [OrganizationController::class, 'store'])->middleware('permission:tenant.manage');
        Route::get ('organizations/{tenantId}/{id}', [OrganizationController::class, 'show']);

        Route::get ('departments/{tenantId}',        [DepartmentController::class, 'index']);
        Route::post('departments',                   [DepartmentController::class, 'store']);
        Route::get ('departments/{tenantId}/{id}',   [DepartmentController::class, 'show']);

        Route::get ('people/{tenantId}/search',      [PersonController::class, 'search']);
        Route::get ('people/{tenantId}',             [PersonController::class, 'index']);
        Route::post('people',                        [PersonController::class, 'store']);
        Route::get ('people/{tenantId}/{id}',        [PersonController::class, 'show']);

        Route::get ('capabilities/{tenantId}',       [CapabilityController::class, 'index']);
        Route::post('capabilities',                  [CapabilityController::class, 'store']);
        Route::get ('capabilities/{tenantId}/{id}',  [CapabilityController::class, 'show']);

        // The Organizational Intelligence Loop, in stage order.
        Route::get ('signals/{tenantId}',            [SignalController::class, 'index']);
        Route::post('signals',                       [SignalController::class, 'store']);

        Route::get ('evidence/{tenantId}',           [EvidenceController::class, 'index']);
        Route::post('evidence',                      [EvidenceController::class, 'store']);

        Route::get ('cases/{tenantId}',              [CaseController::class, 'index']);
        Route::post('cases',                         [CaseController::class, 'store']);
        Route::patch('cases/{tenantId}/{id}/transition', [CaseController::class, 'transition']);
        Route::post('cases/{tenantId}/{id}/evidence',   [CaseController::class, 'attachEvidence']);

        Route::get ('hypotheses/{tenantId}/case/{caseId}', [HypothesisController::class, 'forCase']);
        Route::post('hypotheses',                    [HypothesisController::class, 'store']);
        Route::post('hypotheses/{tenantId}/{id}/status', [HypothesisController::class, 'setStatus']);

        Route::get ('reasoning/{tenantId}/signal/{signalId}', [ReasoningController::class, 'forSignal']);
        Route::post('reasoning',                     [ReasoningController::class, 'store']);

        Route::get ('recommendations/{tenantId}',    [RecommendationController::class, 'index']);
        Route::post('recommendations',               [RecommendationController::class, 'store']);

        Route::get ('decisions/{tenantId}',          [DecisionController::class, 'index']);
        Route::post('decisions',                     [DecisionController::class, 'store']);
        Route::post('decisions/{tenantId}/{id}/approve', [DecisionController::class, 'approve'])->middleware('permission:decision.approve');

        Route::get ('eso-executions/{tenantId}',     [EsoExecutionController::class, 'index']);
        Route::post('eso-executions',                [EsoExecutionController::class, 'store'])->middleware('permission:eso.execute');
        Route::patch('eso-executions/{tenantId}/{id}/transition', [EsoExecutionController::class, 'complete'])->middleware('permission:eso.execute');
        Route::post('eso-executions/{tenantId}/{id}/rollback',   [EsoExecutionController::class, 'rollback'])->middleware('permission:eso.execute');
        Route::get ('eso-executions/{tenantId}/eso/{esoId}',      [EsoExecutionController::class, 'history']);

        Route::get ('outcomes/{tenantId}',           [OutcomeController::class, 'index']);
        Route::post('outcomes',                      [OutcomeController::class, 'store']);

        Route::get ('learnings/{tenantId}/reusable', [LearningController::class, 'reusable']);
        Route::get ('learnings/{tenantId}',          [LearningController::class, 'index']);
        Route::post('learnings',                     [LearningController::class, 'store']);

        // Supporting surfaces
        Route::get ('kasba/assessment/{tenantId}/assignment/{assignmentId}/{capabilityId}', [KasbaController::class, 'assessment']);
        Route::post('kasba/proficiency',             [KasbaController::class, 'recordProficiency']);

        Route::get ('executors/{tenantId}',          [ExecutorController::class, 'index']);
        Route::post('executors',                     [ExecutorController::class, 'store']);

        Route::get ('policies/{tenantId}',           [PolicyController::class, 'index']);
        Route::post('policies',                      [PolicyController::class, 'store']);


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

        // ---- Notifications and settings -------------------------------------
        Route::get   ('notifications/{tenantId}',              [NotificationController::class, 'index']);
        Route::get   ('notifications/{tenantId}/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post  ('notifications/{tenantId}/read-all',     [NotificationController::class, 'markAllRead']);
        Route::patch ('notifications/{tenantId}/{id}/read',    [NotificationController::class, 'markRead']);

        Route::get('settings/{tenantId}', [SettingsController::class, 'index']);
        Route::put('settings/{tenantId}', [SettingsController::class, 'set'])->middleware('permission:settings.manage');

        // ---- Event backbone (ADR-002) ---------------------------------------
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
        Route::post('risks',                             [RiskController::class, 'assess']);
        Route::post('risks/{tenantId}/{id}/mitigate',    [RiskController::class, 'mitigate']);

        Route::get('mental-models/{tenantId}',                   [MentalModelController::class, 'index']);
        Route::get('mental-models/{tenantId}/domain/{domain}',   [MentalModelController::class, 'byDomain']);

        Route::get ('knowledge-library/{tenantId}/search',    [KnowledgeLibraryController::class, 'search']);
        Route::get ('knowledge-library/{tenantId}',           [KnowledgeLibraryController::class, 'index']);
        Route::post('knowledge-library',                      [KnowledgeLibraryController::class, 'store']);
        Route::post('knowledge-library/{tenantId}/{id}/reuse',[KnowledgeLibraryController::class, 'markReused']);

        // ---- Conversations ---------------------------------------------------
        Route::get   ('conversations/sessions/{tenantId}/search',        [ConversationController::class, 'search']);
        Route::get   ('conversations/sessions/{tenantId}',               [ConversationController::class, 'index']);
        Route::post  ('conversations/sessions',                          [ConversationController::class, 'store']);
        Route::get   ('conversations/sessions/{tenantId}/{id}/messages', [ConversationController::class, 'messages']);
        Route::post  ('conversations/sessions/{tenantId}/{id}/messages', [ConversationController::class, 'sendMessage']);
        Route::patch ('conversations/sessions/{tenantId}/{id}/pin',      [ConversationController::class, 'setPinned']);
        Route::patch ('conversations/sessions/{tenantId}/{id}/rename',   [ConversationController::class, 'rename']);
        Route::get   ('conversations/sessions/{tenantId}/{id}',          [ConversationController::class, 'show']);
        Route::delete('conversations/sessions/{tenantId}/{id}',          [ConversationController::class, 'destroy']);
        Route::get   ('conversations/prompt-templates/{tenantId}',       [ConversationController::class, 'promptTemplates']);
        Route::post  ('conversations/prompt-templates',                  [ConversationController::class, 'storePromptTemplate']);


        // ---- KASBA ----------------------------------------------------------
        Route::get ('kasba/heatmap/{tenantId}',                          [KasbaController::class, 'heatmap']);
        Route::get ('kasba/tasks/{tenantId}/capability/{capabilityId}',  [KasbaController::class, 'tasksForCapability']);
        Route::post('kasba/tasks',                                       [KasbaController::class, 'storeTask']);
        Route::get ('kasba/proficiency/{tenantId}/assignment/{assignmentId}/history', [KasbaController::class, 'proficiencyHistory']);
        Route::get ('kasba/proficiency/{tenantId}/assignment/{assignmentId}/trend',   [KasbaController::class, 'proficiencyTrend']);

        // ---- Capabilities ----------------------------------------------------
        Route::get  ('capabilities/{tenantId}/search',            [CapabilityController::class, 'search']);
        Route::patch('capabilities/{tenantId}/{id}',              [CapabilityController::class, 'update']);
        Route::post ('capabilities/{tenantId}/{id}/version',      [CapabilityController::class, 'createVersion']);
        Route::get  ('capabilities/{tenantId}/{id}/versions',     [CapabilityController::class, 'versions']);
        Route::post ('capabilities/{tenantId}/{id}/archive',      [CapabilityController::class, 'archive']);
        Route::post ('capabilities/{tenantId}/{id}/assign',       [CapabilityController::class, 'assign']);
        Route::get  ('capabilities/{tenantId}/{id}/assignments',  [CapabilityController::class, 'assignments']);
        Route::get  ('capabilities/{tenantId}/{id}/audit',        [CapabilityController::class, 'audit']);


        // ---- Search, chain, proactive reasoning, tasks ------------------------
        Route::get('search/{tenantId}',                        [SearchController::class, 'search']);
        Route::get('workspace/{tenantId}/signal/{signalId}/chain', [SearchController::class, 'signalChain']);

        Route::get('reasoning-engine/{tenantId}/missing-evidence',   [ReasoningEngineController::class, 'missingEvidence']);
        Route::get('reasoning-engine/{tenantId}/duplicate-signals',  [ReasoningEngineController::class, 'duplicateSignals']);
        Route::get('reasoning-engine/{tenantId}/early-warnings',     [ReasoningEngineController::class, 'earlyWarnings']);

        Route::get ('tasks/registry', [TaskController::class, 'registry']);
        Route::post('tasks/run',      [TaskController::class, 'run']);

        // ---- Signals, cases, hypotheses, policies -----------------------------
        Route::get  ('signals/{tenantId}/{id}',        [SignalController::class, 'show']);
        Route::patch('signals/{tenantId}/{id}/status', [SignalController::class, 'changeStatus']);

        Route::get('cases/{tenantId}/{id}',          [CaseController::class, 'show']);
        Route::get('cases/{tenantId}/{id}/evidence', [CaseController::class, 'evidence']);

        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/reject',  [HypothesisController::class, 'reject']);
        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/support', [HypothesisController::class, 'support']);
        Route::post('hypotheses/{tenantId}/case/{caseId}/{id}/confirm', [HypothesisController::class, 'confirm']);

        Route::get ('policies/{tenantId}/{id}/history',  [PolicyController::class, 'history']);
        Route::post('policies/{tenantId}/{id}/version',  [PolicyController::class, 'createVersion']);
        Route::post('policies/{tenantId}/{id}/evaluate', [PolicyController::class, 'evaluate']);
        Route::get ('policies/{tenantId}/{id}',          [PolicyController::class, 'show']);
        Route::post('policies',                          [PolicyController::class, 'store']);

        Route::get('evidence/{tenantId}/signal/{signalId}', [EvidenceController::class, 'forSignal']);

        // ---- ERP-entity lifecycle --------------------------------------------
        Route::patch('organizations/{tenantId}/{id}',         [OrganizationController::class, 'update']);
        Route::post ('organizations/{tenantId}/{id}/archive', [OrganizationController::class, 'archive']);
        Route::get  ('organizations/{tenantId}/{id}/audit',   [OrganizationController::class, 'audit']);

        Route::patch('departments/{tenantId}/{id}',         [DepartmentController::class, 'update']);
        Route::post ('departments/{tenantId}/{id}/archive', [DepartmentController::class, 'archive']);
        Route::get  ('departments/{tenantId}/{id}/audit',   [DepartmentController::class, 'audit']);
        Route::get  ('departments/{tenantId}/{id}/twin',    [DepartmentController::class, 'twin']);

        Route::patch('people/{tenantId}/{id}',         [PersonController::class, 'update']);
        Route::post ('people/{tenantId}/{id}/archive', [PersonController::class, 'archive']);
        Route::get  ('people/{tenantId}/{id}/audit',   [PersonController::class, 'audit']);
        Route::get  ('people/{tenantId}/{id}/twin',    [PersonController::class, 'twin']);


        // ---- AI (model-agnostic, ADR-004) and graph reads (ADR-008 seam) -----
        Route::get ('ai/providers',            [AiController::class, 'providers']);
        Route::get ('ai/executions/{tenantId}',[AiController::class, 'executions']);
        Route::post('ai/evidence/summarize',   [AiController::class, 'summarizeEvidence']);

        Route::get('graph/{tenantId}/search',                        [GraphController::class, 'search']);
        Route::get('graph/{tenantId}/entity/{label}/{id}/related',   [GraphController::class, 'related']);
        Route::get('graph/{tenantId}/entity/{label}/{id}',           [GraphController::class, 'entity']);

        Route::get ('workspace/{tenantId}',          [WorkspaceController::class, 'summary']);
    });
});
