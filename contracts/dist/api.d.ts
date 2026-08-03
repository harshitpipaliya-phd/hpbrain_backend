/* eslint-disable */
/**
 * AUTO-GENERATED from openapi/hpbrain.openapi.yaml — DO NOT EDIT BY HAND.
 * Regenerate with: php artisan brain:openapi && npm run generate
 *
 * `unknown` marks a shape the API could not verify. Narrow it before use.
 */

export type AiEvaluationStorePostAiEvaluationsRequest = {
  evaluation_name: string;
  evaluation_type: string;
  dataset?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiEvaluationController@store returns a raw database row. */
export type AiEvaluationStorePostAiEvaluationsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiEvaluationController@index returns a raw database row. */
export type AiEvaluationIndexGetAiEvaluationsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiEvaluationController@show returns a raw database row. */
export type AiEvaluationShowGetAiEvaluationsTenantIdIdResponse = unknown;
export type AiEvaluationShowGetAiEvaluationsTenantIdIdError = 'evaluation_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\AiEvaluationController@results returns a raw database row. */
export type AiEvaluationResultsGetAiEvaluationsTenantIdIdResultsResponse = unknown;
export type AiEvaluationResultsGetAiEvaluationsTenantIdIdResultsError = 'evaluation_not_found';

export type AiEvaluationRunPostAiEvaluationsTenantIdIdRunRequest = {
  model?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiEvaluationController@run returns a raw database row. */
export type AiEvaluationRunPostAiEvaluationsTenantIdIdRunResponse = unknown;
export type AiEvaluationRunPostAiEvaluationsTenantIdIdRunError = 'evaluation_not_found';

export type AiSummarizeEvidencePostAiEvidenceSummarizeRequest = {
  signalId: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiController@summarizeEvidence returns a raw database row. */
export type AiSummarizeEvidencePostAiEvidenceSummarizeResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiController@executions returns a raw database row. */
export type AiExecutionsGetAiExecutionsTenantIdResponse = unknown;

export type AiFeedbackStorePostAiFeedbackRequest = {
  execution_id: string;
  rating: string;
  feedback_text?: string;
  feedback_type?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiFeedbackController@store returns a raw database row. */
export type AiFeedbackStorePostAiFeedbackResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiFeedbackController@index returns a raw database row. */
export type AiFeedbackIndexGetAiFeedbackTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiFeedbackController@show returns a raw database row. */
export type AiFeedbackShowGetAiFeedbackTenantIdIdResponse = unknown;
export type AiFeedbackShowGetAiFeedbackTenantIdIdError = 'feedback_not_found';

export type AiPromptTemplateStorePostAiPromptTemplatesRequest = {
  prompt_key: string;
  version: number;
  name: string;
  description?: string;
  purpose?: string;
  system_prompt: string;
  user_prompt_template: string;
  response_schema?: Array<unknown>;
  allowed_roles?: Array<unknown>;
  data_sources?: Array<unknown>;
  model_capability?: string;
  generation_settings?: Array<unknown>;
  safety_profile?: string;
  status?: string;
  change_summary?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiPromptTemplateController@store returns a raw database row. */
export type AiPromptTemplateStorePostAiPromptTemplatesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiPromptTemplateController@index returns a raw database row. */
export type AiPromptTemplateIndexGetAiPromptTemplatesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiPromptTemplateController@show returns a raw database row. */
export type AiPromptTemplateShowGetAiPromptTemplatesTenantIdIdResponse = unknown;
export type AiPromptTemplateShowGetAiPromptTemplatesTenantIdIdError = 'prompt_template_not_found';

export type AiPromptTemplateUpdatePatchAiPromptTemplatesTenantIdIdRequest = {
  name?: string;
  description?: string;
  status?: string;
  change_summary?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiPromptTemplateController@update returns a raw database row. */
export type AiPromptTemplateUpdatePatchAiPromptTemplatesTenantIdIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiPromptTemplateController@destroy returns a raw database row. */
export type AiPromptTemplateDestroyDeleteAiPromptTemplatesTenantIdIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiPromptTemplateController@render returns a raw database row. */
export type AiPromptTemplateRenderGetAiPromptTemplatesTenantIdIdRenderResponse = unknown;
export type AiPromptTemplateRenderGetAiPromptTemplatesTenantIdIdRenderError = 'prompt_template_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\AiPromptTemplateController@versions returns a raw database row. */
export type AiPromptTemplateVersionsGetAiPromptTemplatesTenantIdIdVersionsResponse = unknown;
export type AiPromptTemplateVersionsGetAiPromptTemplatesTenantIdIdVersionsError = 'prompt_template_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\AiController@providers returns a raw database row. */
export type AiProvidersGetAiProvidersResponse = unknown;

export type AiProviderStorePostAiProvidersRequest = {
  provider_name: string;
  provider_type: string;
  config?: Array<unknown>;
  priority?: number;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiProviderController@store returns a raw database row. */
export type AiProviderStorePostAiProvidersResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiProviderController@index returns a raw database row. */
export type AiProviderIndexGetAiProvidersTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiProviderController@show returns a raw database row. */
export type AiProviderShowGetAiProvidersTenantIdIdResponse = unknown;
export type AiProviderShowGetAiProvidersTenantIdIdError = 'provider_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AiProviderController@update; body shape not derivable. */
export type AiProviderUpdatePatchAiProvidersTenantIdIdRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AiProviderController@update returns a raw database row. */
export type AiProviderUpdatePatchAiProvidersTenantIdIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiProviderController@destroy returns a raw database row. */
export type AiProviderDestroyDeleteAiProvidersTenantIdIdResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AiProviderController@setActive; body shape not derivable. */
export type AiProviderSetActivePostAiProvidersTenantIdIdActivateRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AiProviderController@setActive returns a raw database row. */
export type AiProviderSetActivePostAiProvidersTenantIdIdActivateResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AiProviderController@test; body shape not derivable. */
export type AiProviderTestPostAiProvidersTenantIdIdTestRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AiProviderController@test returns a raw database row. */
export type AiProviderTestPostAiProvidersTenantIdIdTestResponse = unknown;

export type AiQuotaStorePostAiQuotasRequest = {
  quota_type: string;
  quota_key: string;
  limit_value: number;
  reset_period?: string;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiQuotaController@store returns a raw database row. */
export type AiQuotaStorePostAiQuotasResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiQuotaController@index returns a raw database row. */
export type AiQuotaIndexGetAiQuotasTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiQuotaController@show returns a raw database row. */
export type AiQuotaShowGetAiQuotasTenantIdIdResponse = unknown;
export type AiQuotaShowGetAiQuotasTenantIdIdError = 'quota_not_found';

export type AiQuotaUpdatePatchAiQuotasTenantIdIdRequest = {
  limit_value?: number;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiQuotaController@update returns a raw database row. */
export type AiQuotaUpdatePatchAiQuotasTenantIdIdResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AiQuotaController@reset; body shape not derivable. */
export type AiQuotaResetPostAiQuotasTenantIdIdResetRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AiQuotaController@reset returns a raw database row. */
export type AiQuotaResetPostAiQuotasTenantIdIdResetResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@sessions returns a raw database row. */
export type AiWorkspaceSessionsGetAiWorkspaceSessionsResponse = unknown;

export type AiWorkspaceStorePostAiWorkspaceSessionsRequest = {
  title: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@store returns a raw database row. */
export type AiWorkspaceStorePostAiWorkspaceSessionsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@history returns a raw database row. */
export type AiWorkspaceHistoryGetAiWorkspaceSessionsSessionIdHistoryResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@messages returns a raw database row. */
export type AiWorkspaceMessagesGetAiWorkspaceSessionsSessionIdMessagesResponse = unknown;

export type AiWorkspaceSendPostAiWorkspaceSessionsSessionIdMessagesRequest = {
  message: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@send returns a raw database row. */
export type AiWorkspaceSendPostAiWorkspaceSessionsSessionIdMessagesResponse = unknown;
export type AiWorkspaceSendPostAiWorkspaceSessionsSessionIdMessagesError = 'session_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AiWorkspaceController@explain; body shape not derivable. */
export type AiWorkspaceExplainPostAiWorkspaceSessionsSessionIdMessagesMessageIdExplainRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@explain returns a raw database row. */
export type AiWorkspaceExplainPostAiWorkspaceSessionsSessionIdMessagesMessageIdExplainResponse = unknown;
export type AiWorkspaceExplainPostAiWorkspaceSessionsSessionIdMessagesMessageIdExplainError = 'message_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@followUp returns a raw database row. */
export type AiWorkspaceFollowUpGetAiWorkspaceSessionsSessionIdMessagesMessageIdFollowUpResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AiWorkspaceController@regenerate; body shape not derivable. */
export type AiWorkspaceRegeneratePostAiWorkspaceSessionsSessionIdMessagesMessageIdRegenerateRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AiWorkspaceController@regenerate returns a raw database row. */
export type AiWorkspaceRegeneratePostAiWorkspaceSessionsSessionIdMessagesMessageIdRegenerateResponse = unknown;
export type AiWorkspaceRegeneratePostAiWorkspaceSessionsSessionIdMessagesMessageIdRegenerateError = 'message_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@index returns a raw database row. */
export type AnalyticsIndexGetAnalyticsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@decisionIntelligence returns a raw database row. */
export type AnalyticsDecisionIntelligenceGetAnalyticsTenantIdDecisionIntelligenceResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@decisionsCsv returns a raw database row. */
export type AnalyticsDecisionsCsvGetAnalyticsTenantIdDecisionsExportCsvResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@executiveSummary returns a raw database row. */
export type AnalyticsExecutiveSummaryGetAnalyticsTenantIdExecutiveSummaryResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@intelligenceReport returns a raw database row. */
export type AnalyticsIntelligenceReportGetAnalyticsTenantIdReportsIntelligenceResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@organizationReport returns a raw database row. */
export type AnalyticsOrganizationReportGetAnalyticsTenantIdReportsOrganizationResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\AnalyticsController@peopleReport returns a raw database row. */
export type AnalyticsPeopleReportGetAnalyticsTenantIdReportsPeopleResponse = unknown;

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

export type AuthLoginPostAuthLoginRequest = {
  email: string;
  password: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\AuthController@login returns a raw database row. */
export type AuthLoginPostAuthLoginResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AuthController@logout; body shape not derivable. */
export type AuthLogoutPostAuthLogoutRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AuthController@logout returns a raw database row. */
export type AuthLogoutPostAuthLogoutResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\AuthController@refresh; body shape not derivable. */
export type AuthRefreshPostAuthRefreshRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\AuthController@refresh returns a raw database row. */
export type AuthRefreshPostAuthRefreshResponse = unknown;

export type BrandingStorePostBrandingRequest = {
  org_id: string;
  name?: string;
  logo_url?: string;
  favicon_url?: string;
  primary_color?: string;
  secondary_color?: string;
  accent_color?: string;
  font_family?: string;
  login_background_url?: string;
  email_header_url?: string;
  custom_css?: string;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\BrandingController@store returns a raw database row. */
export type BrandingStorePostBrandingResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\BrandingController@index returns a raw database row. */
export type BrandingIndexGetBrandingTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\BrandingController@show returns a raw database row. */
export type BrandingShowGetBrandingTenantIdIdResponse = unknown;
export type BrandingShowGetBrandingTenantIdIdError = 'branding_not_found';

export type BrandingUpdatePatchBrandingTenantIdIdRequest = {
  name?: string;
  logo_url?: string;
  favicon_url?: string;
  primary_color?: string;
  secondary_color?: string;
  accent_color?: string;
  font_family?: string;
  login_background_url?: string;
  email_header_url?: string;
  custom_css?: string;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\BrandingController@update returns a raw database row. */
export type BrandingUpdatePatchBrandingTenantIdIdResponse = unknown;
export type BrandingUpdatePatchBrandingTenantIdIdError = 'branding_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\BrandingController@destroy returns a raw database row. */
export type BrandingDestroyDeleteBrandingTenantIdIdResponse = unknown;
export type BrandingDestroyDeleteBrandingTenantIdIdError = 'branding_not_found';

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

export type CompetencyStorePostCompetenciesRequest = {
  competency_key: string;
  name: string;
  description?: string;
  category?: string;
  framework?: string;
  level_descriptors?: Array<unknown>;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\CompetencyController@store returns a raw database row. */
export type CompetencyStorePostCompetenciesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CompetencyController@index returns a raw database row. */
export type CompetencyIndexGetCompetenciesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\CompetencyController@show returns a raw database row. */
export type CompetencyShowGetCompetenciesTenantIdIdResponse = unknown;
export type CompetencyShowGetCompetenciesTenantIdIdError = 'competency_not_found';

export type CompetencyUpdatePatchCompetenciesTenantIdIdRequest = {
  competency_key?: string;
  name?: string;
  description?: string;
  category?: string;
  framework?: string;
  level_descriptors?: Array<unknown>;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\CompetencyController@update returns a raw database row. */
export type CompetencyUpdatePatchCompetenciesTenantIdIdResponse = unknown;
export type CompetencyUpdatePatchCompetenciesTenantIdIdError = 'competency_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\CompetencyController@destroy returns a raw database row. */
export type CompetencyDestroyDeleteCompetenciesTenantIdIdResponse = unknown;
export type CompetencyDestroyDeleteCompetenciesTenantIdIdError = 'competency_not_found';

export type ConfigVersionStorePostConfigVersionsRequest = {
  org_id: string;
  config_type: string;
  config_key: string;
  version?: number;
  data?: Array<unknown>;
  status?: string;
  change_summary?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ConfigVersionController@store returns a raw database row. */
export type ConfigVersionStorePostConfigVersionsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ConfigVersionController@index returns a raw database row. */
export type ConfigVersionIndexGetConfigVersionsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ConfigVersionController@show returns a raw database row. */
export type ConfigVersionShowGetConfigVersionsTenantIdIdResponse = unknown;
export type ConfigVersionShowGetConfigVersionsTenantIdIdError = 'config_version_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\ConfigVersionController@activate; body shape not derivable. */
export type ConfigVersionActivatePostConfigVersionsTenantIdIdActivateRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\ConfigVersionController@activate returns a raw database row. */
export type ConfigVersionActivatePostConfigVersionsTenantIdIdActivateResponse = unknown;
export type ConfigVersionActivatePostConfigVersionsTenantIdIdActivateError = 'config_version_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\ConfigVersionController@rollback; body shape not derivable. */
export type ConfigVersionRollbackPostConfigVersionsTenantIdIdRollbackRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\ConfigVersionController@rollback returns a raw database row. */
export type ConfigVersionRollbackPostConfigVersionsTenantIdIdRollbackResponse = unknown;
export type ConfigVersionRollbackPostConfigVersionsTenantIdIdRollbackError = 'config_version_not_found';

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

export type DashboardWidgetStorePostDashboardWidgetsRequest = {
  widget_key: string;
  name: string;
  description?: string;
  category?: string;
  component_type: string;
  config_schema?: Array<unknown>;
  default_config?: Array<unknown>;
  icon?: string;
  is_system?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\DashboardWidgetController@store returns a raw database row. */
export type DashboardWidgetStorePostDashboardWidgetsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\DashboardWidgetController@index returns a raw database row. */
export type DashboardWidgetIndexGetDashboardWidgetsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\DashboardWidgetController@show returns a raw database row. */
export type DashboardWidgetShowGetDashboardWidgetsTenantIdIdResponse = unknown;
export type DashboardWidgetShowGetDashboardWidgetsTenantIdIdError = 'widget_not_found';

export type DashboardWidgetUpdatePatchDashboardWidgetsTenantIdIdRequest = {
  widget_key?: string;
  name?: string;
  description?: string;
  category?: string;
  component_type?: string;
  config_schema?: Array<unknown>;
  default_config?: Array<unknown>;
  icon?: string;
  is_system?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\DashboardWidgetController@update returns a raw database row. */
export type DashboardWidgetUpdatePatchDashboardWidgetsTenantIdIdResponse = unknown;
export type DashboardWidgetUpdatePatchDashboardWidgetsTenantIdIdError = 'widget_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\DashboardWidgetController@destroy returns a raw database row. */
export type DashboardWidgetDestroyDeleteDashboardWidgetsTenantIdIdResponse = unknown;
export type DashboardWidgetDestroyDeleteDashboardWidgetsTenantIdIdError = 'widget_not_found';

export type DashboardStorePostDashboardsRequest = {
  org_id?: string;
  dashboard_key: string;
  name: string;
  description?: string;
  industry_code?: string;
  role_key?: string;
  is_default?: boolean;
  is_system?: boolean;
  layout?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\DashboardController@store returns a raw database row. */
export type DashboardStorePostDashboardsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\DashboardController@index returns a raw database row. */
export type DashboardIndexGetDashboardsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\DashboardController@show returns a raw database row. */
export type DashboardShowGetDashboardsTenantIdIdResponse = unknown;
export type DashboardShowGetDashboardsTenantIdIdError = 'dashboard_not_found';

export type DashboardUpdatePatchDashboardsTenantIdIdRequest = {
  dashboard_key?: string;
  name?: string;
  description?: string;
  industry_code?: string;
  role_key?: string;
  is_default?: boolean;
  is_system?: boolean;
  layout?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\DashboardController@update returns a raw database row. */
export type DashboardUpdatePatchDashboardsTenantIdIdResponse = unknown;
export type DashboardUpdatePatchDashboardsTenantIdIdError = 'dashboard_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\DashboardController@destroy returns a raw database row. */
export type DashboardDestroyDeleteDashboardsTenantIdIdResponse = unknown;
export type DashboardDestroyDeleteDashboardsTenantIdIdError = 'dashboard_not_found';

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

export type EntityMappingStorePostEntityMappingsRequest = {
  source_system: string;
  source_entity: string;
  source_field: string;
  universal_entity: string;
  universal_field: string;
  mapping_type?: string;
  transform_expression?: string;
  lookup_table?: string;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\EntityMappingController@store returns a raw database row. */
export type EntityMappingStorePostEntityMappingsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EntityMappingController@index returns a raw database row. */
export type EntityMappingIndexGetEntityMappingsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\EntityMappingController@show returns a raw database row. */
export type EntityMappingShowGetEntityMappingsTenantIdIdResponse = unknown;
export type EntityMappingShowGetEntityMappingsTenantIdIdError = 'mapping_not_found';

export type EntityMappingUpdatePatchEntityMappingsTenantIdIdRequest = {
  source_system?: string;
  source_entity?: string;
  source_field?: string;
  universal_entity?: string;
  universal_field?: string;
  mapping_type?: string;
  transform_expression?: string;
  lookup_table?: string;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\EntityMappingController@update returns a raw database row. */
export type EntityMappingUpdatePatchEntityMappingsTenantIdIdResponse = unknown;
export type EntityMappingUpdatePatchEntityMappingsTenantIdIdError = 'mapping_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\EntityMappingController@destroy returns a raw database row. */
export type EntityMappingDestroyDeleteEntityMappingsTenantIdIdResponse = unknown;
export type EntityMappingDestroyDeleteEntityMappingsTenantIdIdError = 'mapping_not_found';

export type EsoExecutionStorePostEsoExecutionsRequest = {
  decisionId: string;
  esoDefinitionId: string;
  executorType: string;
  executorId?: string;
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

export type FeatureFlagStorePostFeatureFlagsRequest = {
  flag_key: string;
  flag_name: string;
  description?: string;
  enabled?: boolean;
  level?: string;
  level_id?: string;
  rollout_percentage?: number;
  rules?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\FeatureFlagController@store returns a raw database row. */
export type FeatureFlagStorePostFeatureFlagsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\FeatureFlagController@index returns a raw database row. */
export type FeatureFlagIndexGetFeatureFlagsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\FeatureFlagController@show returns a raw database row. */
export type FeatureFlagShowGetFeatureFlagsTenantIdIdResponse = unknown;
export type FeatureFlagShowGetFeatureFlagsTenantIdIdError = 'feature_flag_not_found';

export type FeatureFlagUpdatePatchFeatureFlagsTenantIdIdRequest = {
  flag_key?: string;
  flag_name?: string;
  description?: string;
  enabled?: boolean;
  level?: string;
  level_id?: string;
  rollout_percentage?: number;
  rules?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\FeatureFlagController@update returns a raw database row. */
export type FeatureFlagUpdatePatchFeatureFlagsTenantIdIdResponse = unknown;
export type FeatureFlagUpdatePatchFeatureFlagsTenantIdIdError = 'feature_flag_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\FeatureFlagController@destroy returns a raw database row. */
export type FeatureFlagDestroyDeleteFeatureFlagsTenantIdIdResponse = unknown;
export type FeatureFlagDestroyDeleteFeatureFlagsTenantIdIdError = 'feature_flag_not_found';

export type FormStorePostFormsRequest = {
  org_id: string;
  form_key: string;
  name: string;
  description?: string;
  entity_type?: string;
  fields?: Array<unknown>;
  validation_rules?: Array<unknown>;
  submit_action?: string;
  is_active?: boolean;
  version?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\FormController@store returns a raw database row. */
export type FormStorePostFormsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\FormController@index returns a raw database row. */
export type FormIndexGetFormsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\FormController@show returns a raw database row. */
export type FormShowGetFormsTenantIdIdResponse = unknown;
export type FormShowGetFormsTenantIdIdError = 'form_not_found';

export type FormUpdatePatchFormsTenantIdIdRequest = {
  form_key?: string;
  name?: string;
  description?: string;
  entity_type?: string;
  fields?: Array<unknown>;
  validation_rules?: Array<unknown>;
  submit_action?: string;
  is_active?: boolean;
  version?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\FormController@update returns a raw database row. */
export type FormUpdatePatchFormsTenantIdIdResponse = unknown;
export type FormUpdatePatchFormsTenantIdIdError = 'form_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\FormController@destroy returns a raw database row. */
export type FormDestroyDeleteFormsTenantIdIdResponse = unknown;
export type FormDestroyDeleteFormsTenantIdIdError = 'form_not_found';

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

export type ImportStartPostImportsRequest = {
  rows: Array<unknown>;
  entity_type: string;
  org_id?: string;
  import_type?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ImportController@start returns a raw database row. */
export type ImportStartPostImportsResponse = unknown;

export type ImportDetectDuplicatesPostImportsDetectDuplicatesRequest = {
  rows: Array<unknown>;
  entity_type: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ImportController@detectDuplicates returns a raw database row. */
export type ImportDetectDuplicatesPostImportsDetectDuplicatesResponse = unknown;

export type ImportPreviewPostImportsPreviewRequest = {
  file_path: string;
  entity_type: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ImportController@preview returns a raw database row. */
export type ImportPreviewPostImportsPreviewResponse = unknown;

export type ImportValidatePostImportsValidateRequest = {
  file_path: string;
  entity_type: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\ImportController@validate returns a raw database row. */
export type ImportValidatePostImportsValidateResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ImportController@index returns a raw database row. */
export type ImportIndexGetImportsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ImportController@show returns a raw database row. */
export type ImportShowGetImportsTenantIdIdResponse = unknown;
export type ImportShowGetImportsTenantIdIdError = 'import_job_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ImportController@logs returns a raw database row. */
export type ImportLogsGetImportsTenantIdIdLogsResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\ImportController@process; body shape not derivable. */
export type ImportProcessPostImportsTenantIdIdProcessRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\ImportController@process returns a raw database row. */
export type ImportProcessPostImportsTenantIdIdProcessResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\ImportController@rollback; body shape not derivable. */
export type ImportRollbackPostImportsTenantIdIdRollbackRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\ImportController@rollback returns a raw database row. */
export type ImportRollbackPostImportsTenantIdIdRollbackResponse = unknown;
export type ImportRollbackPostImportsTenantIdIdRollbackError = 'import_job_not_found';

export type IndustryStorePostIndustriesRequest = {
  code: string;
  name: string;
  description?: string;
  icon?: string;
  sort_order?: number;
  status?: string;
  settings?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\IndustryController@store returns a raw database row. */
export type IndustryStorePostIndustriesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\IndustryController@index returns a raw database row. */
export type IndustryIndexGetIndustriesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\IndustryController@show returns a raw database row. */
export type IndustryShowGetIndustriesTenantIdIdResponse = unknown;
export type IndustryShowGetIndustriesTenantIdIdError = 'industry_not_found';

export type IndustryUpdatePatchIndustriesTenantIdIdRequest = {
  code?: string;
  name?: string;
  description?: string;
  icon?: string;
  sort_order?: number;
  status?: string;
  settings?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\IndustryController@update returns a raw database row. */
export type IndustryUpdatePatchIndustriesTenantIdIdResponse = unknown;
export type IndustryUpdatePatchIndustriesTenantIdIdError = 'industry_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\IndustryController@destroy returns a raw database row. */
export type IndustryDestroyDeleteIndustriesTenantIdIdResponse = unknown;
export type IndustryDestroyDeleteIndustriesTenantIdIdError = 'industry_not_found';

export type IndustryTemplateStorePostIndustryTemplatesRequest = {
  industry_code: string;
  template_name: string;
  description?: string;
  terminology?: Array<unknown>;
  modules?: Array<unknown>;
  navigation?: Array<unknown>;
  dashboards?: Array<unknown>;
  branding?: Array<unknown>;
  workflows?: Array<unknown>;
  integrations?: Array<unknown>;
  is_system?: boolean;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\IndustryTemplateController@store returns a raw database row. */
export type IndustryTemplateStorePostIndustryTemplatesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\IndustryTemplateController@index returns a raw database row. */
export type IndustryTemplateIndexGetIndustryTemplatesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\IndustryTemplateController@show returns a raw database row. */
export type IndustryTemplateShowGetIndustryTemplatesTenantIdIdResponse = unknown;
export type IndustryTemplateShowGetIndustryTemplatesTenantIdIdError = 'industry_template_not_found';

export type IndustryTemplateUpdatePatchIndustryTemplatesTenantIdIdRequest = {
  industry_code?: string;
  template_name?: string;
  description?: string;
  terminology?: Array<unknown>;
  modules?: Array<unknown>;
  navigation?: Array<unknown>;
  dashboards?: Array<unknown>;
  branding?: Array<unknown>;
  workflows?: Array<unknown>;
  integrations?: Array<unknown>;
  is_system?: boolean;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\IndustryTemplateController@update returns a raw database row. */
export type IndustryTemplateUpdatePatchIndustryTemplatesTenantIdIdResponse = unknown;
export type IndustryTemplateUpdatePatchIndustryTemplatesTenantIdIdError = 'industry_template_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\IndustryTemplateController@destroy returns a raw database row. */
export type IndustryTemplateDestroyDeleteIndustryTemplatesTenantIdIdResponse = unknown;
export type IndustryTemplateDestroyDeleteIndustryTemplatesTenantIdIdError = 'industry_template_not_found';

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

export type LocationTypeStorePostLocationTypesRequest = {
  type_key: string;
  name: string;
  description?: string;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\LocationTypeController@store returns a raw database row. */
export type LocationTypeStorePostLocationTypesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\LocationTypeController@index returns a raw database row. */
export type LocationTypeIndexGetLocationTypesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\LocationTypeController@show returns a raw database row. */
export type LocationTypeShowGetLocationTypesTenantIdIdResponse = unknown;
export type LocationTypeShowGetLocationTypesTenantIdIdError = 'location_type_not_found';

export type LocationTypeUpdatePatchLocationTypesTenantIdIdRequest = {
  type_key?: string;
  name?: string;
  description?: string;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\LocationTypeController@update returns a raw database row. */
export type LocationTypeUpdatePatchLocationTypesTenantIdIdResponse = unknown;
export type LocationTypeUpdatePatchLocationTypesTenantIdIdError = 'location_type_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\LocationTypeController@destroy returns a raw database row. */
export type LocationTypeDestroyDeleteLocationTypesTenantIdIdResponse = unknown;
export type LocationTypeDestroyDeleteLocationTypesTenantIdIdError = 'location_type_not_found';

export type LocationStorePostLocationsRequest = {
  org_id?: string;
  location_type_id?: string;
  name: string;
  address?: string;
  city?: string;
  state?: string;
  country?: string;
  postal_code?: string;
  timezone?: string;
  phone?: string;
  email?: string;
  metadata?: Array<unknown>;
  is_headquarters?: boolean;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\LocationController@store returns a raw database row. */
export type LocationStorePostLocationsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\LocationController@index returns a raw database row. */
export type LocationIndexGetLocationsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\LocationController@show returns a raw database row. */
export type LocationShowGetLocationsTenantIdIdResponse = unknown;
export type LocationShowGetLocationsTenantIdIdError = 'location_not_found';

export type LocationUpdatePatchLocationsTenantIdIdRequest = {
  org_id?: string;
  location_type_id?: string;
  name?: string;
  address?: string;
  city?: string;
  state?: string;
  country?: string;
  postal_code?: string;
  timezone?: string;
  phone?: string;
  email?: string;
  metadata?: Array<unknown>;
  is_headquarters?: boolean;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\LocationController@update returns a raw database row. */
export type LocationUpdatePatchLocationsTenantIdIdResponse = unknown;
export type LocationUpdatePatchLocationsTenantIdIdError = 'location_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\LocationController@destroy returns a raw database row. */
export type LocationDestroyDeleteLocationsTenantIdIdResponse = unknown;
export type LocationDestroyDeleteLocationsTenantIdIdError = 'location_not_found';

export type MeasurementPlanStorePostMeasurementPlansRequest = {
  decisionId: string;
  baselineMetric: string;
  baselineValue?: number;
  targetValue?: number;
  metricUnit?: string;
  measurementWindowDays?: number;
  ownerId?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\MeasurementPlanController@store returns a raw database row. */
export type MeasurementPlanStorePostMeasurementPlansResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\MentalModelController@index returns a raw database row. */
export type MentalModelIndexGetMentalModelsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\MentalModelController@byDomain returns a raw database row. */
export type MentalModelByDomainGetMentalModelsTenantIdDomainDomainResponse = unknown;
export type MentalModelByDomainGetMentalModelsTenantIdDomainDomainError = 'mental_model_not_found';

export type ModuleStorePostModulesRequest = {
  module_key: string;
  name: string;
  description?: string;
  version?: string;
  category?: string;
  is_core?: boolean;
  is_enabled?: boolean;
  dependencies?: Array<unknown>;
  config_schema?: Array<unknown>;
  sort_order?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\ModuleController@store returns a raw database row. */
export type ModuleStorePostModulesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ModuleController@index returns a raw database row. */
export type ModuleIndexGetModulesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ModuleController@show returns a raw database row. */
export type ModuleShowGetModulesTenantIdIdResponse = unknown;
export type ModuleShowGetModulesTenantIdIdError = 'module_not_found';

export type ModuleUpdatePatchModulesTenantIdIdRequest = {
  module_key?: string;
  name?: string;
  description?: string;
  version?: string;
  category?: string;
  is_core?: boolean;
  is_enabled?: boolean;
  dependencies?: Array<unknown>;
  config_schema?: Array<unknown>;
  sort_order?: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\ModuleController@update returns a raw database row. */
export type ModuleUpdatePatchModulesTenantIdIdResponse = unknown;
export type ModuleUpdatePatchModulesTenantIdIdError = 'module_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ModuleController@destroy returns a raw database row. */
export type ModuleDestroyDeleteModulesTenantIdIdResponse = unknown;
export type ModuleDestroyDeleteModulesTenantIdIdError = 'module_not_found';

export type NavigationStorePostNavigationRequest = {
  industry_code: string;
  role_key: string;
  item_key: string;
  label: string;
  icon?: string;
  route?: string;
  parent_id?: string;
  sort_order?: number;
  is_visible?: boolean;
  required_permission?: string;
  required_flag?: string;
  required_module?: string;
  children?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\NavigationController@store returns a raw database row. */
export type NavigationStorePostNavigationResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\NavigationController@index returns a raw database row. */
export type NavigationIndexGetNavigationTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\NavigationController@show returns a raw database row. */
export type NavigationShowGetNavigationTenantIdIdResponse = unknown;
export type NavigationShowGetNavigationTenantIdIdError = 'navigation_item_not_found';

export type NavigationUpdatePatchNavigationTenantIdIdRequest = {
  industry_code?: string;
  role_key?: string;
  item_key?: string;
  label?: string;
  icon?: string;
  route?: string;
  parent_id?: string;
  sort_order?: number;
  is_visible?: boolean;
  required_permission?: string;
  required_flag?: string;
  required_module?: string;
  children?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\NavigationController@update returns a raw database row. */
export type NavigationUpdatePatchNavigationTenantIdIdResponse = unknown;
export type NavigationUpdatePatchNavigationTenantIdIdError = 'navigation_item_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\NavigationController@destroy returns a raw database row. */
export type NavigationDestroyDeleteNavigationTenantIdIdResponse = unknown;
export type NavigationDestroyDeleteNavigationTenantIdIdError = 'navigation_item_not_found';

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

/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@index returns a raw database row. */
export type OnboardingIndexGetOnboardingTenantIdResponse = unknown;

export type OnboardingStartPostOnboardingTenantIdStartRequest = {
  org_id?: string;
  initial_data?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@start returns a raw database row. */
export type OnboardingStartPostOnboardingTenantIdStartResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@show returns a raw database row. */
export type OnboardingShowGetOnboardingTenantIdIdResponse = unknown;
export type OnboardingShowGetOnboardingTenantIdIdError = 'onboarding_session_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\OnboardingController@abandon; body shape not derivable. */
export type OnboardingAbandonPostOnboardingTenantIdIdAbandonRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@abandon returns a raw database row. */
export type OnboardingAbandonPostOnboardingTenantIdIdAbandonResponse = unknown;
export type OnboardingAbandonPostOnboardingTenantIdIdAbandonError = 'onboarding_session_not_found';

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\OnboardingController@activate; body shape not derivable. */
export type OnboardingActivatePostOnboardingTenantIdIdActivateRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@activate returns a raw database row. */
export type OnboardingActivatePostOnboardingTenantIdIdActivateResponse = unknown;
export type OnboardingActivatePostOnboardingTenantIdIdActivateError = 'onboarding_session_not_found';

export type OnboardingCompleteStepPostOnboardingTenantIdIdCompleteStepRequest = {
  step: number;
  data?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@completeStep returns a raw database row. */
export type OnboardingCompleteStepPostOnboardingTenantIdIdCompleteStepResponse = unknown;
export type OnboardingCompleteStepPostOnboardingTenantIdIdCompleteStepError = 'onboarding_session_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@getNextStep returns a raw database row. */
export type OnboardingGetNextStepGetOnboardingTenantIdIdNextStepResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@readiness returns a raw database row. */
export type OnboardingReadinessGetOnboardingTenantIdIdReadinessResponse = unknown;

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\OnboardingController@runReadinessChecks; body shape not derivable. */
export type OnboardingRunReadinessChecksPostOnboardingTenantIdIdReadinessRunRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@runReadinessChecks returns a raw database row. */
export type OnboardingRunReadinessChecksPostOnboardingTenantIdIdReadinessRunResponse = unknown;

export type OnboardingValidateStepPostOnboardingTenantIdIdValidateStepRequest = {
  step: number;
};
/** UNVERIFIED: App\Http\Controllers\Api\OnboardingController@validateStep returns a raw database row. */
export type OnboardingValidateStepPostOnboardingTenantIdIdValidateStepResponse = unknown;

export type OrganizationConfigStorePostOrganizationConfigsRequest = {
  org_id: string;
  config_key: string;
  config_value?: string;
  config_type?: string;
  description?: string;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationConfigController@store returns a raw database row. */
export type OrganizationConfigStorePostOrganizationConfigsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationConfigController@index returns a raw database row. */
export type OrganizationConfigIndexGetOrganizationConfigsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationConfigController@show returns a raw database row. */
export type OrganizationConfigShowGetOrganizationConfigsTenantIdIdResponse = unknown;
export type OrganizationConfigShowGetOrganizationConfigsTenantIdIdError = 'config_not_found';

export type OrganizationConfigUpdatePatchOrganizationConfigsTenantIdIdRequest = {
  config_value?: string;
  config_type?: string;
  description?: string;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationConfigController@update returns a raw database row. */
export type OrganizationConfigUpdatePatchOrganizationConfigsTenantIdIdResponse = unknown;
export type OrganizationConfigUpdatePatchOrganizationConfigsTenantIdIdError = 'config_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationConfigController@destroy returns a raw database row. */
export type OrganizationConfigDestroyDeleteOrganizationConfigsTenantIdIdResponse = unknown;
export type OrganizationConfigDestroyDeleteOrganizationConfigsTenantIdIdError = 'config_not_found';

export type OrganizationModuleStorePostOrganizationModulesRequest = {
  org_id: string;
  module_id: string;
  is_enabled?: boolean;
  config?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationModuleController@store returns a raw database row. */
export type OrganizationModuleStorePostOrganizationModulesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationModuleController@index returns a raw database row. */
export type OrganizationModuleIndexGetOrganizationModulesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationModuleController@show returns a raw database row. */
export type OrganizationModuleShowGetOrganizationModulesTenantIdIdResponse = unknown;
export type OrganizationModuleShowGetOrganizationModulesTenantIdIdError = 'org_module_not_found';

export type OrganizationModuleUpdatePatchOrganizationModulesTenantIdIdRequest = {
  is_enabled?: boolean;
  config?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationModuleController@update returns a raw database row. */
export type OrganizationModuleUpdatePatchOrganizationModulesTenantIdIdResponse = unknown;
export type OrganizationModuleUpdatePatchOrganizationModulesTenantIdIdError = 'org_module_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationModuleController@destroy returns a raw database row. */
export type OrganizationModuleDestroyDeleteOrganizationModulesTenantIdIdResponse = unknown;
export type OrganizationModuleDestroyDeleteOrganizationModulesTenantIdIdError = 'org_module_not_found';

export type OrganizationTypeStorePostOrganizationTypesRequest = {
  type_key: string;
  name: string;
  description?: string;
  icon?: string;
  sort_order?: number;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationTypeController@store returns a raw database row. */
export type OrganizationTypeStorePostOrganizationTypesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationTypeController@index returns a raw database row. */
export type OrganizationTypeIndexGetOrganizationTypesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationTypeController@show returns a raw database row. */
export type OrganizationTypeShowGetOrganizationTypesTenantIdIdResponse = unknown;
export type OrganizationTypeShowGetOrganizationTypesTenantIdIdError = 'organization_type_not_found';

export type OrganizationTypeUpdatePatchOrganizationTypesTenantIdIdRequest = {
  type_key?: string;
  name?: string;
  description?: string;
  icon?: string;
  sort_order?: number;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationTypeController@update returns a raw database row. */
export type OrganizationTypeUpdatePatchOrganizationTypesTenantIdIdResponse = unknown;
export type OrganizationTypeUpdatePatchOrganizationTypesTenantIdIdError = 'organization_type_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationTypeController@destroy returns a raw database row. */
export type OrganizationTypeDestroyDeleteOrganizationTypesTenantIdIdResponse = unknown;
export type OrganizationTypeDestroyDeleteOrganizationTypesTenantIdIdError = 'organization_type_not_found';

export type OrganizationUnitStorePostOrganizationUnitsRequest = {
  org_id?: string;
  unit_type?: string;
  name: string;
  description?: string;
  code?: string;
  parent_unit_id?: string;
  head_id?: string;
  location?: string;
  cost_center?: string;
  status?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationUnitController@store returns a raw database row. */
export type OrganizationUnitStorePostOrganizationUnitsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationUnitController@index returns a raw database row. */
export type OrganizationUnitIndexGetOrganizationUnitsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationUnitController@hierarchy returns a raw database row. */
export type OrganizationUnitHierarchyGetOrganizationUnitsTenantIdHierarchyResponse = unknown;
export type OrganizationUnitHierarchyGetOrganizationUnitsTenantIdHierarchyError = 'orgId_required';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationUnitController@tree returns a raw database row. */
export type OrganizationUnitTreeGetOrganizationUnitsTenantIdTreeResponse = unknown;
export type OrganizationUnitTreeGetOrganizationUnitsTenantIdTreeError = 'orgId_required';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationUnitController@show returns a raw database row. */
export type OrganizationUnitShowGetOrganizationUnitsTenantIdIdResponse = unknown;
export type OrganizationUnitShowGetOrganizationUnitsTenantIdIdError = 'organization_unit_not_found';

export type OrganizationUnitUpdatePatchOrganizationUnitsTenantIdIdRequest = {
  org_id?: string;
  unit_type?: string;
  name?: string;
  description?: string;
  code?: string;
  parent_unit_id?: string;
  head_id?: string;
  location?: string;
  cost_center?: string;
  status?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\OrganizationUnitController@update returns a raw database row. */
export type OrganizationUnitUpdatePatchOrganizationUnitsTenantIdIdResponse = unknown;
export type OrganizationUnitUpdatePatchOrganizationUnitsTenantIdIdError = 'organization_unit_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationUnitController@destroy returns a raw database row. */
export type OrganizationUnitDestroyDeleteOrganizationUnitsTenantIdIdResponse = unknown;
export type OrganizationUnitDestroyDeleteOrganizationUnitsTenantIdIdError = 'organization_unit_not_found';

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

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@dataQuality returns a raw database row. */
export type OrganizationDataQualityGetOrganizationsTenantIdIdDataQualityResponse = unknown;
export type OrganizationDataQualityGetOrganizationsTenantIdIdDataQualityError = 'organization_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\OrganizationController@structure returns a raw database row. */
export type OrganizationStructureGetOrganizationsTenantIdIdStructureResponse = unknown;
export type OrganizationStructureGetOrganizationsTenantIdIdStructureError = 'organization_not_found';

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

export type PersonCompetencyStorePostPersonCompetenciesRequest = {
  person_id: string;
  competency_id: string;
  current_level?: string;
  target_level?: string;
  assessed_date?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonCompetencyController@store returns a raw database row. */
export type PersonCompetencyStorePostPersonCompetenciesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonCompetencyController@index returns a raw database row. */
export type PersonCompetencyIndexGetPersonCompetenciesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonCompetencyController@show returns a raw database row. */
export type PersonCompetencyShowGetPersonCompetenciesTenantIdIdResponse = unknown;
export type PersonCompetencyShowGetPersonCompetenciesTenantIdIdError = 'person_competency_not_found';

export type PersonCompetencyUpdatePatchPersonCompetenciesTenantIdIdRequest = {
  current_level?: string;
  target_level?: string;
  assessed_date?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonCompetencyController@update returns a raw database row. */
export type PersonCompetencyUpdatePatchPersonCompetenciesTenantIdIdResponse = unknown;
export type PersonCompetencyUpdatePatchPersonCompetenciesTenantIdIdError = 'person_competency_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\PersonCompetencyController@destroy returns a raw database row. */
export type PersonCompetencyDestroyDeletePersonCompetenciesTenantIdIdResponse = unknown;
export type PersonCompetencyDestroyDeletePersonCompetenciesTenantIdIdError = 'person_competency_not_found';

export type PersonRoleStorePostPersonRolesRequest = {
  person_id: string;
  role_id: string;
  org_id?: string;
  unit_id?: string;
  start_date?: string;
  end_date?: string;
  is_primary?: boolean;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonRoleController@store returns a raw database row. */
export type PersonRoleStorePostPersonRolesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonRoleController@index returns a raw database row. */
export type PersonRoleIndexGetPersonRolesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonRoleController@show returns a raw database row. */
export type PersonRoleShowGetPersonRolesTenantIdIdResponse = unknown;
export type PersonRoleShowGetPersonRolesTenantIdIdError = 'person_role_not_found';

export type PersonRoleUpdatePatchPersonRolesTenantIdIdRequest = {
  person_id?: string;
  role_id?: string;
  org_id?: string;
  unit_id?: string;
  start_date?: string;
  end_date?: string;
  is_primary?: boolean;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonRoleController@update returns a raw database row. */
export type PersonRoleUpdatePatchPersonRolesTenantIdIdResponse = unknown;
export type PersonRoleUpdatePatchPersonRolesTenantIdIdError = 'person_role_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\PersonRoleController@destroy returns a raw database row. */
export type PersonRoleDestroyDeletePersonRolesTenantIdIdResponse = unknown;
export type PersonRoleDestroyDeletePersonRolesTenantIdIdError = 'person_role_not_found';

export type PersonSkillStorePostPersonSkillsRequest = {
  person_id: string;
  skill_id: string;
  proficiency_level?: string;
  proficiency_score?: number;
  assessed_by?: string;
  assessed_date?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonSkillController@store returns a raw database row. */
export type PersonSkillStorePostPersonSkillsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonSkillController@index returns a raw database row. */
export type PersonSkillIndexGetPersonSkillsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PersonSkillController@show returns a raw database row. */
export type PersonSkillShowGetPersonSkillsTenantIdIdResponse = unknown;
export type PersonSkillShowGetPersonSkillsTenantIdIdError = 'person_skill_not_found';

export type PersonSkillUpdatePatchPersonSkillsTenantIdIdRequest = {
  proficiency_level?: string;
  proficiency_score?: number;
  assessed_by?: string;
  assessed_date?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\PersonSkillController@update returns a raw database row. */
export type PersonSkillUpdatePatchPersonSkillsTenantIdIdResponse = unknown;
export type PersonSkillUpdatePatchPersonSkillsTenantIdIdError = 'person_skill_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\PersonSkillController@destroy returns a raw database row. */
export type PersonSkillDestroyDeletePersonSkillsTenantIdIdResponse = unknown;
export type PersonSkillDestroyDeletePersonSkillsTenantIdIdError = 'person_skill_not_found';

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

export type PositionStorePostPositionsRequest = {
  org_id?: string;
  unit_id?: string;
  title: string;
  description?: string;
  employment_type?: string;
  is_vacant?: boolean;
  reports_to_position_id?: string;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\PositionController@store returns a raw database row. */
export type PositionStorePostPositionsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PositionController@index returns a raw database row. */
export type PositionIndexGetPositionsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\PositionController@show returns a raw database row. */
export type PositionShowGetPositionsTenantIdIdResponse = unknown;
export type PositionShowGetPositionsTenantIdIdError = 'position_not_found';

export type PositionUpdatePatchPositionsTenantIdIdRequest = {
  org_id?: string;
  unit_id?: string;
  title?: string;
  description?: string;
  employment_type?: string;
  is_vacant?: boolean;
  reports_to_position_id?: string;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\PositionController@update returns a raw database row. */
export type PositionUpdatePatchPositionsTenantIdIdResponse = unknown;
export type PositionUpdatePatchPositionsTenantIdIdError = 'position_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\PositionController@destroy returns a raw database row. */
export type PositionDestroyDeletePositionsTenantIdIdResponse = unknown;
export type PositionDestroyDeletePositionsTenantIdIdError = 'position_not_found';

export type ReadinessCheckStorePostReadinessChecksRequest = {
  org_id?: string;
  check_type: string;
  check_name: string;
  status?: string;
  message?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReadinessCheckController@store returns a raw database row. */
export type ReadinessCheckStorePostReadinessChecksResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReadinessCheckController@index returns a raw database row. */
export type ReadinessCheckIndexGetReadinessChecksTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReadinessCheckController@show returns a raw database row. */
export type ReadinessCheckShowGetReadinessChecksTenantIdIdResponse = unknown;
export type ReadinessCheckShowGetReadinessChecksTenantIdIdError = 'readiness_check_not_found';

export type ReadinessCheckUpdatePatchReadinessChecksTenantIdIdRequest = {
  check_type?: string;
  check_name?: string;
  status?: string;
  message?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReadinessCheckController@update returns a raw database row. */
export type ReadinessCheckUpdatePatchReadinessChecksTenantIdIdResponse = unknown;
export type ReadinessCheckUpdatePatchReadinessChecksTenantIdIdError = 'readiness_check_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ReadinessCheckController@destroy returns a raw database row. */
export type ReadinessCheckDestroyDeleteReadinessChecksTenantIdIdResponse = unknown;
export type ReadinessCheckDestroyDeleteReadinessChecksTenantIdIdError = 'readiness_check_not_found';

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

export type ReportingStructureStorePostReportingStructuresRequest = {
  org_id?: string;
  reporter_person_id: string;
  reportee_person_id: string;
  reporting_type?: string;
  unit_id?: string;
  start_date?: string;
  end_date?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReportingStructureController@store returns a raw database row. */
export type ReportingStructureStorePostReportingStructuresResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReportingStructureController@index returns a raw database row. */
export type ReportingStructureIndexGetReportingStructuresTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ReportingStructureController@show returns a raw database row. */
export type ReportingStructureShowGetReportingStructuresTenantIdIdResponse = unknown;
export type ReportingStructureShowGetReportingStructuresTenantIdIdError = 'reporting_structure_not_found';

export type ReportingStructureUpdatePatchReportingStructuresTenantIdIdRequest = {
  org_id?: string;
  reporter_person_id?: string;
  reportee_person_id?: string;
  reporting_type?: string;
  unit_id?: string;
  start_date?: string;
  end_date?: string;
  metadata?: Array<unknown>;
};
/** UNVERIFIED: App\Http\Controllers\Api\ReportingStructureController@update returns a raw database row. */
export type ReportingStructureUpdatePatchReportingStructuresTenantIdIdResponse = unknown;
export type ReportingStructureUpdatePatchReportingStructuresTenantIdIdError = 'reporting_structure_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ReportingStructureController@destroy returns a raw database row. */
export type ReportingStructureDestroyDeleteReportingStructuresTenantIdIdResponse = unknown;
export type ReportingStructureDestroyDeleteReportingStructuresTenantIdIdError = 'reporting_structure_not_found';

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

export type RoleStorePostRolesRequest = {
  role_key: string;
  name: string;
  description?: string;
  category?: string;
  permissions?: Array<unknown>;
  is_system?: boolean;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\RoleController@store returns a raw database row. */
export type RoleStorePostRolesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\RoleController@index returns a raw database row. */
export type RoleIndexGetRolesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\RoleController@show returns a raw database row. */
export type RoleShowGetRolesTenantIdIdResponse = unknown;
export type RoleShowGetRolesTenantIdIdError = 'role_not_found';

export type RoleUpdatePatchRolesTenantIdIdRequest = {
  role_key?: string;
  name?: string;
  description?: string;
  category?: string;
  permissions?: Array<unknown>;
  is_system?: boolean;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\RoleController@update returns a raw database row. */
export type RoleUpdatePatchRolesTenantIdIdResponse = unknown;
export type RoleUpdatePatchRolesTenantIdIdError = 'role_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\RoleController@destroy returns a raw database row. */
export type RoleDestroyDeleteRolesTenantIdIdResponse = unknown;
export type RoleDestroyDeleteRolesTenantIdIdError = 'role_not_found';

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

/** UNVERIFIED: no validate() in App\Http\Controllers\Api\SignalController@generate; body shape not derivable. */
export type SignalGeneratePostSignalsGenerateRequest = unknown;
/** UNVERIFIED: App\Http\Controllers\Api\SignalController@generate returns a raw database row. */
export type SignalGeneratePostSignalsGenerateResponse = unknown;

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

export type SkillStorePostSkillsRequest = {
  skill_key: string;
  name: string;
  description?: string;
  category?: string;
  level?: string;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\SkillController@store returns a raw database row. */
export type SkillStorePostSkillsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\SkillController@index returns a raw database row. */
export type SkillIndexGetSkillsTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\SkillController@show returns a raw database row. */
export type SkillShowGetSkillsTenantIdIdResponse = unknown;
export type SkillShowGetSkillsTenantIdIdError = 'skill_not_found';

export type SkillUpdatePatchSkillsTenantIdIdRequest = {
  skill_key?: string;
  name?: string;
  description?: string;
  category?: string;
  level?: string;
  metadata?: Array<unknown>;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\SkillController@update returns a raw database row. */
export type SkillUpdatePatchSkillsTenantIdIdResponse = unknown;
export type SkillUpdatePatchSkillsTenantIdIdError = 'skill_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\SkillController@destroy returns a raw database row. */
export type SkillDestroyDeleteSkillsTenantIdIdResponse = unknown;
export type SkillDestroyDeleteSkillsTenantIdIdError = 'skill_not_found';

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

export type TemplateOverrideStorePostTemplateOverridesRequest = {
  org_id?: string;
  template_type: string;
  template_key: string;
  override_level?: string;
  override_data?: Array<unknown>;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\TemplateOverrideController@store returns a raw database row. */
export type TemplateOverrideStorePostTemplateOverridesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\TemplateOverrideController@index returns a raw database row. */
export type TemplateOverrideIndexGetTemplateOverridesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\TemplateOverrideController@show returns a raw database row. */
export type TemplateOverrideShowGetTemplateOverridesTenantIdIdResponse = unknown;
export type TemplateOverrideShowGetTemplateOverridesTenantIdIdError = 'template_override_not_found';

export type TemplateOverrideUpdatePatchTemplateOverridesTenantIdIdRequest = {
  template_type?: string;
  template_key?: string;
  override_level?: string;
  override_data?: Array<unknown>;
  is_active?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\TemplateOverrideController@update returns a raw database row. */
export type TemplateOverrideUpdatePatchTemplateOverridesTenantIdIdResponse = unknown;
export type TemplateOverrideUpdatePatchTemplateOverridesTenantIdIdError = 'template_override_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\TemplateOverrideController@destroy returns a raw database row. */
export type TemplateOverrideDestroyDeleteTemplateOverridesTenantIdIdResponse = unknown;
export type TemplateOverrideDestroyDeleteTemplateOverridesTenantIdIdError = 'template_override_not_found';

export type TerminologyStorePostTerminologyRequest = {
  industry_code: string;
  entity_type: string;
  display_name: string;
  plural_name?: string;
  description?: string;
  icon?: string;
  sort_order?: number;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\TerminologyController@store returns a raw database row. */
export type TerminologyStorePostTerminologyResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\TerminologyController@index returns a raw database row. */
export type TerminologyIndexGetTerminologyTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\TerminologyController@show returns a raw database row. */
export type TerminologyShowGetTerminologyTenantIdIdResponse = unknown;
export type TerminologyShowGetTerminologyTenantIdIdError = 'terminology_not_found';

export type TerminologyUpdatePatchTerminologyTenantIdIdRequest = {
  industry_code?: string;
  entity_type?: string;
  display_name?: string;
  plural_name?: string;
  description?: string;
  icon?: string;
  sort_order?: number;
  status?: string;
};
/** UNVERIFIED: App\Http\Controllers\Api\TerminologyController@update returns a raw database row. */
export type TerminologyUpdatePatchTerminologyTenantIdIdResponse = unknown;
export type TerminologyUpdatePatchTerminologyTenantIdIdError = 'terminology_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\TerminologyController@destroy returns a raw database row. */
export type TerminologyDestroyDeleteTerminologyTenantIdIdResponse = unknown;
export type TerminologyDestroyDeleteTerminologyTenantIdIdError = 'terminology_not_found';

export type ThemeStorePostThemesRequest = {
  theme_key: string;
  name: string;
  description?: string;
  colors?: Array<unknown>;
  typography?: Array<unknown>;
  spacing?: Array<unknown>;
  borderRadius?: Array<unknown>;
  shadows?: Array<unknown>;
  is_dark?: boolean;
  is_default?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\ThemeController@store returns a raw database row. */
export type ThemeStorePostThemesResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ThemeController@index returns a raw database row. */
export type ThemeIndexGetThemesTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\ThemeController@show returns a raw database row. */
export type ThemeShowGetThemesTenantIdIdResponse = unknown;
export type ThemeShowGetThemesTenantIdIdError = 'theme_not_found';

export type ThemeUpdatePatchThemesTenantIdIdRequest = {
  theme_key?: string;
  name?: string;
  description?: string;
  colors?: Array<unknown>;
  typography?: Array<unknown>;
  spacing?: Array<unknown>;
  borderRadius?: Array<unknown>;
  shadows?: Array<unknown>;
  is_dark?: boolean;
  is_default?: boolean;
};
/** UNVERIFIED: App\Http\Controllers\Api\ThemeController@update returns a raw database row. */
export type ThemeUpdatePatchThemesTenantIdIdResponse = unknown;
export type ThemeUpdatePatchThemesTenantIdIdError = 'theme_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\ThemeController@destroy returns a raw database row. */
export type ThemeDestroyDeleteThemesTenantIdIdResponse = unknown;
export type ThemeDestroyDeleteThemesTenantIdIdError = 'theme_not_found';

/** UNVERIFIED: App\Http\Controllers\Api\WorkspaceController@summary returns a raw database row. */
export type WorkspaceSummaryGetWorkspaceTenantIdResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\WorkspaceController@homeMetrics returns a raw database row. */
export type WorkspaceHomeMetricsGetWorkspaceTenantIdHomeMetricsResponse = unknown;

/** UNVERIFIED: App\Http\Controllers\Api\SearchController@signalChain returns a raw database row. */
export type SearchSignalChainGetWorkspaceTenantIdSignalSignalIdChainResponse = unknown;
export type SearchSignalChainGetWorkspaceTenantIdSignalSignalIdChainError = 'signal_not_found';

/** Every operation the API exposes, for tooling. */
export const OPERATIONS = [
  {
    "name": "AiEvaluationStorePostAiEvaluations",
    "method": "POST",
    "path": "/ai/evaluations",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiEvaluationController@store",
    "unverifiedResponse": true
  },
  {
    "name": "AiEvaluationIndexGetAiEvaluationsTenantId",
    "method": "GET",
    "path": "/ai/evaluations/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiEvaluationController@index",
    "unverifiedResponse": true
  },
  {
    "name": "AiEvaluationShowGetAiEvaluationsTenantIdId",
    "method": "GET",
    "path": "/ai/evaluations/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiEvaluationController@show",
    "unverifiedResponse": true
  },
  {
    "name": "AiEvaluationResultsGetAiEvaluationsTenantIdIdResults",
    "method": "GET",
    "path": "/ai/evaluations/{tenantId}/{id}/results",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiEvaluationController@results",
    "unverifiedResponse": true
  },
  {
    "name": "AiEvaluationRunPostAiEvaluationsTenantIdIdRun",
    "method": "POST",
    "path": "/ai/evaluations/{tenantId}/{id}/run",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiEvaluationController@run",
    "unverifiedResponse": true
  },
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
    "name": "AiFeedbackStorePostAiFeedback",
    "method": "POST",
    "path": "/ai/feedback",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiFeedbackController@store",
    "unverifiedResponse": true
  },
  {
    "name": "AiFeedbackIndexGetAiFeedbackTenantId",
    "method": "GET",
    "path": "/ai/feedback/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiFeedbackController@index",
    "unverifiedResponse": true
  },
  {
    "name": "AiFeedbackShowGetAiFeedbackTenantIdId",
    "method": "GET",
    "path": "/ai/feedback/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiFeedbackController@show",
    "unverifiedResponse": true
  },
  {
    "name": "AiPromptTemplateStorePostAiPromptTemplates",
    "method": "POST",
    "path": "/ai/prompt-templates",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiPromptTemplateController@store",
    "unverifiedResponse": true
  },
  {
    "name": "AiPromptTemplateIndexGetAiPromptTemplatesTenantId",
    "method": "GET",
    "path": "/ai/prompt-templates/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiPromptTemplateController@index",
    "unverifiedResponse": true
  },
  {
    "name": "AiPromptTemplateShowGetAiPromptTemplatesTenantIdId",
    "method": "GET",
    "path": "/ai/prompt-templates/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiPromptTemplateController@show",
    "unverifiedResponse": true
  },
  {
    "name": "AiPromptTemplateUpdatePatchAiPromptTemplatesTenantIdId",
    "method": "PATCH",
    "path": "/ai/prompt-templates/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiPromptTemplateController@update",
    "unverifiedResponse": true
  },
  {
    "name": "AiPromptTemplateDestroyDeleteAiPromptTemplatesTenantIdId",
    "method": "DELETE",
    "path": "/ai/prompt-templates/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiPromptTemplateController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "AiPromptTemplateRenderGetAiPromptTemplatesTenantIdIdRender",
    "method": "GET",
    "path": "/ai/prompt-templates/{tenantId}/{id}/render",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiPromptTemplateController@render",
    "unverifiedResponse": true
  },
  {
    "name": "AiPromptTemplateVersionsGetAiPromptTemplatesTenantIdIdVersions",
    "method": "GET",
    "path": "/ai/prompt-templates/{tenantId}/{id}/versions",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiPromptTemplateController@versions",
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
    "name": "AiProviderStorePostAiProviders",
    "method": "POST",
    "path": "/ai/providers",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiProviderController@store",
    "unverifiedResponse": true
  },
  {
    "name": "AiProviderIndexGetAiProvidersTenantId",
    "method": "GET",
    "path": "/ai/providers/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiProviderController@index",
    "unverifiedResponse": true
  },
  {
    "name": "AiProviderShowGetAiProvidersTenantIdId",
    "method": "GET",
    "path": "/ai/providers/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiProviderController@show",
    "unverifiedResponse": true
  },
  {
    "name": "AiProviderUpdatePatchAiProvidersTenantIdId",
    "method": "PATCH",
    "path": "/ai/providers/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiProviderController@update",
    "unverifiedResponse": true
  },
  {
    "name": "AiProviderDestroyDeleteAiProvidersTenantIdId",
    "method": "DELETE",
    "path": "/ai/providers/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiProviderController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "AiProviderSetActivePostAiProvidersTenantIdIdActivate",
    "method": "POST",
    "path": "/ai/providers/{tenantId}/{id}/activate",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiProviderController@setActive",
    "unverifiedResponse": true
  },
  {
    "name": "AiProviderTestPostAiProvidersTenantIdIdTest",
    "method": "POST",
    "path": "/ai/providers/{tenantId}/{id}/test",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiProviderController@test",
    "unverifiedResponse": true
  },
  {
    "name": "AiQuotaStorePostAiQuotas",
    "method": "POST",
    "path": "/ai/quotas",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiQuotaController@store",
    "unverifiedResponse": true
  },
  {
    "name": "AiQuotaIndexGetAiQuotasTenantId",
    "method": "GET",
    "path": "/ai/quotas/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiQuotaController@index",
    "unverifiedResponse": true
  },
  {
    "name": "AiQuotaShowGetAiQuotasTenantIdId",
    "method": "GET",
    "path": "/ai/quotas/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiQuotaController@show",
    "unverifiedResponse": true
  },
  {
    "name": "AiQuotaUpdatePatchAiQuotasTenantIdId",
    "method": "PATCH",
    "path": "/ai/quotas/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiQuotaController@update",
    "unverifiedResponse": true
  },
  {
    "name": "AiQuotaResetPostAiQuotasTenantIdIdReset",
    "method": "POST",
    "path": "/ai/quotas/{tenantId}/{id}/reset",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiQuotaController@reset",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceSessionsGetAiWorkspaceSessions",
    "method": "GET",
    "path": "/ai/workspace/sessions",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@sessions",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceStorePostAiWorkspaceSessions",
    "method": "POST",
    "path": "/ai/workspace/sessions",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@store",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceHistoryGetAiWorkspaceSessionsSessionIdHistory",
    "method": "GET",
    "path": "/ai/workspace/sessions/{sessionId}/history",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@history",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceMessagesGetAiWorkspaceSessionsSessionIdMessages",
    "method": "GET",
    "path": "/ai/workspace/sessions/{sessionId}/messages",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@messages",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceSendPostAiWorkspaceSessionsSessionIdMessages",
    "method": "POST",
    "path": "/ai/workspace/sessions/{sessionId}/messages",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@send",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceExplainPostAiWorkspaceSessionsSessionIdMessagesMessageIdExplain",
    "method": "POST",
    "path": "/ai/workspace/sessions/{sessionId}/messages/{messageId}/explain",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@explain",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceFollowUpGetAiWorkspaceSessionsSessionIdMessagesMessageIdFollowUp",
    "method": "GET",
    "path": "/ai/workspace/sessions/{sessionId}/messages/{messageId}/follow-up",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@followUp",
    "unverifiedResponse": true
  },
  {
    "name": "AiWorkspaceRegeneratePostAiWorkspaceSessionsSessionIdMessagesMessageIdRegenerate",
    "method": "POST",
    "path": "/ai/workspace/sessions/{sessionId}/messages/{messageId}/regenerate",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AiWorkspaceController@regenerate",
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
    "name": "AnalyticsIntelligenceReportGetAnalyticsTenantIdReportsIntelligence",
    "method": "GET",
    "path": "/analytics/{tenantId}/reports/intelligence",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AnalyticsController@intelligenceReport",
    "unverifiedResponse": true
  },
  {
    "name": "AnalyticsOrganizationReportGetAnalyticsTenantIdReportsOrganization",
    "method": "GET",
    "path": "/analytics/{tenantId}/reports/organization",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AnalyticsController@organizationReport",
    "unverifiedResponse": true
  },
  {
    "name": "AnalyticsPeopleReportGetAnalyticsTenantIdReportsPeople",
    "method": "GET",
    "path": "/analytics/{tenantId}/reports/people",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\AnalyticsController@peopleReport",
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
    "name": "AuthLoginPostAuthLogin",
    "method": "POST",
    "path": "/auth/login",
    "permissions": {},
    "controller": "App\\Http\\Controllers\\Api\\AuthController@login",
    "unverifiedResponse": true
  },
  {
    "name": "AuthLogoutPostAuthLogout",
    "method": "POST",
    "path": "/auth/logout",
    "permissions": {},
    "controller": "App\\Http\\Controllers\\Api\\AuthController@logout",
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
    "name": "BrandingStorePostBranding",
    "method": "POST",
    "path": "/branding",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\BrandingController@store",
    "unverifiedResponse": true
  },
  {
    "name": "BrandingIndexGetBrandingTenantId",
    "method": "GET",
    "path": "/branding/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\BrandingController@index",
    "unverifiedResponse": true
  },
  {
    "name": "BrandingShowGetBrandingTenantIdId",
    "method": "GET",
    "path": "/branding/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\BrandingController@show",
    "unverifiedResponse": true
  },
  {
    "name": "BrandingUpdatePatchBrandingTenantIdId",
    "method": "PATCH",
    "path": "/branding/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\BrandingController@update",
    "unverifiedResponse": true
  },
  {
    "name": "BrandingDestroyDeleteBrandingTenantIdId",
    "method": "DELETE",
    "path": "/branding/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\BrandingController@destroy",
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
    "name": "CompetencyStorePostCompetencies",
    "method": "POST",
    "path": "/competencies",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CompetencyController@store",
    "unverifiedResponse": true
  },
  {
    "name": "CompetencyIndexGetCompetenciesTenantId",
    "method": "GET",
    "path": "/competencies/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CompetencyController@index",
    "unverifiedResponse": true
  },
  {
    "name": "CompetencyShowGetCompetenciesTenantIdId",
    "method": "GET",
    "path": "/competencies/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CompetencyController@show",
    "unverifiedResponse": true
  },
  {
    "name": "CompetencyUpdatePatchCompetenciesTenantIdId",
    "method": "PATCH",
    "path": "/competencies/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CompetencyController@update",
    "unverifiedResponse": true
  },
  {
    "name": "CompetencyDestroyDeleteCompetenciesTenantIdId",
    "method": "DELETE",
    "path": "/competencies/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\CompetencyController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "ConfigVersionStorePostConfigVersions",
    "method": "POST",
    "path": "/config-versions",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConfigVersionController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ConfigVersionIndexGetConfigVersionsTenantId",
    "method": "GET",
    "path": "/config-versions/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConfigVersionController@index",
    "unverifiedResponse": true
  },
  {
    "name": "ConfigVersionShowGetConfigVersionsTenantIdId",
    "method": "GET",
    "path": "/config-versions/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConfigVersionController@show",
    "unverifiedResponse": true
  },
  {
    "name": "ConfigVersionActivatePostConfigVersionsTenantIdIdActivate",
    "method": "POST",
    "path": "/config-versions/{tenantId}/{id}/activate",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConfigVersionController@activate",
    "unverifiedResponse": true
  },
  {
    "name": "ConfigVersionRollbackPostConfigVersionsTenantIdIdRollback",
    "method": "POST",
    "path": "/config-versions/{tenantId}/{id}/rollback",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ConfigVersionController@rollback",
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
    "name": "DashboardWidgetStorePostDashboardWidgets",
    "method": "POST",
    "path": "/dashboard-widgets",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardWidgetController@store",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardWidgetIndexGetDashboardWidgetsTenantId",
    "method": "GET",
    "path": "/dashboard-widgets/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardWidgetController@index",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardWidgetShowGetDashboardWidgetsTenantIdId",
    "method": "GET",
    "path": "/dashboard-widgets/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardWidgetController@show",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardWidgetUpdatePatchDashboardWidgetsTenantIdId",
    "method": "PATCH",
    "path": "/dashboard-widgets/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardWidgetController@update",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardWidgetDestroyDeleteDashboardWidgetsTenantIdId",
    "method": "DELETE",
    "path": "/dashboard-widgets/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardWidgetController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardStorePostDashboards",
    "method": "POST",
    "path": "/dashboards",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardController@store",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardIndexGetDashboardsTenantId",
    "method": "GET",
    "path": "/dashboards/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardController@index",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardShowGetDashboardsTenantIdId",
    "method": "GET",
    "path": "/dashboards/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardController@show",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardUpdatePatchDashboardsTenantIdId",
    "method": "PATCH",
    "path": "/dashboards/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardController@update",
    "unverifiedResponse": true
  },
  {
    "name": "DashboardDestroyDeleteDashboardsTenantIdId",
    "method": "DELETE",
    "path": "/dashboards/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\DashboardController@destroy",
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
    "name": "EntityMappingStorePostEntityMappings",
    "method": "POST",
    "path": "/entity-mappings",
    "permissions": [
      "read",
      "tenant.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EntityMappingController@store",
    "unverifiedResponse": true
  },
  {
    "name": "EntityMappingIndexGetEntityMappingsTenantId",
    "method": "GET",
    "path": "/entity-mappings/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EntityMappingController@index",
    "unverifiedResponse": true
  },
  {
    "name": "EntityMappingShowGetEntityMappingsTenantIdId",
    "method": "GET",
    "path": "/entity-mappings/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EntityMappingController@show",
    "unverifiedResponse": true
  },
  {
    "name": "EntityMappingUpdatePatchEntityMappingsTenantIdId",
    "method": "PATCH",
    "path": "/entity-mappings/{tenantId}/{id}",
    "permissions": [
      "read",
      "tenant.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EntityMappingController@update",
    "unverifiedResponse": true
  },
  {
    "name": "EntityMappingDestroyDeleteEntityMappingsTenantIdId",
    "method": "DELETE",
    "path": "/entity-mappings/{tenantId}/{id}",
    "permissions": [
      "read",
      "tenant.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\EntityMappingController@destroy",
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
    "name": "FeatureFlagStorePostFeatureFlags",
    "method": "POST",
    "path": "/feature-flags",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FeatureFlagController@store",
    "unverifiedResponse": true
  },
  {
    "name": "FeatureFlagIndexGetFeatureFlagsTenantId",
    "method": "GET",
    "path": "/feature-flags/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FeatureFlagController@index",
    "unverifiedResponse": true
  },
  {
    "name": "FeatureFlagShowGetFeatureFlagsTenantIdId",
    "method": "GET",
    "path": "/feature-flags/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FeatureFlagController@show",
    "unverifiedResponse": true
  },
  {
    "name": "FeatureFlagUpdatePatchFeatureFlagsTenantIdId",
    "method": "PATCH",
    "path": "/feature-flags/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FeatureFlagController@update",
    "unverifiedResponse": true
  },
  {
    "name": "FeatureFlagDestroyDeleteFeatureFlagsTenantIdId",
    "method": "DELETE",
    "path": "/feature-flags/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FeatureFlagController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "FormStorePostForms",
    "method": "POST",
    "path": "/forms",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FormController@store",
    "unverifiedResponse": true
  },
  {
    "name": "FormIndexGetFormsTenantId",
    "method": "GET",
    "path": "/forms/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FormController@index",
    "unverifiedResponse": true
  },
  {
    "name": "FormShowGetFormsTenantIdId",
    "method": "GET",
    "path": "/forms/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FormController@show",
    "unverifiedResponse": true
  },
  {
    "name": "FormUpdatePatchFormsTenantIdId",
    "method": "PATCH",
    "path": "/forms/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FormController@update",
    "unverifiedResponse": true
  },
  {
    "name": "FormDestroyDeleteFormsTenantIdId",
    "method": "DELETE",
    "path": "/forms/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\FormController@destroy",
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
    "name": "ImportStartPostImports",
    "method": "POST",
    "path": "/imports",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@start",
    "unverifiedResponse": true
  },
  {
    "name": "ImportDetectDuplicatesPostImportsDetectDuplicates",
    "method": "POST",
    "path": "/imports/detect-duplicates",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@detectDuplicates",
    "unverifiedResponse": true
  },
  {
    "name": "ImportPreviewPostImportsPreview",
    "method": "POST",
    "path": "/imports/preview",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@preview",
    "unverifiedResponse": true
  },
  {
    "name": "ImportValidatePostImportsValidate",
    "method": "POST",
    "path": "/imports/validate",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@validate",
    "unverifiedResponse": true
  },
  {
    "name": "ImportIndexGetImportsTenantId",
    "method": "GET",
    "path": "/imports/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@index",
    "unverifiedResponse": true
  },
  {
    "name": "ImportShowGetImportsTenantIdId",
    "method": "GET",
    "path": "/imports/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@show",
    "unverifiedResponse": true
  },
  {
    "name": "ImportLogsGetImportsTenantIdIdLogs",
    "method": "GET",
    "path": "/imports/{tenantId}/{id}/logs",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@logs",
    "unverifiedResponse": true
  },
  {
    "name": "ImportProcessPostImportsTenantIdIdProcess",
    "method": "POST",
    "path": "/imports/{tenantId}/{id}/process",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@process",
    "unverifiedResponse": true
  },
  {
    "name": "ImportRollbackPostImportsTenantIdIdRollback",
    "method": "POST",
    "path": "/imports/{tenantId}/{id}/rollback",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ImportController@rollback",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryStorePostIndustries",
    "method": "POST",
    "path": "/industries",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryController@store",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryIndexGetIndustriesTenantId",
    "method": "GET",
    "path": "/industries/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryController@index",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryShowGetIndustriesTenantIdId",
    "method": "GET",
    "path": "/industries/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryController@show",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryUpdatePatchIndustriesTenantIdId",
    "method": "PATCH",
    "path": "/industries/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryController@update",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryDestroyDeleteIndustriesTenantIdId",
    "method": "DELETE",
    "path": "/industries/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryTemplateStorePostIndustryTemplates",
    "method": "POST",
    "path": "/industry-templates",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryTemplateController@store",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryTemplateIndexGetIndustryTemplatesTenantId",
    "method": "GET",
    "path": "/industry-templates/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryTemplateController@index",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryTemplateShowGetIndustryTemplatesTenantIdId",
    "method": "GET",
    "path": "/industry-templates/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryTemplateController@show",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryTemplateUpdatePatchIndustryTemplatesTenantIdId",
    "method": "PATCH",
    "path": "/industry-templates/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryTemplateController@update",
    "unverifiedResponse": true
  },
  {
    "name": "IndustryTemplateDestroyDeleteIndustryTemplatesTenantIdId",
    "method": "DELETE",
    "path": "/industry-templates/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\IndustryTemplateController@destroy",
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
    "name": "LocationTypeStorePostLocationTypes",
    "method": "POST",
    "path": "/location-types",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationTypeController@store",
    "unverifiedResponse": true
  },
  {
    "name": "LocationTypeIndexGetLocationTypesTenantId",
    "method": "GET",
    "path": "/location-types/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationTypeController@index",
    "unverifiedResponse": true
  },
  {
    "name": "LocationTypeShowGetLocationTypesTenantIdId",
    "method": "GET",
    "path": "/location-types/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationTypeController@show",
    "unverifiedResponse": true
  },
  {
    "name": "LocationTypeUpdatePatchLocationTypesTenantIdId",
    "method": "PATCH",
    "path": "/location-types/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationTypeController@update",
    "unverifiedResponse": true
  },
  {
    "name": "LocationTypeDestroyDeleteLocationTypesTenantIdId",
    "method": "DELETE",
    "path": "/location-types/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationTypeController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "LocationStorePostLocations",
    "method": "POST",
    "path": "/locations",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationController@store",
    "unverifiedResponse": true
  },
  {
    "name": "LocationIndexGetLocationsTenantId",
    "method": "GET",
    "path": "/locations/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationController@index",
    "unverifiedResponse": true
  },
  {
    "name": "LocationShowGetLocationsTenantIdId",
    "method": "GET",
    "path": "/locations/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationController@show",
    "unverifiedResponse": true
  },
  {
    "name": "LocationUpdatePatchLocationsTenantIdId",
    "method": "PATCH",
    "path": "/locations/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationController@update",
    "unverifiedResponse": true
  },
  {
    "name": "LocationDestroyDeleteLocationsTenantIdId",
    "method": "DELETE",
    "path": "/locations/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\LocationController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "MeasurementPlanStorePostMeasurementPlans",
    "method": "POST",
    "path": "/measurement-plans",
    "permissions": [
      "read",
      "create"
    ],
    "controller": "App\\Http\\Controllers\\Api\\MeasurementPlanController@store",
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
    "name": "ModuleStorePostModules",
    "method": "POST",
    "path": "/modules",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ModuleController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ModuleIndexGetModulesTenantId",
    "method": "GET",
    "path": "/modules/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ModuleController@index",
    "unverifiedResponse": true
  },
  {
    "name": "ModuleShowGetModulesTenantIdId",
    "method": "GET",
    "path": "/modules/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ModuleController@show",
    "unverifiedResponse": true
  },
  {
    "name": "ModuleUpdatePatchModulesTenantIdId",
    "method": "PATCH",
    "path": "/modules/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ModuleController@update",
    "unverifiedResponse": true
  },
  {
    "name": "ModuleDestroyDeleteModulesTenantIdId",
    "method": "DELETE",
    "path": "/modules/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ModuleController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "NavigationStorePostNavigation",
    "method": "POST",
    "path": "/navigation",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NavigationController@store",
    "unverifiedResponse": true
  },
  {
    "name": "NavigationIndexGetNavigationTenantId",
    "method": "GET",
    "path": "/navigation/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NavigationController@index",
    "unverifiedResponse": true
  },
  {
    "name": "NavigationShowGetNavigationTenantIdId",
    "method": "GET",
    "path": "/navigation/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NavigationController@show",
    "unverifiedResponse": true
  },
  {
    "name": "NavigationUpdatePatchNavigationTenantIdId",
    "method": "PATCH",
    "path": "/navigation/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NavigationController@update",
    "unverifiedResponse": true
  },
  {
    "name": "NavigationDestroyDeleteNavigationTenantIdId",
    "method": "DELETE",
    "path": "/navigation/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\NavigationController@destroy",
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
    "name": "OnboardingIndexGetOnboardingTenantId",
    "method": "GET",
    "path": "/onboarding/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@index",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingStartPostOnboardingTenantIdStart",
    "method": "POST",
    "path": "/onboarding/{tenantId}/start",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@start",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingShowGetOnboardingTenantIdId",
    "method": "GET",
    "path": "/onboarding/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@show",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingAbandonPostOnboardingTenantIdIdAbandon",
    "method": "POST",
    "path": "/onboarding/{tenantId}/{id}/abandon",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@abandon",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingActivatePostOnboardingTenantIdIdActivate",
    "method": "POST",
    "path": "/onboarding/{tenantId}/{id}/activate",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@activate",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingCompleteStepPostOnboardingTenantIdIdCompleteStep",
    "method": "POST",
    "path": "/onboarding/{tenantId}/{id}/complete-step",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@completeStep",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingGetNextStepGetOnboardingTenantIdIdNextStep",
    "method": "GET",
    "path": "/onboarding/{tenantId}/{id}/next-step",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@getNextStep",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingReadinessGetOnboardingTenantIdIdReadiness",
    "method": "GET",
    "path": "/onboarding/{tenantId}/{id}/readiness",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@readiness",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingRunReadinessChecksPostOnboardingTenantIdIdReadinessRun",
    "method": "POST",
    "path": "/onboarding/{tenantId}/{id}/readiness/run",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@runReadinessChecks",
    "unverifiedResponse": true
  },
  {
    "name": "OnboardingValidateStepPostOnboardingTenantIdIdValidateStep",
    "method": "POST",
    "path": "/onboarding/{tenantId}/{id}/validate-step",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OnboardingController@validateStep",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationConfigStorePostOrganizationConfigs",
    "method": "POST",
    "path": "/organization-configs",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationConfigController@store",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationConfigIndexGetOrganizationConfigsTenantId",
    "method": "GET",
    "path": "/organization-configs/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationConfigController@index",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationConfigShowGetOrganizationConfigsTenantIdId",
    "method": "GET",
    "path": "/organization-configs/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationConfigController@show",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationConfigUpdatePatchOrganizationConfigsTenantIdId",
    "method": "PATCH",
    "path": "/organization-configs/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationConfigController@update",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationConfigDestroyDeleteOrganizationConfigsTenantIdId",
    "method": "DELETE",
    "path": "/organization-configs/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationConfigController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationModuleStorePostOrganizationModules",
    "method": "POST",
    "path": "/organization-modules",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationModuleController@store",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationModuleIndexGetOrganizationModulesTenantId",
    "method": "GET",
    "path": "/organization-modules/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationModuleController@index",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationModuleShowGetOrganizationModulesTenantIdId",
    "method": "GET",
    "path": "/organization-modules/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationModuleController@show",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationModuleUpdatePatchOrganizationModulesTenantIdId",
    "method": "PATCH",
    "path": "/organization-modules/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationModuleController@update",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationModuleDestroyDeleteOrganizationModulesTenantIdId",
    "method": "DELETE",
    "path": "/organization-modules/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationModuleController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationTypeStorePostOrganizationTypes",
    "method": "POST",
    "path": "/organization-types",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationTypeController@store",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationTypeIndexGetOrganizationTypesTenantId",
    "method": "GET",
    "path": "/organization-types/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationTypeController@index",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationTypeShowGetOrganizationTypesTenantIdId",
    "method": "GET",
    "path": "/organization-types/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationTypeController@show",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationTypeUpdatePatchOrganizationTypesTenantIdId",
    "method": "PATCH",
    "path": "/organization-types/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationTypeController@update",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationTypeDestroyDeleteOrganizationTypesTenantIdId",
    "method": "DELETE",
    "path": "/organization-types/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationTypeController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUnitStorePostOrganizationUnits",
    "method": "POST",
    "path": "/organization-units",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationUnitController@store",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUnitIndexGetOrganizationUnitsTenantId",
    "method": "GET",
    "path": "/organization-units/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationUnitController@index",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUnitHierarchyGetOrganizationUnitsTenantIdHierarchy",
    "method": "GET",
    "path": "/organization-units/{tenantId}/hierarchy",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationUnitController@hierarchy",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUnitTreeGetOrganizationUnitsTenantIdTree",
    "method": "GET",
    "path": "/organization-units/{tenantId}/tree",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationUnitController@tree",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUnitShowGetOrganizationUnitsTenantIdId",
    "method": "GET",
    "path": "/organization-units/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationUnitController@show",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUnitUpdatePatchOrganizationUnitsTenantIdId",
    "method": "PATCH",
    "path": "/organization-units/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationUnitController@update",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationUnitDestroyDeleteOrganizationUnitsTenantIdId",
    "method": "DELETE",
    "path": "/organization-units/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationUnitController@destroy",
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
    "name": "OrganizationDataQualityGetOrganizationsTenantIdIdDataQuality",
    "method": "GET",
    "path": "/organizations/{tenantId}/{id}/data-quality",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@dataQuality",
    "unverifiedResponse": true
  },
  {
    "name": "OrganizationStructureGetOrganizationsTenantIdIdStructure",
    "method": "GET",
    "path": "/organizations/{tenantId}/{id}/structure",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\OrganizationController@structure",
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
    "name": "PersonCompetencyStorePostPersonCompetencies",
    "method": "POST",
    "path": "/person-competencies",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonCompetencyController@store",
    "unverifiedResponse": true
  },
  {
    "name": "PersonCompetencyIndexGetPersonCompetenciesTenantId",
    "method": "GET",
    "path": "/person-competencies/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonCompetencyController@index",
    "unverifiedResponse": true
  },
  {
    "name": "PersonCompetencyShowGetPersonCompetenciesTenantIdId",
    "method": "GET",
    "path": "/person-competencies/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonCompetencyController@show",
    "unverifiedResponse": true
  },
  {
    "name": "PersonCompetencyUpdatePatchPersonCompetenciesTenantIdId",
    "method": "PATCH",
    "path": "/person-competencies/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonCompetencyController@update",
    "unverifiedResponse": true
  },
  {
    "name": "PersonCompetencyDestroyDeletePersonCompetenciesTenantIdId",
    "method": "DELETE",
    "path": "/person-competencies/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonCompetencyController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "PersonRoleStorePostPersonRoles",
    "method": "POST",
    "path": "/person-roles",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonRoleController@store",
    "unverifiedResponse": true
  },
  {
    "name": "PersonRoleIndexGetPersonRolesTenantId",
    "method": "GET",
    "path": "/person-roles/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonRoleController@index",
    "unverifiedResponse": true
  },
  {
    "name": "PersonRoleShowGetPersonRolesTenantIdId",
    "method": "GET",
    "path": "/person-roles/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonRoleController@show",
    "unverifiedResponse": true
  },
  {
    "name": "PersonRoleUpdatePatchPersonRolesTenantIdId",
    "method": "PATCH",
    "path": "/person-roles/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonRoleController@update",
    "unverifiedResponse": true
  },
  {
    "name": "PersonRoleDestroyDeletePersonRolesTenantIdId",
    "method": "DELETE",
    "path": "/person-roles/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonRoleController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "PersonSkillStorePostPersonSkills",
    "method": "POST",
    "path": "/person-skills",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonSkillController@store",
    "unverifiedResponse": true
  },
  {
    "name": "PersonSkillIndexGetPersonSkillsTenantId",
    "method": "GET",
    "path": "/person-skills/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonSkillController@index",
    "unverifiedResponse": true
  },
  {
    "name": "PersonSkillShowGetPersonSkillsTenantIdId",
    "method": "GET",
    "path": "/person-skills/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonSkillController@show",
    "unverifiedResponse": true
  },
  {
    "name": "PersonSkillUpdatePatchPersonSkillsTenantIdId",
    "method": "PATCH",
    "path": "/person-skills/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonSkillController@update",
    "unverifiedResponse": true
  },
  {
    "name": "PersonSkillDestroyDeletePersonSkillsTenantIdId",
    "method": "DELETE",
    "path": "/person-skills/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PersonSkillController@destroy",
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
    "name": "PositionStorePostPositions",
    "method": "POST",
    "path": "/positions",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PositionController@store",
    "unverifiedResponse": true
  },
  {
    "name": "PositionIndexGetPositionsTenantId",
    "method": "GET",
    "path": "/positions/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PositionController@index",
    "unverifiedResponse": true
  },
  {
    "name": "PositionShowGetPositionsTenantIdId",
    "method": "GET",
    "path": "/positions/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PositionController@show",
    "unverifiedResponse": true
  },
  {
    "name": "PositionUpdatePatchPositionsTenantIdId",
    "method": "PATCH",
    "path": "/positions/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PositionController@update",
    "unverifiedResponse": true
  },
  {
    "name": "PositionDestroyDeletePositionsTenantIdId",
    "method": "DELETE",
    "path": "/positions/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\PositionController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "ReadinessCheckStorePostReadinessChecks",
    "method": "POST",
    "path": "/readiness-checks",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReadinessCheckController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ReadinessCheckIndexGetReadinessChecksTenantId",
    "method": "GET",
    "path": "/readiness-checks/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReadinessCheckController@index",
    "unverifiedResponse": true
  },
  {
    "name": "ReadinessCheckShowGetReadinessChecksTenantIdId",
    "method": "GET",
    "path": "/readiness-checks/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReadinessCheckController@show",
    "unverifiedResponse": true
  },
  {
    "name": "ReadinessCheckUpdatePatchReadinessChecksTenantIdId",
    "method": "PATCH",
    "path": "/readiness-checks/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReadinessCheckController@update",
    "unverifiedResponse": true
  },
  {
    "name": "ReadinessCheckDestroyDeleteReadinessChecksTenantIdId",
    "method": "DELETE",
    "path": "/readiness-checks/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReadinessCheckController@destroy",
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
    "name": "ReportingStructureStorePostReportingStructures",
    "method": "POST",
    "path": "/reporting-structures",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReportingStructureController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ReportingStructureIndexGetReportingStructuresTenantId",
    "method": "GET",
    "path": "/reporting-structures/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReportingStructureController@index",
    "unverifiedResponse": true
  },
  {
    "name": "ReportingStructureShowGetReportingStructuresTenantIdId",
    "method": "GET",
    "path": "/reporting-structures/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReportingStructureController@show",
    "unverifiedResponse": true
  },
  {
    "name": "ReportingStructureUpdatePatchReportingStructuresTenantIdId",
    "method": "PATCH",
    "path": "/reporting-structures/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReportingStructureController@update",
    "unverifiedResponse": true
  },
  {
    "name": "ReportingStructureDestroyDeleteReportingStructuresTenantIdId",
    "method": "DELETE",
    "path": "/reporting-structures/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ReportingStructureController@destroy",
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
    "name": "RoleStorePostRoles",
    "method": "POST",
    "path": "/roles",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RoleController@store",
    "unverifiedResponse": true
  },
  {
    "name": "RoleIndexGetRolesTenantId",
    "method": "GET",
    "path": "/roles/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RoleController@index",
    "unverifiedResponse": true
  },
  {
    "name": "RoleShowGetRolesTenantIdId",
    "method": "GET",
    "path": "/roles/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RoleController@show",
    "unverifiedResponse": true
  },
  {
    "name": "RoleUpdatePatchRolesTenantIdId",
    "method": "PATCH",
    "path": "/roles/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RoleController@update",
    "unverifiedResponse": true
  },
  {
    "name": "RoleDestroyDeleteRolesTenantIdId",
    "method": "DELETE",
    "path": "/roles/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\RoleController@destroy",
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
    "name": "SignalGeneratePostSignalsGenerate",
    "method": "POST",
    "path": "/signals/generate",
    "permissions": [
      "read",
      "events.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SignalController@generate",
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
    "name": "SkillStorePostSkills",
    "method": "POST",
    "path": "/skills",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SkillController@store",
    "unverifiedResponse": true
  },
  {
    "name": "SkillIndexGetSkillsTenantId",
    "method": "GET",
    "path": "/skills/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SkillController@index",
    "unverifiedResponse": true
  },
  {
    "name": "SkillShowGetSkillsTenantIdId",
    "method": "GET",
    "path": "/skills/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SkillController@show",
    "unverifiedResponse": true
  },
  {
    "name": "SkillUpdatePatchSkillsTenantIdId",
    "method": "PATCH",
    "path": "/skills/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SkillController@update",
    "unverifiedResponse": true
  },
  {
    "name": "SkillDestroyDeleteSkillsTenantIdId",
    "method": "DELETE",
    "path": "/skills/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\SkillController@destroy",
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
    "name": "TemplateOverrideStorePostTemplateOverrides",
    "method": "POST",
    "path": "/template-overrides",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TemplateOverrideController@store",
    "unverifiedResponse": true
  },
  {
    "name": "TemplateOverrideIndexGetTemplateOverridesTenantId",
    "method": "GET",
    "path": "/template-overrides/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TemplateOverrideController@index",
    "unverifiedResponse": true
  },
  {
    "name": "TemplateOverrideShowGetTemplateOverridesTenantIdId",
    "method": "GET",
    "path": "/template-overrides/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TemplateOverrideController@show",
    "unverifiedResponse": true
  },
  {
    "name": "TemplateOverrideUpdatePatchTemplateOverridesTenantIdId",
    "method": "PATCH",
    "path": "/template-overrides/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TemplateOverrideController@update",
    "unverifiedResponse": true
  },
  {
    "name": "TemplateOverrideDestroyDeleteTemplateOverridesTenantIdId",
    "method": "DELETE",
    "path": "/template-overrides/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TemplateOverrideController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "TerminologyStorePostTerminology",
    "method": "POST",
    "path": "/terminology",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TerminologyController@store",
    "unverifiedResponse": true
  },
  {
    "name": "TerminologyIndexGetTerminologyTenantId",
    "method": "GET",
    "path": "/terminology/{tenantId}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TerminologyController@index",
    "unverifiedResponse": true
  },
  {
    "name": "TerminologyShowGetTerminologyTenantIdId",
    "method": "GET",
    "path": "/terminology/{tenantId}/{id}",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TerminologyController@show",
    "unverifiedResponse": true
  },
  {
    "name": "TerminologyUpdatePatchTerminologyTenantIdId",
    "method": "PATCH",
    "path": "/terminology/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TerminologyController@update",
    "unverifiedResponse": true
  },
  {
    "name": "TerminologyDestroyDeleteTerminologyTenantIdId",
    "method": "DELETE",
    "path": "/terminology/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\TerminologyController@destroy",
    "unverifiedResponse": true
  },
  {
    "name": "ThemeStorePostThemes",
    "method": "POST",
    "path": "/themes",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ThemeController@store",
    "unverifiedResponse": true
  },
  {
    "name": "ThemeIndexGetThemesTenantId",
    "method": "GET",
    "path": "/themes/{tenantId}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ThemeController@index",
    "unverifiedResponse": true
  },
  {
    "name": "ThemeShowGetThemesTenantIdId",
    "method": "GET",
    "path": "/themes/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ThemeController@show",
    "unverifiedResponse": true
  },
  {
    "name": "ThemeUpdatePatchThemesTenantIdId",
    "method": "PATCH",
    "path": "/themes/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ThemeController@update",
    "unverifiedResponse": true
  },
  {
    "name": "ThemeDestroyDeleteThemesTenantIdId",
    "method": "DELETE",
    "path": "/themes/{tenantId}/{id}",
    "permissions": [
      "read",
      "settings.manage"
    ],
    "controller": "App\\Http\\Controllers\\Api\\ThemeController@destroy",
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
    "name": "WorkspaceHomeMetricsGetWorkspaceTenantIdHomeMetrics",
    "method": "GET",
    "path": "/workspace/{tenantId}/home-metrics",
    "permissions": [
      "read"
    ],
    "controller": "App\\Http\\Controllers\\Api\\WorkspaceController@homeMetrics",
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
