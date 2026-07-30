/* eslint-disable */
/**
 * AUTO-GENERATED from openapi/hpbrain.openapi.yaml — DO NOT EDIT BY HAND.
 * Regenerate with: php artisan brain:openapi && npm run generate
 *
 * `unknown` marks a shape the API could not verify. Narrow it before use.
 */

export type AiSummarizeEvidencePostAiEvidenceSummarizeRequest = {
  signalId: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiController@summarizeEvidence returns a raw database row. */
export type AiSummarizeEvidencePostAiEvidenceSummarizeResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiController@executions returns a raw database row. */
export type AiExecutionsGetAiExecutionsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiController@providers returns a raw database row. */
export type AiProvidersGetAiProvidersResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@index returns a raw database row. */
export type AnalyticsIndexGetAnalyticsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@decisionIntelligence returns a raw database row. */
export type AnalyticsDecisionIntelligenceGetAnalyticsTenantIdDecisionIntelligenceResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@decisionsCsv returns a raw database row. */
export type AnalyticsDecisionsCsvGetAnalyticsTenantIdDecisionsExportCsvResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@executiveSummary returns a raw database row. */
export type AnalyticsExecutiveSummaryGetAnalyticsTenantIdExecutiveSummaryResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AuditController@index returns a raw database row. */
export type AuditIndexGetAuditResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AuditController@activity returns a raw database row. */
export type AuditActivityGetAuditActivityResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AuditController@stats returns a raw database row. */
export type AuditStatsGetAuditStatsResponse = unknown;

export type AuthChangePasswordPostAuthChangePasswordRequest = {
  currentPassword: string;
  newPassword: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AuthController@changePassword returns a raw database row. */
export type AuthChangePasswordPostAuthChangePasswordResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AuthController@devToken; body shape not derivable. */
export type AuthDevTokenPostAuthDevTokenRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AuthController@devToken returns a raw database row. */
export type AuthDevTokenPostAuthDevTokenResponse = unknown;

export type AuthLoginPostAuthLoginRequest = {
  tenantId: string;
  email: string;
  password: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AuthController@login returns a raw database row. */
export type AuthLoginPostAuthLoginResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AuthController@refresh; body shape not derivable. */
export type AuthRefreshPostAuthRefreshRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AuthController@refresh returns a raw database row. */
export type AuthRefreshPostAuthRefreshResponse = unknown;

export type CapabilityStorePostCapabilitiesRequest = {
  name: string;
  capabilityCode: string;
  orgId?: string;
  description?: string;
  category?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@store returns a raw database row. */
export type CapabilityStorePostCapabilitiesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@index returns a raw database row. */
export type CapabilityIndexGetCapabilitiesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@search returns a raw database row. */
export type CapabilitySearchGetCapabilitiesTenantIdSearchResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@show returns a raw database row. */
export type CapabilityShowGetCapabilitiesTenantIdIdResponse = unknown;
export type CapabilityShowGetCapabilitiesTenantIdIdError = 'capability_not_found';

export type CapabilityUpdatePatchCapabilitiesTenantIdIdRequest = {
  name?: string;
  description?: string;
  category?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@update returns a raw database row. */
export type CapabilityUpdatePatchCapabilitiesTenantIdIdResponse = unknown;
export type CapabilityUpdatePatchCapabilitiesTenantIdIdError = 'capability_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\CapabilityController@archive; body shape not derivable. */
export type CapabilityArchivePostCapabilitiesTenantIdIdArchiveRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@archive returns a raw database row. */
export type CapabilityArchivePostCapabilitiesTenantIdIdArchiveResponse = unknown;
export type CapabilityArchivePostCapabilitiesTenantIdIdArchiveError = 'capability_not_found';

export type CapabilityAssignPostCapabilitiesTenantIdIdAssignRequest = {
  targetType: string;
  targetId: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@assign returns a raw database row. */
export type CapabilityAssignPostCapabilitiesTenantIdIdAssignResponse = unknown;
export type CapabilityAssignPostCapabilitiesTenantIdIdAssignError = 'capability_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@assignments returns a raw database row. */
export type CapabilityAssignmentsGetCapabilitiesTenantIdIdAssignmentsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@audit returns a raw database row. */
export type CapabilityAuditGetCapabilitiesTenantIdIdAuditResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\CapabilityController@createVersion; body shape not derivable. */
export type CapabilityCreateVersionPostCapabilitiesTenantIdIdVersionRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@createVersion returns a raw database row. */
export type CapabilityCreateVersionPostCapabilitiesTenantIdIdVersionResponse = unknown;
export type CapabilityCreateVersionPostCapabilitiesTenantIdIdVersionError = 'capability_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\CapabilityController@versions returns a raw database row. */
export type CapabilityVersionsGetCapabilitiesTenantIdIdVersionsResponse = unknown;

export type CaseStorePostCasesRequest = {
  title: string;
  signalId?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\CaseController@store returns a raw database row. */
export type CaseStorePostCasesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CaseController@index returns a raw database row. */
export type CaseIndexGetCasesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CaseController@show returns a raw database row. */
export type CaseShowGetCasesTenantIdIdResponse = unknown;
export type CaseShowGetCasesTenantIdIdError = 'case_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\CaseController@attachEvidence; body shape not derivable. */
export type CaseAttachEvidencePostCasesTenantIdIdEvidenceRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\CaseController@attachEvidence returns a raw database row. */
export type CaseAttachEvidencePostCasesTenantIdIdEvidenceResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CaseController@evidence returns a raw database row. */
export type CaseEvidenceGetCasesTenantIdIdEvidenceResponse = unknown;

export type CaseTransitionPatchCasesTenantIdIdTransitionRequest = {
  status: string;
  resolvedHypothesisId?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\CaseController@transition returns a raw database row. */
export type CaseTransitionPatchCasesTenantIdIdTransitionResponse = unknown;
export type CaseTransitionPatchCasesTenantIdIdTransitionError = 'case_not_found';

export type ConversationStorePromptTemplatePostConversationsPromptTemplatesRequest = {
  name: string;
  template: string;
  version?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@storePromptTemplate returns a raw database row. */
export type ConversationStorePromptTemplatePostConversationsPromptTemplatesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@promptTemplates returns a raw database row. */
export type ConversationPromptTemplatesGetConversationsPromptTemplatesTenantIdResponse = unknown;

export type ConversationStorePostConversationsSessionsRequest = {
  title: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@store returns a raw database row. */
export type ConversationStorePostConversationsSessionsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@index returns a raw database row. */
export type ConversationIndexGetConversationsSessionsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@search returns a raw database row. */
export type ConversationSearchGetConversationsSessionsTenantIdSearchResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@show returns a raw database row. */
export type ConversationShowGetConversationsSessionsTenantIdIdResponse = unknown;
export type ConversationShowGetConversationsSessionsTenantIdIdError = 'session_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@destroy returns a raw database row. */
export type ConversationDestroyDeleteConversationsSessionsTenantIdIdResponse = unknown;
export type ConversationDestroyDeleteConversationsSessionsTenantIdIdError = 'session_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@messages returns a raw database row. */
export type ConversationMessagesGetConversationsSessionsTenantIdIdMessagesResponse = unknown;
export type ConversationMessagesGetConversationsSessionsTenantIdIdMessagesError = 'session_not_found';

export type ConversationSendMessagePostConversationsSessionsTenantIdIdMessagesRequest = {
  content: string;
  role?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@sendMessage returns a raw database row. */
export type ConversationSendMessagePostConversationsSessionsTenantIdIdMessagesResponse = unknown;
export type ConversationSendMessagePostConversationsSessionsTenantIdIdMessagesError = 'session_not_found';

export type ConversationSetPinnedPatchConversationsSessionsTenantIdIdPinRequest = {
  pinned: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@setPinned returns a raw database row. */
export type ConversationSetPinnedPatchConversationsSessionsTenantIdIdPinResponse = unknown;
export type ConversationSetPinnedPatchConversationsSessionsTenantIdIdPinError = 'session_not_found';

export type ConversationRenamePatchConversationsSessionsTenantIdIdRenameRequest = {
  title: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ConversationController@rename returns a raw database row. */
export type ConversationRenamePatchConversationsSessionsTenantIdIdRenameResponse = unknown;
export type ConversationRenamePatchConversationsSessionsTenantIdIdRenameError = 'session_not_found';

export type DecisionStorePostDecisionsRequest = {
  tenantId: string;
  recommendationId: string;
  rationale: string;
  executorType?: string;
  alternativesConsidered?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\DecisionController@store returns a raw database row. */
export type DecisionStorePostDecisionsResponse = unknown;
export type DecisionStorePostDecisionsError = 'recommendation_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\DecisionController@index returns a raw database row. */
export type DecisionIndexGetDecisionsTenantIdResponse = unknown;

export type DecisionApprovePostDecisionsTenantIdIdApproveRequest = {
  note?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\DecisionController@approve returns a raw database row. */
export type DecisionApprovePostDecisionsTenantIdIdApproveResponse = unknown;
export type DecisionApprovePostDecisionsTenantIdIdApproveError = 'decision_not_found' | 'decision_not_approvable' | 'self_approval_forbidden';

export type DepartmentStorePostDepartmentsRequest = {
  name: string;
  description?: string;
  parentId?: number;
  orgId: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\DepartmentController@store returns a raw database row. */
export type DepartmentStorePostDepartmentsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\DepartmentController@index returns a raw database row. */
export type DepartmentIndexGetDepartmentsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\DepartmentController@show returns a raw database row. */
export type DepartmentShowGetDepartmentsTenantIdIdResponse = unknown;
export type DepartmentShowGetDepartmentsTenantIdIdError = 'department_not_found';

export type DepartmentUpdatePatchDepartmentsTenantIdIdRequest = {
  name?: string;
  description?: string;
  parentId?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\DepartmentController@update returns a raw database row. */
export type DepartmentUpdatePatchDepartmentsTenantIdIdResponse = unknown;
export type DepartmentUpdatePatchDepartmentsTenantIdIdError = 'department_not_found' | 'no_fields_to_update';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\DepartmentController@archive; body shape not derivable. */
export type DepartmentArchivePostDepartmentsTenantIdIdArchiveRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\DepartmentController@archive returns a raw database row. */
export type DepartmentArchivePostDepartmentsTenantIdIdArchiveResponse = unknown;
export type DepartmentArchivePostDepartmentsTenantIdIdArchiveError = 'department_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\DepartmentController@audit returns a raw database row. */
export type DepartmentAuditGetDepartmentsTenantIdIdAuditResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\DepartmentController@twin returns a raw database row. */
export type DepartmentTwinGetDepartmentsTenantIdIdTwinResponse = unknown;
export type DepartmentTwinGetDepartmentsTenantIdIdTwinError = 'department_not_found';

export type EsoExecutionStorePostEsoExecutionsRequest = {
  decisionId: string;
  esoDefinitionId: string;
  executorType: string;
  executorId?: string;
  measurementPlan?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\EsoExecutionController@store returns a raw database row. */
export type EsoExecutionStorePostEsoExecutionsResponse = unknown;
export type EsoExecutionStorePostEsoExecutionsError = 'measurement_plan_required';

/** UNVERIFIED: App\Http\Controllers\Api\EsoExecutionController@index returns a raw database row. */
export type EsoExecutionIndexGetEsoExecutionsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EsoExecutionController@history returns a raw database row. */
export type EsoExecutionHistoryGetEsoExecutionsTenantIdEsoEsoIdResponse = unknown;

export type EsoExecutionRollbackPostEsoExecutionsTenantIdIdRollbackRequest = {
  reason: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\EsoExecutionController@rollback returns a raw database row. */
export type EsoExecutionRollbackPostEsoExecutionsTenantIdIdRollbackResponse = unknown;
export type EsoExecutionRollbackPostEsoExecutionsTenantIdIdRollbackError = 'eso_execution_not_found';

export type EsoExecutionCompletePatchEsoExecutionsTenantIdIdTransitionRequest = {
  status: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\EsoExecutionController@complete returns a raw database row. */
export type EsoExecutionCompletePatchEsoExecutionsTenantIdIdTransitionResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EventController@index returns a raw database row. */
export type EventIndexGetEventsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EventController@consumers returns a raw database row. */
export type EventConsumersGetEventsConsumersResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EventController@dlq returns a raw database row. */
export type EventDlqGetEventsDlqResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EventController@deleteDlq returns a raw database row. */
export type EventDeleteDlqDeleteEventsDlqIdResponse = unknown;
export type EventDeleteDlqDeleteEventsDlqIdError = 'dlq_entry_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\EventController@retryDlq; body shape not derivable. */
export type EventRetryDlqPostEventsDlqIdRetryRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\EventController@retryDlq returns a raw database row. */
export type EventRetryDlqPostEventsDlqIdRetryResponse = unknown;
export type EventRetryDlqPostEventsDlqIdRetryError = 'dlq_entry_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\EventController@retryFailed; body shape not derivable. */
export type EventRetryFailedPostEventsRetryFailedRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\EventController@retryFailed returns a raw database row. */
export type EventRetryFailedPostEventsRetryFailedResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EventController@stats returns a raw database row. */
export type EventStatsGetEventsStatsSummaryResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EventController@show returns a raw database row. */
export type EventShowGetEventsIdResponse = unknown;
export type EventShowGetEventsIdError = 'event_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\EventController@replay; body shape not derivable. */
export type EventReplayPostEventsIdReplayRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\EventController@replay returns a raw database row. */
export type EventReplayPostEventsIdReplayResponse = unknown;
export type EventReplayPostEventsIdReplayError = 'event_not_found';

export type EvidenceStorePostEvidenceRequest = {
  tenantId: string;
  signalId: string;
  source: string;
  evidenceType?: string;
  content: Array<unknown>;
  provenance: {
    source: string;
    ts: string;
    confidence: number;
  };
  confidence?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\EvidenceController@store returns a raw database row. */
export type EvidenceStorePostEvidenceResponse = unknown;
export type EvidenceStorePostEvidenceError = 'signal_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\EvidenceController@index returns a raw database row. */
export type EvidenceIndexGetEvidenceTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EvidenceController@forSignal returns a raw database row. */
export type EvidenceForSignalGetEvidenceTenantIdSignalSignalIdResponse = unknown;

export type ExecutorStorePostExecutorsRequest = {
  tenantId: string;
  executorType: string;
  name: string;
  personId?: string;
  capabilityTags?: Array<string>;
  trustLevel?: number;
  maxConcurrent?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\ExecutorController@store returns a raw database row. */
export type ExecutorStorePostExecutorsResponse = unknown;
export type ExecutorStorePostExecutorsError = 'person_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ExecutorController@index returns a raw database row. */
export type ExecutorIndexGetExecutorsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\GraphController@entity returns a raw database row. */
export type GraphEntityGetGraphTenantIdEntityLabelIdResponse = unknown;
export type GraphEntityGetGraphTenantIdEntityLabelIdError = 'entity_not_found' | 'unknown_label';

/** UNVERIFIED: App\Http\Controllers\Api\GraphController@related returns a raw database row. */
export type GraphRelatedGetGraphTenantIdEntityLabelIdRelatedResponse = unknown;
export type GraphRelatedGetGraphTenantIdEntityLabelIdRelatedError = 'unknown_label';

/** UNVERIFIED: App\Http\Controllers\Api\GraphController@search returns a raw database row. */
export type GraphSearchGetGraphTenantIdSearchResponse = unknown;

export type HypothesisStorePostHypothesesRequest = {
  caseId: string;
  statement: string;
  rootCauseFamily: string;
  confidence?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\HypothesisController@store returns a raw database row. */
export type HypothesisStorePostHypothesesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\HypothesisController@forCase returns a raw database row. */
export type HypothesisForCaseGetHypothesesTenantIdCaseCaseIdResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\HypothesisController@confirm; body shape not derivable. */
export type HypothesisConfirmPostHypothesesTenantIdCaseCaseIdIdConfirmRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\HypothesisController@confirm returns a raw database row. */
export type HypothesisConfirmPostHypothesesTenantIdCaseCaseIdIdConfirmResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\HypothesisController@reject; body shape not derivable. */
export type HypothesisRejectPostHypothesesTenantIdCaseCaseIdIdRejectRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\HypothesisController@reject returns a raw database row. */
export type HypothesisRejectPostHypothesesTenantIdCaseCaseIdIdRejectResponse = unknown;
export type HypothesisRejectPostHypothesesTenantIdCaseCaseIdIdRejectError = 'rejection_requires_reason';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\HypothesisController@support; body shape not derivable. */
export type HypothesisSupportPostHypothesesTenantIdCaseCaseIdIdSupportRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\HypothesisController@support returns a raw database row. */
export type HypothesisSupportPostHypothesesTenantIdCaseCaseIdIdSupportResponse = unknown;

export type HypothesisSetStatusPostHypothesesTenantIdIdStatusRequest = {
  status: string;
  rejectedReason?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\HypothesisController@setStatus returns a raw database row. */
export type HypothesisSetStatusPostHypothesesTenantIdIdStatusResponse = unknown;
export type HypothesisSetStatusPostHypothesesTenantIdIdStatusError = 'rejection_requires_reason';

/** UNVERIFIED: App\Http\Controllers\Api\KasbaController@assessment returns a raw database row. */
export type KasbaAssessmentGetKasbaAssessmentTenantIdAssignmentAssignmentIdCapabilityIdResponse = unknown;
export type KasbaAssessmentGetKasbaAssessmentTenantIdAssignmentAssignmentIdCapabilityIdError = 'capability_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\KasbaController@heatmap returns a raw database row. */
export type KasbaHeatmapGetKasbaHeatmapTenantIdResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\KasbaController@recordProficiency; body shape not derivable. */
export type KasbaRecordProficiencyPostKasbaProficiencyRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\KasbaController@recordProficiency returns a raw database row. */
export type KasbaRecordProficiencyPostKasbaProficiencyResponse = unknown;
export type KasbaRecordProficiencyPostKasbaProficiencyError = 'evidence_not_found' | 'capability_state_transition_rejected';

/** UNVERIFIED: App\Http\Controllers\Api\KasbaController@proficiencyHistory returns a raw database row. */
export type KasbaProficiencyHistoryGetKasbaProficiencyTenantIdAssignmentAssignmentIdHistoryResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\KasbaController@proficiencyTrend returns a raw database row. */
export type KasbaProficiencyTrendGetKasbaProficiencyTenantIdAssignmentAssignmentIdTrendResponse = unknown;

export type KasbaStoreTaskPostKasbaTasksRequest = {
  capabilityId: string;
  name: string;
  description?: string;
  evidenceRequired?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\KasbaController@storeTask returns a raw database row. */
export type KasbaStoreTaskPostKasbaTasksResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\KasbaController@tasksForCapability returns a raw database row. */
export type KasbaTasksForCapabilityGetKasbaTasksTenantIdCapabilityCapabilityIdResponse = unknown;

export type KnowledgeLibraryStorePostKnowledgeLibraryRequest = {
  title: string;
  content: string;
  category?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\KnowledgeLibraryController@store returns a raw database row. */
export type KnowledgeLibraryStorePostKnowledgeLibraryResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\KnowledgeLibraryController@index returns a raw database row. */
export type KnowledgeLibraryIndexGetKnowledgeLibraryTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\KnowledgeLibraryController@search returns a raw database row. */
export type KnowledgeLibrarySearchGetKnowledgeLibraryTenantIdSearchResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\KnowledgeLibraryController@markReused; body shape not derivable. */
export type KnowledgeLibraryMarkReusedPostKnowledgeLibraryTenantIdIdReuseRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\KnowledgeLibraryController@markReused returns a raw database row. */
export type KnowledgeLibraryMarkReusedPostKnowledgeLibraryTenantIdIdReuseResponse = unknown;
export type KnowledgeLibraryMarkReusedPostKnowledgeLibraryTenantIdIdReuseError = 'knowledge_asset_not_found';

export type LearningStorePostLearningsRequest = {
  outcomeId: string;
  pattern: string;
  description?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\LearningController@store returns a raw database row. */
export type LearningStorePostLearningsResponse = unknown;
export type LearningStorePostLearningsError = 'outcome_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\LearningController@index returns a raw database row. */
export type LearningIndexGetLearningsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\LearningController@reusable returns a raw database row. */
export type LearningReusableGetLearningsTenantIdReusableResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\MentalModelController@index returns a raw database row. */
export type MentalModelIndexGetMentalModelsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\MentalModelController@byDomain returns a raw database row. */
export type MentalModelByDomainGetMentalModelsTenantIdDomainDomainResponse = unknown;
export type MentalModelByDomainGetMentalModelsTenantIdDomainDomainError = 'mental_model_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\NotificationController@index returns a raw database row. */
export type NotificationIndexGetNotificationsTenantIdResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\NotificationController@markAllRead; body shape not derivable. */
export type NotificationMarkAllReadPostNotificationsTenantIdReadAllRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\NotificationController@markAllRead returns a raw database row. */
export type NotificationMarkAllReadPostNotificationsTenantIdReadAllResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\NotificationController@unreadCount returns a raw database row. */
export type NotificationUnreadCountGetNotificationsTenantIdUnreadCountResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\NotificationController@markRead; body shape not derivable. */
export type NotificationMarkReadPatchNotificationsTenantIdIdReadRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\NotificationController@markRead returns a raw database row. */
export type NotificationMarkReadPatchNotificationsTenantIdIdReadResponse = unknown;
export type NotificationMarkReadPatchNotificationsTenantIdIdReadError = 'notification_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ObservabilityController@health returns a raw database row. */
export type ObservabilityHealthGetObservabilityHealthResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ObservabilityController@database returns a raw database row. */
export type ObservabilityDatabaseGetObservabilityHealthDatabaseResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ObservabilityController@events returns a raw database row. */
export type ObservabilityEventsGetObservabilityHealthEventsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ObservabilityController@neo4j returns a raw database row. */
export type ObservabilityNeo4jGetObservabilityHealthNeo4jResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ObservabilityController@system returns a raw database row. */
export type ObservabilitySystemGetObservabilityHealthSystemResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ObservabilityController@systemMetrics returns a raw database row. */
export type ObservabilitySystemMetricsGetObservabilityMetricsSystemResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ObservabilityController@metrics returns a raw database row. */
export type ObservabilityMetricsGetObservabilityMetricsTenantIdResponse = unknown;

export type OrganizationStorePostOrganizationsRequest = {
  name: string;
  orgCode?: string;
  industry?: string;
  legalName?: string;
  logo?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@store returns a raw database row. */
export type OrganizationStorePostOrganizationsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@index returns a raw database row. */
export type OrganizationIndexGetOrganizationsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@show returns a raw database row. */
export type OrganizationShowGetOrganizationsTenantIdIdResponse = unknown;
export type OrganizationShowGetOrganizationsTenantIdIdError = 'organization_not_found';

export type OrganizationUpdatePatchOrganizationsTenantIdIdRequest = {
  name?: string;
  orgCode?: string;
  industry?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@update returns a raw database row. */
export type OrganizationUpdatePatchOrganizationsTenantIdIdResponse = unknown;
export type OrganizationUpdatePatchOrganizationsTenantIdIdError = 'organization_not_found' | 'no_fields_to_update';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\OrganizationController@archive; body shape not derivable. */
export type OrganizationArchivePostOrganizationsTenantIdIdArchiveRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@archive returns a raw database row. */
export type OrganizationArchivePostOrganizationsTenantIdIdArchiveResponse = unknown;
export type OrganizationArchivePostOrganizationsTenantIdIdArchiveError = 'organization_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@audit returns a raw database row. */
export type OrganizationAuditGetOrganizationsTenantIdIdAuditResponse = unknown;

export type OutcomeStorePostOutcomesRequest = {
  tenantId: string;
  decisionId: string;
  result: string;
  metrics: Array<unknown>;
  kpis?: Array<unknown>;
  evidenceIds: Array<string>;
  feedback?: string;
  confidence: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\OutcomeController@store returns a raw database row. */
export type OutcomeStorePostOutcomesResponse = unknown;
export type OutcomeStorePostOutcomesError = 'decision_not_approved' | 'evidence_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OutcomeController@index returns a raw database row. */
export type OutcomeIndexGetOutcomesTenantIdResponse = unknown;

export type PersonStorePostPeopleRequest = {
  employeeId: string;
  firstName: string;
  lastName: string;
  email: string;
  orgId: number;
  phone?: string;
  gender?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonController@store returns a raw database row. */
export type PersonStorePostPeopleResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonController@index returns a raw database row. */
export type PersonIndexGetPeopleTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonController@search returns a raw database row. */
export type PersonSearchGetPeopleTenantIdSearchResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonController@show returns a raw database row. */
export type PersonShowGetPeopleTenantIdIdResponse = unknown;
export type PersonShowGetPeopleTenantIdIdError = 'person_not_found';

export type PersonUpdatePatchPeopleTenantIdIdRequest = {
  firstName?: string;
  lastName?: string;
  email?: string;
  phone?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonController@update returns a raw database row. */
export type PersonUpdatePatchPeopleTenantIdIdResponse = unknown;
export type PersonUpdatePatchPeopleTenantIdIdError = 'person_not_found' | 'no_fields_to_update';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\PersonController@archive; body shape not derivable. */
export type PersonArchivePostPeopleTenantIdIdArchiveRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\PersonController@archive returns a raw database row. */
export type PersonArchivePostPeopleTenantIdIdArchiveResponse = unknown;
export type PersonArchivePostPeopleTenantIdIdArchiveError = 'person_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\PersonController@audit returns a raw database row. */
export type PersonAuditGetPeopleTenantIdIdAuditResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonController@twin returns a raw database row. */
export type PersonTwinGetPeopleTenantIdIdTwinResponse = unknown;
export type PersonTwinGetPeopleTenantIdIdTwinError = 'person_not_found';

export type PolicyStorePostPoliciesRequest = {
  name: string;
  rules: Array<unknown>;
  scope?: string;
  policyType?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\PolicyController@store returns a raw database row. */
export type PolicyStorePostPoliciesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PolicyController@index returns a raw database row. */
export type PolicyIndexGetPoliciesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PolicyController@show returns a raw database row. */
export type PolicyShowGetPoliciesTenantIdIdResponse = unknown;
export type PolicyShowGetPoliciesTenantIdIdError = 'policy_not_found';

export type PolicyEvaluatePostPoliciesTenantIdIdEvaluateRequest = {
  context: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\PolicyController@evaluate returns a raw database row. */
export type PolicyEvaluatePostPoliciesTenantIdIdEvaluateResponse = unknown;
export type PolicyEvaluatePostPoliciesTenantIdIdEvaluateError = 'policy_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\PolicyController@history returns a raw database row. */
export type PolicyHistoryGetPoliciesTenantIdIdHistoryResponse = unknown;

export type PolicyCreateVersionPostPoliciesTenantIdIdVersionRequest = {
  rules: Array<unknown>;
  scope?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\PolicyController@createVersion returns a raw database row. */
export type PolicyCreateVersionPostPoliciesTenantIdIdVersionResponse = unknown;
export type PolicyCreateVersionPostPoliciesTenantIdIdVersionError = 'policy_not_found';

export type ReasoningStorePostReasoningRequest = {
  signalId: string;
  description: string;
  caseId?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReasoningController@store returns a raw database row. */
export type ReasoningStorePostReasoningResponse = unknown;

export type ReasoningEngineAssessPostReasoningEngineTenantIdAssessRequest = {
  assignmentId: string;
  capabilityId: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@assess returns a raw database row. */
export type ReasoningEngineAssessPostReasoningEngineTenantIdAssessResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@duplicateSignals returns a raw database row. */
export type ReasoningEngineDuplicateSignalsGetReasoningEngineTenantIdDuplicateSignalsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@earlyWarnings returns a raw database row. */
export type ReasoningEngineEarlyWarningsGetReasoningEngineTenantIdEarlyWarningsResponse = unknown;

export type ReasoningEngineEvaluatePostReasoningEngineTenantIdEvaluateRequest = {
  signalId: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@evaluate returns a raw database row. */
export type ReasoningEngineEvaluatePostReasoningEngineTenantIdEvaluateResponse = unknown;

export type ReasoningEngineExplainPostReasoningEngineTenantIdExplainRequest = {
  signalId: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@explain returns a raw database row. */
export type ReasoningEngineExplainPostReasoningEngineTenantIdExplainResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@memoryStats returns a raw database row. */
export type ReasoningEngineMemoryStatsGetReasoningEngineTenantIdMemoryStatsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@missingEvidence returns a raw database row. */
export type ReasoningEngineMissingEvidenceGetReasoningEngineTenantIdMissingEvidenceResponse = unknown;

export type ReasoningEngineRecommendPostReasoningEngineTenantIdRecommendRequest = {
  signalId: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReasoningEngineController@recommend returns a raw database row. */
export type ReasoningEngineRecommendPostReasoningEngineTenantIdRecommendResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReasoningController@forSignal returns a raw database row. */
export type ReasoningForSignalGetReasoningTenantIdSignalSignalIdResponse = unknown;

export type RecommendationStorePostRecommendationsRequest = {
  tenantId: string;
  reasoningStepId: string;
  category: string;
  title: string;
  description?: string;
  priority: string;
  confidence: number;
  impact?: string;
  cost?: string;
  risk?: string;
  dependencies?: Array<unknown>;
  esoId?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\RecommendationController@store returns a raw database row. */
export type RecommendationStorePostRecommendationsResponse = unknown;
export type RecommendationStorePostRecommendationsError = 'reasoning_step_not_found' | 'eso_binding_required' | 'eso_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\RecommendationController@index returns a raw database row. */
export type RecommendationIndexGetRecommendationsTenantIdResponse = unknown;

export type RiskAssessPostRisksRequest = {
  title: string;
  category: string;
  impact: number;
  probability: number;
  description?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\RiskController@assess returns a raw database row. */
export type RiskAssessPostRisksResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\RiskController@index returns a raw database row. */
export type RiskIndexGetRisksTenantIdResponse = unknown;

export type RiskMitigatePostRisksTenantIdIdMitigateRequest = {
  mitigation: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\RiskController@mitigate returns a raw database row. */
export type RiskMitigatePostRisksTenantIdIdMitigateResponse = unknown;
export type RiskMitigatePostRisksTenantIdIdMitigateError = 'risk_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\SearchController@search returns a raw database row. */
export type SearchSearchGetSearchTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\SettingsController@index returns a raw database row. */
export type SettingsIndexGetSettingsTenantIdResponse = unknown;

export type SettingsSetPutSettingsTenantIdRequest = {
  scope?: string;
  key: string;
  value?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\SettingsController@set returns a raw database row. */
export type SettingsSetPutSettingsTenantIdResponse = unknown;

export type SignalStorePostSignalsRequest = {
  source: string;
  classification: string;
  priority?: string;
  severity?: string;
  confidence?: number;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\SignalController@store returns a raw database row. */
export type SignalStorePostSignalsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\SignalController@index returns a raw database row. */
export type SignalIndexGetSignalsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\SignalController@show returns a raw database row. */
export type SignalShowGetSignalsTenantIdIdResponse = unknown;
export type SignalShowGetSignalsTenantIdIdError = 'signal_not_found';

export type SignalChangeStatusPatchSignalsTenantIdIdStatusRequest = {
  status: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\SignalController@changeStatus returns a raw database row. */
export type SignalChangeStatusPatchSignalsTenantIdIdStatusResponse = unknown;
export type SignalChangeStatusPatchSignalsTenantIdIdStatusError = 'signal_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\TaskController@registry returns a raw database row. */
export type TaskRegistryGetTasksRegistryResponse = unknown;

export type TaskRunPostTasksRunRequest = {
  steps?: {
    *.taskName?: string;
    *.input?: Array<unknown>;
    *.maxRetries?: number;
  };
  tasks?: Array<string>;
  stopOnFailure?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\TaskController@run returns a raw database row. */
export type TaskRunPostTasksRunResponse = unknown;
export type TaskRunPostTasksRunError = 'no_steps' | 'unknown_tasks';

/** UNVERIFIED: App\Http\Controllers\Api\WorkspaceController@summary returns a raw database row. */
export type WorkspaceSummaryGetWorkspaceTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\SearchController@signalChain returns a raw database row. */
export type SearchSignalChainGetWorkspaceTenantIdSignalSignalIdChainResponse = unknown;
export type SearchSignalChainGetWorkspaceTenantIdSignalSignalIdChainError = 'signal_not_found';

/** Every operation the API exposes, for tooling. */
export const OPERATIONS = [
  {
    "name": "AiSummarizeEvidencePostAiEvidenceSummarize",
    "method": "POST",
    "path": "/ai/evidence/summarize",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiController@summarizeEvidence",
    "unverifiedResponse": true
  },
  {
    "name": "AiExecutionsGetAiExecutionsTenantId",
    "method": "GET",
    "path": "/ai/executions/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiController@executions",
    "unverifiedResponse": true
  },
  {
    "name": "AiProvidersGetAiProviders",
    "method": "GET",
    "path": "/ai/providers",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiController@providers",
    "unverifiedResponse": true
  },
  {
    "name": "AnalyticsIndexGetAnalyticsTenantId",
    "method": "GET",
    "path": "/analytics/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AnalyticsController@index",
    "unverifiedResponse": true
  },
  {
    "name": "AnalyticsDecisionIntelligenceGetAnalyticsTenantIdDecisionIntelligence",
    "method": "GET",
    "path": "/analytics/{tenantId}/decision-intelligence",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AnalyticsController@decisionIntelligence",
    "unverifiedResponse": true
  },
  {
    "name": "AnalyticsDecisionsCsvGetAnalyticsTenantIdDecisionsExportCsv",
    "method": "GET",
    "path": "/analytics/{tenantId}/decisions/export.csv",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AnalyticsController@decisionsCsv",
    "unverifiedResponse": true
  },
  {
    "name": "AnalyticsExecutiveSummaryGetAnalyticsTenantIdExecutiveSummary",
    "method": "GET",
    "path": "/analytics/{tenantId}/executive-summary",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AnalyticsController@executiveSummary",
    "unverifiedResponse": true
  },
  {
    "name": "AuditIndexGetAudit",
    "method": "GET",
    "path": "/audit",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AuditController@index",
    "unverifiedResponse": true
  },
  {
    "name": "AuditActivityGetAuditActivity",
    "method": "GET",
    "path": "/audit/activity",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AuditController@activity",
    "unverifiedResponse": true
  },
  {
    "name": "AuditStatsGetAuditStats",
    "method": "GET",
    "path": "/audit/stats",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AuditController@stats",
    "unverifiedResponse": true
  },
  {
    "name": "AuthChangePasswordPostAuthChangePassword",
    "method": "POST",
    "path": "/auth/change-password",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AuthController@changePassword",
    "unverifiedResponse": true
  },
  {
    "name": "AuthDevTokenPostAuthDevToken",
    "method": "POST",
    "path": "/auth/dev-token",
    "permissions": {},
    "controller": "App\\Http\\Controllers\\Api\\AuthController@devToken",
    "unverifiedResponse": true
  },
  {
    "name": "AuthLoginPostAuthLogin",
    "method": "POST",
    "path": "/auth/login",
    "permissions": {},
    "controller": "App\\Http\\Controllers\\Api\\AuthController@login",
    "unverifiedResponse": true
  },
  {
    "name": "AuthRefreshPostAuthRefresh",
    "method": "POST",
    "path": "/auth/refresh",
    "permissions": {},
    "controller": "App\\Http\\Controllers\\Api\\AuthController@refresh",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityStorePostCapabilities",
    "method": "POST",
    "path": "/capabilities",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@store",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityIndexGetCapabilitiesTenantId",
    "method": "GET",
    "path": "/capabilities/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@index",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilitySearchGetCapabilitiesTenantIdSearch",
    "method": "GET",
    "path": "/capabilities/{tenantId}/search",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@search",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityShowGetCapabilitiesTenantIdId",
    "method": "GET",
    "path": "/capabilities/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@show",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityUpdatePatchCapabilitiesTenantIdId",
    "method": "PATCH",
    "path": "/capabilities/{tenantId}/{id}",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@update",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityArchivePostCapabilitiesTenantIdIdArchive",
    "method": "POST",
    "path": "/capabilities/{tenantId}/{id}/archive",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@archive",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityAssignPostCapabilitiesTenantIdIdAssign",
    "method": "POST",
    "path": "/capabilities/{tenantId}/{id}/assign",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@assign",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityAssignmentsGetCapabilitiesTenantIdIdAssignments",
    "method": "GET",
    "path": "/capabilities/{tenantId}/{id}/assignments",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@assignments",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityAuditGetCapabilitiesTenantIdIdAudit",
    "method": "GET",
    "path": "/capabilities/{tenantId}/{id}/audit",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@audit",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityCreateVersionPostCapabilitiesTenantIdIdVersion",
    "method": "POST",
    "path": "/capabilities/{tenantId}/{id}/version",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@createVersion",
    "unverifiedResponse": true
  },
  {
    "name": "CapabilityVersionsGetCapabilitiesTenantIdIdVersions",
    "method": "GET",
    "path": "/capabilities/{tenantId}/{id}/versions",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CapabilityController@versions",
    "unverifiedResponse": true
  },
  {
    "name": "CaseStorePostCases",
    "method": "POST",
    "path": "/cases",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CaseController@store",
    "unverifiedResponse": true
  },
  {
    "name": "CaseIndexGetCasesTenantId",
    "method": "GET",
    "path": "/cases/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CaseController@index",
    "unverifiedResponse": true
  },
  {
    "name": "CaseShowGetCasesTenantIdId",
    "method": "GET",
    "path": "/cases/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CaseController@show",
    "unverifiedResponse": true
  },
  {
    "name": "CaseAttachEvidencePostCasesTenantIdIdEvidence",
    "method": "POST",
    "path": "/cases/{tenantId}/{id}/evidence",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CaseController@attachEvidence",
    "unverifiedResponse": true
  },
  {
    "name": "CaseEvidenceGetCasesTenantIdIdEvidence",
    "method": "GET",
    "path": "/cases/{tenantId}/{id}/evidence",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CaseController@evidence",
    "unverifiedResponse": true
  },
  {
    "name": "CaseTransitionPatchCasesTenantIdIdTransition",
    "method": "PATCH",
    "path": "/cases/{tenantId}/{id}/transition",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CaseController@transition",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationStorePromptTemplatePostConversationsPromptTemplates",
    "method": "POST",
    "path": "/conversations/prompt-templates",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@storePromptTemplate",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationPromptTemplatesGetConversationsPromptTemplatesTenantId",
    "method": "GET",
    "path": "/conversations/prompt-templates/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@promptTemplates",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationStorePostConversationsSessions",
    "method": "POST",
    "path": "/conversations/sessions",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationIndexGetConversationsSessionsTenantId",
    "method": "GET",
    "path": "/conversations/sessions/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@index",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationSearchGetConversationsSessionsTenantIdSearch",
    "method": "GET",
    "path": "/conversations/sessions/{tenantId}/search",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@search",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationShowGetConversationsSessionsTenantIdId",
    "method": "GET",
    "path": "/conversations/sessions/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@show",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationDestroyDeleteConversationsSessionsTenantIdId",
    "method": "DELETE",
    "path": "/conversations/sessions/{tenantId}/{id}",
    "permissions": [
      "read",
      "delete"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationMessagesGetConversationsSessionsTenantIdIdMessages",
    "method": "GET",
    "path": "/conversations/sessions/{tenantId}/{id}/messages",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@messages",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationSendMessagePostConversationsSessionsTenantIdIdMessages",
    "method": "POST",
    "path": "/conversations/sessions/{tenantId}/{id}/messages",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@sendMessage",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationSetPinnedPatchConversationsSessionsTenantIdIdPin",
    "method": "PATCH",
    "path": "/conversations/sessions/{tenantId}/{id}/pin",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@setPinned",
    "unverifiedResponse": true
  },
  {
    "name": "ConversationRenamePatchConversationsSessionsTenantIdIdRename",
    "method": "PATCH",
    "path": "/conversations/sessions/{tenantId}/{id}/rename",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConversationController@rename",
    "unverifiedResponse": true
  },
  {
    "name": "DecisionStorePostDecisions",
    "method": "POST",
    "path": "/decisions",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DecisionController@store",
    "unverifiedResponse": true
  },
  {
    "name": "DecisionIndexGetDecisionsTenantId",
    "method": "GET",
    "path": "/decisions/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DecisionController@index",
    "unverifiedResponse": true
  },
  {
    "name": "DecisionApprovePostDecisionsTenantIdIdApprove",
    "method": "POST",
    "path": "/decisions/{tenantId}/{id}/approve",
    "permissions": [
      "read",
      "decision.approve"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DecisionController@approve",
    "unverifiedResponse": true
  },
  {
    "name": "DepartmentStorePostDepartments",
    "method": "POST",
    "path": "/departments",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DepartmentController@store",
    "unverifiedResponse": true
  },
  {
    "name": "DepartmentIndexGetDepartmentsTenantId",
    "method": "GET",
    "path": "/departments/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DepartmentController@index",
    "unverifiedResponse": true
  },
  {
    "name": "DepartmentShowGetDepartmentsTenantIdId",
    "method": "GET",
    "path": "/departments/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DepartmentController@show",
    "unverifiedResponse": true
  },
  {
    "name": "DepartmentUpdatePatchDepartmentsTenantIdId",
    "method": "PATCH",
    "path": "/departments/{tenantId}/{id}",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DepartmentController@update",
    "unverifiedResponse": true
  },
  {
    "name": "DepartmentArchivePostDepartmentsTenantIdIdArchive",
    "method": "POST",
    "path": "/departments/{tenantId}/{id}/archive",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DepartmentController@archive",
    "unverifiedResponse": true
  },
  {
    "name": "DepartmentAuditGetDepartmentsTenantIdIdAudit",
    "method": "GET",
    "path": "/departments/{tenantId}/{id}/audit",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DepartmentController@audit",
    "unverifiedResponse": true
  },
  {
    "name": "DepartmentTwinGetDepartmentsTenantIdIdTwin",
    "method": "GET",
    "path": "/departments/{tenantId}/{id}/twin",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DepartmentController@twin",
    "unverifiedResponse": true
  },
  {
    "name": "EsoExecutionStorePostEsoExecutions",
    "method": "POST",
    "path": "/eso-executions",
    "permissions": [
      "read",
      "eso.execute"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EsoExecutionController@store",
    "unverifiedResponse": true
  },
  {
    "name": "EsoExecutionIndexGetEsoExecutionsTenantId",
    "method": "GET",
    "path": "/eso-executions/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EsoExecutionController@index",
    "unverifiedResponse": true
  },
  {
    "name": "EsoExecutionHistoryGetEsoExecutionsTenantIdEsoEsoId",
    "method": "GET",
    "path": "/eso-executions/{tenantId}/eso/{esoId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EsoExecutionController@history",
    "unverifiedResponse": true
  },
  {
    "name": "EsoExecutionRollbackPostEsoExecutionsTenantIdIdRollback",
    "method": "POST",
    "path": "/eso-executions/{tenantId}/{id}/rollback",
    "permissions": [
      "read",
      "eso.execute"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EsoExecutionController@rollback",
    "unverifiedResponse": true
  },
  {
    "name": "EsoExecutionCompletePatchEsoExecutionsTenantIdIdTransition",
    "method": "PATCH",
    "path": "/eso-executions/{tenantId}/{id}/transition",
    "permissions": [
      "read",
      "eso.execute"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EsoExecutionController@complete",
    "unverifiedResponse": true
  },
  {
    "name": "EventIndexGetEvents",
    "method": "GET",
    "path": "/events",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@index",
    "unverifiedResponse": true
  },
  {
    "name": "EventConsumersGetEventsConsumers",
    "method": "GET",
    "path": "/events/consumers",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@consumers",
    "unverifiedResponse": true
  },
  {
    "name": "EventDlqGetEventsDlq",
    "method": "GET",
    "path": "/events/dlq",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@dlq",
    "unverifiedResponse": true
  },
  {
    "name": "EventDeleteDlqDeleteEventsDlqId",
    "method": "DELETE",
    "path": "/events/dlq/{id}",
    "permissions": [
      "read",
      "events.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@deleteDlq",
    "unverifiedResponse": true
  },
  {
    "name": "EventRetryDlqPostEventsDlqIdRetry",
    "method": "POST",
    "path": "/events/dlq/{id}/retry",
    "permissions": [
      "read",
      "events.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@retryDlq",
    "unverifiedResponse": true
  },
  {
    "name": "EventRetryFailedPostEventsRetryFailed",
    "method": "POST",
    "path": "/events/retry/failed",
    "permissions": [
      "read",
      "events.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@retryFailed",
    "unverifiedResponse": true
  },
  {
    "name": "EventStatsGetEventsStatsSummary",
    "method": "GET",
    "path": "/events/stats/summary",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@stats",
    "unverifiedResponse": true
  },
  {
    "name": "EventShowGetEventsId",
    "method": "GET",
    "path": "/events/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@show",
    "unverifiedResponse": true
  },
  {
    "name": "EventReplayPostEventsIdReplay",
    "method": "POST",
    "path": "/events/{id}/replay",
    "permissions": [
      "read",
      "events.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EventController@replay",
    "unverifiedResponse": true
  },
  {
    "name": "EvidenceStorePostEvidence",
    "method": "POST",
    "path": "/evidence",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EvidenceController@store",
    "unverifiedResponse": true
  },
  {
    "name": "EvidenceIndexGetEvidenceTenantId",
    "method": "GET",
    "path": "/evidence/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EvidenceController@index",
    "unverifiedResponse": true
  },
  {
    "name": "EvidenceForSignalGetEvidenceTenantIdSignalSignalId",
    "method": "GET",
    "path": "/evidence/{tenantId}/signal/{signalId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EvidenceController@forSignal",
    "unverifiedResponse": true
  },
  {
    "name": "ExecutorStorePostExecutors",
    "method": "POST",
    "path": "/executors",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ExecutorController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ExecutorIndexGetExecutorsTenantId",
    "method": "GET",
    "path": "/executors/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ExecutorController@index",
    "unverifiedResponse": true
  },
  {
    "name": "GraphEntityGetGraphTenantIdEntityLabelId",
    "method": "GET",
    "path": "/graph/{tenantId}/entity/{label}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\GraphController@entity",
    "unverifiedResponse": true
  },
  {
    "name": "GraphRelatedGetGraphTenantIdEntityLabelIdRelated",
    "method": "GET",
    "path": "/graph/{tenantId}/entity/{label}/{id}/related",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\GraphController@related",
    "unverifiedResponse": true
  },
  {
    "name": "GraphSearchGetGraphTenantIdSearch",
    "method": "GET",
    "path": "/graph/{tenantId}/search",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\GraphController@search",
    "unverifiedResponse": true
  },
  {
    "name": "HypothesisStorePostHypotheses",
    "method": "POST",
    "path": "/hypotheses",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\HypothesisController@store",
    "unverifiedResponse": true
  },
  {
    "name": "HypothesisForCaseGetHypothesesTenantIdCaseCaseId",
    "method": "GET",
    "path": "/hypotheses/{tenantId}/case/{caseId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\HypothesisController@forCase",
    "unverifiedResponse": true
  },
  {
    "name": "HypothesisConfirmPostHypothesesTenantIdCaseCaseIdIdConfirm",
    "method": "POST",
    "path": "/hypotheses/{tenantId}/case/{caseId}/{id}/confirm",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\HypothesisController@confirm",
    "unverifiedResponse": true
  },
  {
    "name": "HypothesisRejectPostHypothesesTenantIdCaseCaseIdIdReject",
    "method": "POST",
    "path": "/hypotheses/{tenantId}/case/{caseId}/{id}/reject",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\HypothesisController@reject",
    "unverifiedResponse": true
  },
  {
    "name": "HypothesisSupportPostHypothesesTenantIdCaseCaseIdIdSupport",
    "method": "POST",
    "path": "/hypotheses/{tenantId}/case/{caseId}/{id}/support",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\HypothesisController@support",
    "unverifiedResponse": true
  },
  {
    "name": "HypothesisSetStatusPostHypothesesTenantIdIdStatus",
    "method": "POST",
    "path": "/hypotheses/{tenantId}/{id}/status",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\HypothesisController@setStatus",
    "unverifiedResponse": true
  },
  {
    "name": "KasbaAssessmentGetKasbaAssessmentTenantIdAssignmentAssignmentIdCapabilityId",
    "method": "GET",
    "path": "/kasba/assessment/{tenantId}/assignment/{assignmentId}/{capabilityId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KasbaController@assessment",
    "unverifiedResponse": true
  },
  {
    "name": "KasbaHeatmapGetKasbaHeatmapTenantId",
    "method": "GET",
    "path": "/kasba/heatmap/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KasbaController@heatmap",
    "unverifiedResponse": true
  },
  {
    "name": "KasbaRecordProficiencyPostKasbaProficiency",
    "method": "POST",
    "path": "/kasba/proficiency",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KasbaController@recordProficiency",
    "unverifiedResponse": true
  },
  {
    "name": "KasbaProficiencyHistoryGetKasbaProficiencyTenantIdAssignmentAssignmentIdHistory",
    "method": "GET",
    "path": "/kasba/proficiency/{tenantId}/assignment/{assignmentId}/history",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KasbaController@proficiencyHistory",
    "unverifiedResponse": true
  },
  {
    "name": "KasbaProficiencyTrendGetKasbaProficiencyTenantIdAssignmentAssignmentIdTrend",
    "method": "GET",
    "path": "/kasba/proficiency/{tenantId}/assignment/{assignmentId}/trend",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KasbaController@proficiencyTrend",
    "unverifiedResponse": true
  },
  {
    "name": "KasbaStoreTaskPostKasbaTasks",
    "method": "POST",
    "path": "/kasba/tasks",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KasbaController@storeTask",
    "unverifiedResponse": true
  },
  {
    "name": "KasbaTasksForCapabilityGetKasbaTasksTenantIdCapabilityCapabilityId",
    "method": "GET",
    "path": "/kasba/tasks/{tenantId}/capability/{capabilityId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KasbaController@tasksForCapability",
    "unverifiedResponse": true
  },
  {
    "name": "KnowledgeLibraryStorePostKnowledgeLibrary",
    "method": "POST",
    "path": "/knowledge-library",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KnowledgeLibraryController@store",
    "unverifiedResponse": true
  },
  {
    "name": "KnowledgeLibraryIndexGetKnowledgeLibraryTenantId",
    "method": "GET",
    "path": "/knowledge-library/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KnowledgeLibraryController@index",
    "unverifiedResponse": true
  },
  {
    "name": "KnowledgeLibrarySearchGetKnowledgeLibraryTenantIdSearch",
    "method": "GET",
    "path": "/knowledge-library/{tenantId}/search",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KnowledgeLibraryController@search",
    "unverifiedResponse": true
  },
  {
    "name": "KnowledgeLibraryMarkReusedPostKnowledgeLibraryTenantIdIdReuse",
    "method": "POST",
    "path": "/knowledge-library/{tenantId}/{id}/reuse",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\KnowledgeLibraryController@markReused",
    "unverifiedResponse": true
  },
  {
    "name": "LearningStorePostLearnings",
    "method": "POST",
    "path": "/learnings",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LearningController@store",
    "unverifiedResponse": true
  },
  {
    "name": "LearningIndexGetLearningsTenantId",
    "method": "GET",
    "path": "/learnings/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LearningController@index",
    "unverifiedResponse": true
  },
  {
    "name": "LearningReusableGetLearningsTenantIdReusable",
    "method": "GET",
    "path": "/learnings/{tenantId}/reusable",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LearningController@reusable",
    "unverifiedResponse": true
  },
  {
    "name": "MentalModelIndexGetMentalModelsTenantId",
    "method": "GET",
    "path": "/mental-models/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\MentalModelController@index",
    "unverifiedResponse": true
  },
  {
    "name": "MentalModelByDomainGetMentalModelsTenantIdDomainDomain",
    "method": "GET",
    "path": "/mental-models/{tenantId}/domain/{domain}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\MentalModelController@byDomain",
    "unverifiedResponse": true
  },
  {
    "name": "NotificationIndexGetNotificationsTenantId",
    "method": "GET",
    "path": "/notifications/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NotificationController@index",
    "unverifiedResponse": true
  },
  {
    "name": "NotificationMarkAllReadPostNotificationsTenantIdReadAll",
    "method": "POST",
    "path": "/notifications/{tenantId}/read-all",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NotificationController@markAllRead",
    "unverifiedResponse": true
  },
  {
    "name": "NotificationUnreadCountGetNotificationsTenantIdUnreadCount",
    "method": "GET",
    "path": "/notifications/{tenantId}/unread-count",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NotificationController@unreadCount",
    "unverifiedResponse": true
  },
  {
    "name": "NotificationMarkReadPatchNotificationsTenantIdIdRead",
    "method": "PATCH",
    "path": "/notifications/{tenantId}/{id}/read",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NotificationController@markRead",
    "unverifiedResponse": true
  },
  {
    "name": "ObservabilityHealthGetObservabilityHealth",
    "method": "GET",
    "path": "/observability/health",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ObservabilityController@health",
    "unverifiedResponse": true
  },
  {
    "name": "ObservabilityDatabaseGetObservabilityHealthDatabase",
    "method": "GET",
    "path": "/observability/health/database",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ObservabilityController@database",
    "unverifiedResponse": true
  },
  {
    "name": "ObservabilityEventsGetObservabilityHealthEvents",
    "method": "GET",
    "path": "/observability/health/events",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ObservabilityController@events",
    "unverifiedResponse": true
  },
  {
    "name": "ObservabilityNeo4jGetObservabilityHealthNeo4j",
    "method": "GET",
    "path": "/observability/health/neo4j",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ObservabilityController@neo4j",
    "unverifiedResponse": true
  },
  {
    "name": "ObservabilitySystemGetObservabilityHealthSystem",
    "method": "GET",
    "path": "/observability/health/system",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ObservabilityController@system",
    "unverifiedResponse": true
  },
  {
    "name": "ObservabilitySystemMetricsGetObservabilityMetricsSystem",
    "method": "GET",
    "path": "/observability/metrics/system",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ObservabilityController@systemMetrics",
    "unverifiedResponse": true
  },
  {
    "name": "ObservabilityMetricsGetObservabilityMetricsTenantId",
    "method": "GET",
    "path": "/observability/metrics/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ObservabilityController@metrics",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationStorePostOrganizations",
    "method": "POST",
    "path": "/organizations",
    "permissions": [
      "read",
      "tenant.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@store",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationIndexGetOrganizationsTenantId",
    "method": "GET",
    "path": "/organizations/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@index",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationShowGetOrganizationsTenantIdId",
    "method": "GET",
    "path": "/organizations/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@show",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUpdatePatchOrganizationsTenantIdId",
    "method": "PATCH",
    "path": "/organizations/{tenantId}/{id}",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@update",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationArchivePostOrganizationsTenantIdIdArchive",
    "method": "POST",
    "path": "/organizations/{tenantId}/{id}/archive",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@archive",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationAuditGetOrganizationsTenantIdIdAudit",
    "method": "GET",
    "path": "/organizations/{tenantId}/{id}/audit",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@audit",
    "unverifiedResponse": true
  },
  {
    "name": "OutcomeStorePostOutcomes",
    "method": "POST",
    "path": "/outcomes",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OutcomeController@store",
    "unverifiedResponse": true
  },
  {
    "name": "OutcomeIndexGetOutcomesTenantId",
    "method": "GET",
    "path": "/outcomes/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OutcomeController@index",
    "unverifiedResponse": true
  },
  {
    "name": "PersonStorePostPeople",
    "method": "POST",
    "path": "/people",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@store",
    "unverifiedResponse": true
  },
  {
    "name": "PersonIndexGetPeopleTenantId",
    "method": "GET",
    "path": "/people/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@index",
    "unverifiedResponse": true
  },
  {
    "name": "PersonSearchGetPeopleTenantIdSearch",
    "method": "GET",
    "path": "/people/{tenantId}/search",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@search",
    "unverifiedResponse": true
  },
  {
    "name": "PersonShowGetPeopleTenantIdId",
    "method": "GET",
    "path": "/people/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@show",
    "unverifiedResponse": true
  },
  {
    "name": "PersonUpdatePatchPeopleTenantIdId",
    "method": "PATCH",
    "path": "/people/{tenantId}/{id}",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@update",
    "unverifiedResponse": true
  },
  {
    "name": "PersonArchivePostPeopleTenantIdIdArchive",
    "method": "POST",
    "path": "/people/{tenantId}/{id}/archive",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@archive",
    "unverifiedResponse": true
  },
  {
    "name": "PersonAuditGetPeopleTenantIdIdAudit",
    "method": "GET",
    "path": "/people/{tenantId}/{id}/audit",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@audit",
    "unverifiedResponse": true
  },
  {
    "name": "PersonTwinGetPeopleTenantIdIdTwin",
    "method": "GET",
    "path": "/people/{tenantId}/{id}/twin",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonController@twin",
    "unverifiedResponse": true
  },
  {
    "name": "PolicyStorePostPolicies",
    "method": "POST",
    "path": "/policies",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PolicyController@store",
    "unverifiedResponse": true
  },
  {
    "name": "PolicyIndexGetPoliciesTenantId",
    "method": "GET",
    "path": "/policies/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PolicyController@index",
    "unverifiedResponse": true
  },
  {
    "name": "PolicyShowGetPoliciesTenantIdId",
    "method": "GET",
    "path": "/policies/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PolicyController@show",
    "unverifiedResponse": true
  },
  {
    "name": "PolicyEvaluatePostPoliciesTenantIdIdEvaluate",
    "method": "POST",
    "path": "/policies/{tenantId}/{id}/evaluate",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PolicyController@evaluate",
    "unverifiedResponse": true
  },
  {
    "name": "PolicyHistoryGetPoliciesTenantIdIdHistory",
    "method": "GET",
    "path": "/policies/{tenantId}/{id}/history",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PolicyController@history",
    "unverifiedResponse": true
  },
  {
    "name": "PolicyCreateVersionPostPoliciesTenantIdIdVersion",
    "method": "POST",
    "path": "/policies/{tenantId}/{id}/version",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PolicyController@createVersion",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningStorePostReasoning",
    "method": "POST",
    "path": "/reasoning",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineAssessPostReasoningEngineTenantIdAssess",
    "method": "POST",
    "path": "/reasoning-engine/{tenantId}/assess",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@assess",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineDuplicateSignalsGetReasoningEngineTenantIdDuplicateSignals",
    "method": "GET",
    "path": "/reasoning-engine/{tenantId}/duplicate-signals",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@duplicateSignals",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineEarlyWarningsGetReasoningEngineTenantIdEarlyWarnings",
    "method": "GET",
    "path": "/reasoning-engine/{tenantId}/early-warnings",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@earlyWarnings",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineEvaluatePostReasoningEngineTenantIdEvaluate",
    "method": "POST",
    "path": "/reasoning-engine/{tenantId}/evaluate",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@evaluate",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineExplainPostReasoningEngineTenantIdExplain",
    "method": "POST",
    "path": "/reasoning-engine/{tenantId}/explain",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@explain",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineMemoryStatsGetReasoningEngineTenantIdMemoryStats",
    "method": "GET",
    "path": "/reasoning-engine/{tenantId}/memory-stats",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@memoryStats",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineMissingEvidenceGetReasoningEngineTenantIdMissingEvidence",
    "method": "GET",
    "path": "/reasoning-engine/{tenantId}/missing-evidence",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@missingEvidence",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningEngineRecommendPostReasoningEngineTenantIdRecommend",
    "method": "POST",
    "path": "/reasoning-engine/{tenantId}/recommend",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningEngineController@recommend",
    "unverifiedResponse": true
  },
  {
    "name": "ReasoningForSignalGetReasoningTenantIdSignalSignalId",
    "method": "GET",
    "path": "/reasoning/{tenantId}/signal/{signalId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReasoningController@forSignal",
    "unverifiedResponse": true
  },
  {
    "name": "RecommendationStorePostRecommendations",
    "method": "POST",
    "path": "/recommendations",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RecommendationController@store",
    "unverifiedResponse": true
  },
  {
    "name": "RecommendationIndexGetRecommendationsTenantId",
    "method": "GET",
    "path": "/recommendations/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RecommendationController@index",
    "unverifiedResponse": true
  },
  {
    "name": "RiskAssessPostRisks",
    "method": "POST",
    "path": "/risks",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RiskController@assess",
    "unverifiedResponse": true
  },
  {
    "name": "RiskIndexGetRisksTenantId",
    "method": "GET",
    "path": "/risks/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RiskController@index",
    "unverifiedResponse": true
  },
  {
    "name": "RiskMitigatePostRisksTenantIdIdMitigate",
    "method": "POST",
    "path": "/risks/{tenantId}/{id}/mitigate",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RiskController@mitigate",
    "unverifiedResponse": true
  },
  {
    "name": "SearchSearchGetSearchTenantId",
    "method": "GET",
    "path": "/search/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SearchController@search",
    "unverifiedResponse": true
  },
  {
    "name": "SettingsIndexGetSettingsTenantId",
    "method": "GET",
    "path": "/settings/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SettingsController@index",
    "unverifiedResponse": true
  },
  {
    "name": "SettingsSetPutSettingsTenantId",
    "method": "PUT",
    "path": "/settings/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SettingsController@set",
    "unverifiedResponse": true
  },
  {
    "name": "SignalStorePostSignals",
    "method": "POST",
    "path": "/signals",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SignalController@store",
    "unverifiedResponse": true
  },
  {
    "name": "SignalIndexGetSignalsTenantId",
    "method": "GET",
    "path": "/signals/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SignalController@index",
    "unverifiedResponse": true
  },
  {
    "name": "SignalShowGetSignalsTenantIdId",
    "method": "GET",
    "path": "/signals/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SignalController@show",
    "unverifiedResponse": true
  },
  {
    "name": "SignalChangeStatusPatchSignalsTenantIdIdStatus",
    "method": "PATCH",
    "path": "/signals/{tenantId}/{id}/status",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SignalController@changeStatus",
    "unverifiedResponse": true
  },
  {
    "name": "TaskRegistryGetTasksRegistry",
    "method": "GET",
    "path": "/tasks/registry",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TaskController@registry",
    "unverifiedResponse": true
  },
  {
    "name": "TaskRunPostTasksRun",
    "method": "POST",
    "path": "/tasks/run",
    "permissions": [
      "read",
      "update"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TaskController@run",
    "unverifiedResponse": true
  },
  {
    "name": "WorkspaceSummaryGetWorkspaceTenantId",
    "method": "GET",
    "path": "/workspace/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\WorkspaceController@summary",
    "unverifiedResponse": true
  },
  {
    "name": "SearchSignalChainGetWorkspaceTenantIdSignalSignalIdChain",
    "method": "GET",
    "path": "/workspace/{tenantId}/signal/{signalId}/chain",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SearchController@signalChain",
    "unverifiedResponse": true
  }
] as const;
