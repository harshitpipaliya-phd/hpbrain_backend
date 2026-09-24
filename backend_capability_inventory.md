# Backend Capability Inventory

**Generated:** 2026-08-03  
**Repository:** hpBrain Backend Laravel Application

---

## Executive Summary

### Metrics Overview

| Metric | Count |
|--------|-------|
| Total Modules | 15 |
| Total Submodules | 42 |
| Total Capabilities | 186 |
| Individual-Level Capabilities | 18 |
| Department-Level Capabilities | 12 |
| Organization-Level Capabilities | 156 |
| API Endpoints Reviewed | 156 |
| Roles | 5 |
| Permissions | 11 |
| Policies | 0 (using middleware-based authorization) |
| Gates | 3 (middleware: jwt, tenant, permission) |
| Security Findings | 8 |
| Unverified Features | 5 |

### Access Scope Distribution

| Scope | Count | Percentage |
|-------|-------|------------|
| Organization | 156 | 83.9% |
| Individual | 18 | 9.7% |
| Department | 12 | 6.4% |

---

## Authorization Architecture

### Middleware Stack

The application uses a three-layer authorization model:

1. **AuthenticateJwt** (`app/Http/Middleware/AuthenticateJwt.php:23-55`)
   - Validates Bearer token JWT authentication
   - Extracts userId, tenantId, and role from JWT claims
   - Rejects malformed or invalid tokens with 401

2. **EnsureTenantScope** (`app/Http/Middleware/EnsureTenantScope.php:44-101`)
   - Enforces tenant isolation
   - Allows admins to cross-reference other organizations
   - Validates organization existence before allowing access
   - Fails closed when ERP is unreachable

3. **RequirePermission** (`app/Http/Middleware/RequirePermission.php:28-124`)
   - Validates role-based permissions
   - Audits all authorization denials
   - Fails closed on unknown roles or permissions

### Roles and Permissions

#### Roles (`app/Domain/Authorization/Role.php:22-68`)

| Role | Key | Description |
|------|-----|-------------|
| Viewer | `viewer` | Read-only access to all tenant data |
| Analyst | `analyst` | Read, create, update, evidence curation |
| Manager | `manager` | Analyst + decision approval + ESO execution |
| Admin | `admin` | All permissions, cross-tenant access |
| Tenant Admin | `tenant_admin` | Same as admin within tenant |

#### Permissions (`app/Domain/Authorization/Permission.php:7-26`)

| Permission | Key | Granted To |
|------------|-----|-----------|
| Read | `read` | All roles |
| Create | `create` | Analyst, Manager, Admin, Tenant Admin |
| Update | `update` | Analyst, Manager, Admin, Tenant Admin |
| Delete | `delete` | Manager, Admin, Tenant Admin |
| Evidence Curate | `evidence.curate` | Analyst, Manager, Admin, Tenant Admin |
| Decision Approve | `decision.approve` | Manager, Admin, Tenant Admin |
| ESO Execute | `eso.execute` | Manager, Admin, Tenant Admin |
| Settings Manage | `settings.manage` | Admin, Tenant Admin |
| API Key Manage | `apikey.manage` | Admin, Tenant Admin |
| Events Manage | `events.manage` | Admin, Tenant Admin |
| Tenant Manage | `tenant.manage` | Admin only |

---

## Module Overview

| Module | Description | Submodules | Routes |
|--------|-------------|------------|--------|
| Authentication | User login, logout, token management | 4 | 4 |
| Organization | Organization management and configuration | 6 | 10 |
| Foundation | Departments, People, Capabilities | 3 | 18 |
| Intelligence Loop | Signals, Evidence, Cases, Reasoning | 6 | 35 |
| Decisions | Recommendations, Decisions, Approvals | 2 | 8 |
| Execution | ESO Executions, Executors, Measurement Plans | 3 | 12 |
| Learning | Outcomes, Learnings, Knowledge Library | 3 | 10 |
| Analytics | Reporting, Dashboards, Metrics | 4 | 12 |
| AI Services | Providers, Templates, Workspace | 6 | 18 |
| Configuration | Settings, Branding, Themes, Forms | 5 | 20 |
| Taxonomy | Roles, Skills, Competencies, Positions | 5 | 20 |
| Data Management | Import, Export, Onboarding | 3 | 16 |
| Observability | Health checks, Metrics, Events | 3 | 12 |
| Conversations | Chat sessions, Messages, Templates | 2 | 12 |

---

## Capability Matrix

### Module 1: Authentication

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| Login | Authenticate with email/password | Public | - | - | POST /auth/login | AuthController:50 | Confirmed Active |
| Logout | Revoke refresh token | Public | - | - | POST /auth/logout | AuthController:116 | Confirmed Active |
| Refresh Token | Exchange refresh token for new access | Public | - | - | POST /auth/refresh | AuthController:142 | Confirmed Active |
| Change Password | Change own password | Individual | All | - | POST /auth/change-password | AuthController:199 | Confirmed Active |

### Module 2: Organization Management

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Organizations | List organizations | Organization | All | read | GET /organizations/{tenantId} | OrganizationController:28 | Confirmed Active |
| Create Organization | Create new organization | Organization | Admin | tenant.manage | POST /organizations | OrganizationController:46 | Confirmed Active |
| View Organization | Get organization details | Organization | All | read | GET /organizations/{tenantId}/{id} | OrganizationController:35 | Confirmed Active |
| Update Organization | Update organization info | Organization | All | update | PATCH /organizations/{tenantId}/{id} | OrganizationController:71 | Confirmed Active |
| Archive Organization | Soft delete organization | Organization | All | update | POST /organizations/{tenantId}/{id}/archive | OrganizationController:103 | Confirmed Active |
| View Organization Audit | View organization audit log | Organization | All | read | GET /organizations/{tenantId}/{id}/audit | OrganizationController:61 | Confirmed Active |
| View Organization Structure | View org hierarchy | Organization | All | read | GET /organizations/{tenantId}/{id}/structure | OrganizationController:116 | Confirmed Active |
| View Data Quality | View data quality metrics | Organization | All | read | GET /organizations/{tenantId}/{id}/data-quality | OrganizationController:163 | Confirmed Active |
| View Organization Types | List organization types | Organization | All | read | GET /organization-types/{tenantId} | OrganizationTypeController | Confirmed Active |
| Manage Organization Units | Manage org units | Organization | Admin | settings.manage | POST /organization-units | OrganizationUnitController | Confirmed Active |

### Module 3: Foundation - Departments

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Departments | List departments | Department | All | read | GET /departments/{tenantId} | DepartmentController | Confirmed Active |
| Create Department | Create new department | Department | All | create | POST /departments | DepartmentController | Confirmed Active |
| View Department | Get department details | Department | All | read | GET /departments/{tenantId}/{id} | DepartmentController | Confirmed Active |
| Update Department | Update department | Department | All | update | PATCH /departments/{tenantId}/{id} | DepartmentController | Confirmed Active |

### Module 4: Foundation - People

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| Search People | Search people in organization | Organization | All | read | GET /people/{tenantId}/search | PersonController | Confirmed Active |
| View People | List people | Organization | All | read | GET /people/{tenantId} | PersonController | Confirmed Active |
| Create Person | Create person record | Organization | All | create | POST /people | PersonController | Confirmed Active |
| View Person | Get person details | Organization | All | read | GET /people/{tenantId}/{id} | PersonController | Confirmed Active |

### Module 5: Foundation - Capabilities

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Capabilities | List capabilities | Organization | All | read | GET /capabilities/{tenantId} | CapabilityController | Confirmed Active |
| Search Capabilities | Search capabilities | Organization | All | read | GET /capabilities/{tenantId}/search | CapabilityController | Confirmed Active |
| Create Capability | Create new capability | Organization | All | create | POST /capabilities | CapabilityController | Confirmed Active |
| View Capability | Get capability details | Organization | All | read | GET /capabilities/{tenantId}/{id} | CapabilityController | Confirmed Active |
| Update Capability | Update capability | Organization | All | update | PATCH /capabilities/{tenantId}/{id} | CapabilityController | Confirmed Active |
| Archive Capability | Archive capability | Organization | All | update | POST /capabilities/{tenantId}/{id}/archive | CapabilityController | Confirmed Active |
| Assign Capability | Assign to person | Organization | All | update | POST /capabilities/{tenantId}/{id}/assign | CapabilityController | Confirmed Active |
| View Assignments | View capability assignments | Organization | All | read | GET /capabilities/{tenantId}/{id}/assignments | CapabilityController | Confirmed Active |

### Module 6: Intelligence Loop - Signals

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Signals | List signals | Organization | All | read | GET /signals/{tenantId} | SignalController:24 | Confirmed Active |
| Create Signal | Record new signal | Organization | All | create | POST /signals | SignalController:36 | Confirmed Active |
| View Signal | Get signal details | Organization | All | read | GET /signals/{tenantId}/{id} | SignalController:29 | Confirmed Active |
| Change Signal Status | Update signal status | Organization | All | update | PATCH /signals/{tenantId}/{id}/status | SignalController:86 | Confirmed Active |
| Generate Signals | AI signal generation | Organization | All | read | POST /signals/generate | SignalController:97 | Confirmed Active |

### Module 7: Intelligence Loop - Evidence

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Evidence | List evidence | Organization | All | read | GET /evidence/{tenantId} | EvidenceController:37 | Confirmed Active |
| Create Evidence | Record new evidence | Organization | All | create | POST /evidence | EvidenceController:53 | Confirmed Active |
| View Evidence | Get evidence details | Organization | All | read | GET /evidence/{tenantId}/{id} | EvidenceController:44 | Confirmed Active |
| View Evidence for Signal | List evidence for signal | Organization | All | read | GET /evidence/{tenantId}/signal/{signalId} | EvidenceController:148 | Confirmed Active |
| Summarize Evidence | AI summarization | Organization | All | read | POST /ai/summarize-evidence | AiController:92 | Confirmed Active |

### Module 8: Intelligence Loop - Cases

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Cases | List cases | Organization | All | read | GET /cases/{tenantId} | CaseController:26 | Confirmed Active |
| Create Case | Create investigation case | Organization | All | create | POST /cases | CaseController:31 | Confirmed Active |
| View Case | Get case details | Organization | All | read | GET /cases/{tenantId}/{id} | CaseController:73 | Confirmed Active |
| Transition Case | Change case status | Organization | All | update | PATCH /cases/{tenantId}/{id}/transition | CaseController:93 | Confirmed Active |
| Attach Evidence | Link evidence to case | Organization | All | update | POST /cases/{tenantId}/{id}/evidence | CaseController:118 | Confirmed Active |
| View Case Evidence | Get case evidence | Organization | All | read | GET /cases/{tenantId}/{id}/evidence | CaseController:80 | Confirmed Active |

### Module 9: Intelligence Loop - Hypotheses

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Hypotheses | List hypotheses for case | Organization | All | read | GET /hypotheses/{tenantId}/case/{caseId} | HypothesisController:25 | Confirmed Active |
| Create Hypothesis | Record hypothesis | Organization | All | create | POST /hypotheses | HypothesisController:32 | Confirmed Active |
| Set Hypothesis Status | Update hypothesis status | Organization | All | update | POST /hypotheses/{tenantId}/{id}/status | HypothesisController:95 | Confirmed Active |
| Reject Hypothesis | Reject hypothesis | Organization | All | update | POST /hypotheses/{tenantId}/case/{caseId}/{id}/reject | HypothesisController:57 | Confirmed Active |
| Support Hypothesis | Mark as supported | Organization | All | update | POST /hypotheses/{tenantId}/case/{caseId}/{id}/support | HypothesisController:70 | Confirmed Active |
| Confirm Hypothesis | Confirm hypothesis | Organization | All | update | POST /hypotheses/{tenantId}/case/{caseId}/{id}/confirm | HypothesisController:75 | Confirmed Active |

### Module 10: Intelligence Loop - Reasoning

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Reasoning | List reasoning for signal | Organization | All | read | GET /reasoning/{tenantId}/signal/{signalId} | ReasoningController | Confirmed Active |
| Create Reasoning | Record reasoning step | Organization | All | create | POST /reasoning | ReasoningController | Confirmed Active |
| Get Missing Evidence | Find evidence gaps | Organization | All | read | GET /reasoning-engine/{tenantId}/missing-evidence | ReasoningEngineController | Confirmed Active |
| Find Duplicates | Detect duplicate signals | Organization | All | read | GET /reasoning-engine/{tenantId}/duplicate-signals | ReasoningEngineController | Confirmed Active |
| Get Warnings | Early warnings | Organization | All | read | GET /reasoning-engine/{tenantId}/early-warnings | ReasoningEngineController | Confirmed Active |
| Explain Reasoning | AI explanation | Organization | All | read | POST /reasoning-engine/{tenantId}/explain | ReasoningEngineController | Confirmed Active |
| Assess Situation | AI assessment | Organization | All | read | POST /reasoning-engine/{tenantId}/assess | ReasoningEngineController | Confirmed Active |

### Module 11: Decisions - Recommendations

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Recommendations | List recommendations | Organization | All | read | GET /recommendations/{tenantId} | RecommendationController:31 | Confirmed Active |
| Create Recommendation | Create recommendation | Organization | All | create | POST /recommendations | RecommendationController:47 | Confirmed Active |
| View Recommendation | Get recommendation | Organization | All | read | GET /recommendations/{tenantId}/{id} | RecommendationController:38 | Confirmed Active |
| Get AI Recommendation | AI-generated recommendation | Organization | All | create | POST /reasoning-engine/{tenantId}/recommend | ReasoningEngineController | Confirmed Active |

### Module 12: Decisions - Decision Governance

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Decisions | List decisions | Organization | All | read | GET /decisions/{tenantId} | DecisionController:40 | Confirmed Active |
| Create Decision | Propose decision | Organization | All | create | POST /decisions | DecisionController:56 | Confirmed Active |
| View Decision | Get decision details | Organization | All | read | GET /decisions/{tenantId}/{id} | DecisionController:47 | Confirmed Active |
| Approve Decision | Approve decision (governance) | Organization | Manager+ | decision.approve | POST /decisions/{tenantId}/{id}/approve | DecisionController:117 | Confirmed Active |

### Module 13: Execution - ESO Executions

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Executions | List ESO executions | Organization | All | read | GET /eso-executions/{tenantId} | EsoExecutionController:34 | Confirmed Active |
| Start Execution | Start ESO execution | Organization | Manager+ | eso.execute | POST /eso-executions | EsoExecutionController:67 | Confirmed Active |
| Complete Execution | Mark execution complete | Organization | Manager+ | eso.execute | PATCH /eso-executions/{tenantId}/{id}/transition | EsoExecutionController:180 | Confirmed Active |
| Rollback Execution | Rollback execution | Organization | Manager+ | eso.execute | POST /eso-executions/{tenantId}/{id}/rollback | EsoExecutionController:205 | Confirmed Active |
| View Execution History | Execution history | Organization | All | read | GET /eso-executions/{tenantId}/eso/{esoId} | EsoExecutionController:191 | Confirmed Active |

### Module 14: Execution - Executors

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Executors | List executors | Organization | All | read | GET /executors/{tenantId} | ExecutorController:29 | Confirmed Active |
| Register Executor | Register executor | Organization | All | create | POST /executors | ExecutorController:45 | Confirmed Active |
| View Executor | Get executor details | Organization | All | read | GET /executors/{tenantId}/{id} | ExecutorController:36 | Confirmed Active |

### Module 15: Execution - Measurement Plans

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| Create Measurement Plan | Define metrics | Organization | All | create | POST /measurement-plans | MeasurementPlanController | Confirmed Active |

### Module 16: Learning - Outcomes

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Outcomes | List outcomes | Organization | All | read | GET /outcomes/{tenantId} | OutcomeController:42 | Confirmed Active |
| Record Outcome | Record decision outcome | Organization | All | create | POST /outcomes | OutcomeController:58 | Confirmed Active |
| View Outcome | Get outcome details | Organization | All | read | GET /outcomes/{tenantId}/{id} | OutcomeController:49 | Confirmed Active |

### Module 17: Learning - Knowledge Management

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Learnings | List learnings | Organization | All | read | GET /learnings/{tenantId} | LearningController | Confirmed Active |
| View Reusable Learnings | List reusable patterns | Organization | All | read | GET /learnings/{tenantId}/reusable | LearningController | Confirmed Active |
| Create Learning | Record learning | Organization | All | create | POST /learnings | LearningController | Confirmed Active |
| Search Knowledge Library | Search knowledge base | Organization | All | read | GET /knowledge-library/{tenantId}/search | KnowledgeLibraryController | Confirmed Active |
| View Knowledge Library | List knowledge assets | Organization | All | read | GET /knowledge-library/{tenantId} | KnowledgeLibraryController | Confirmed Active |
| Create Knowledge Asset | Add to knowledge base | Organization | All | create | POST /knowledge-library | KnowledgeLibraryController | Confirmed Active |
| Mark Knowledge Reused | Mark as reused | Organization | All | update | POST /knowledge-library/{tenantId}/{id}/reuse | KnowledgeLibraryController | Confirmed Active |

### Module 18: Analytics - Reports

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Analytics | View analytics dashboard | Organization | All | read | GET /analytics/{tenantId} | AnalyticsController:39 | Confirmed Active |
| Executive Summary | Executive overview | Organization | All | read | GET /analytics/{tenantId}/executive-summary | AnalyticsController:121 | Confirmed Active |
| Decision Intelligence | Decision metrics | Organization | All | read | GET /analytics/{tenantId}/decision-intelligence | AnalyticsController | Confirmed Active |
| Export Decisions CSV | Export decisions | Organization | All | read | GET /analytics/{tenantId}/decisions/export.csv | AnalyticsController | Confirmed Active |
| Organization Report | Org health report | Organization | All | read | GET /analytics/{tenantId}/reports/organization | AnalyticsController | Confirmed Active |
| People Report | People analytics | Organization | All | read | GET /analytics/{tenantId}/reports/people | AnalyticsController:229 | Confirmed Active |
| Intelligence Report | Intelligence metrics | Organization | All | read | GET /analytics/{tenantId}/reports/intelligence | AnalyticsController:295 | Confirmed Active |

### Module 19: Analytics - Dashboards

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Dashboards | List dashboards | Organization | All | read | GET /dashboards/{tenantId} | DashboardController | Confirmed Active |
| Create Dashboard | Create dashboard | Organization | All | create | POST /dashboards | DashboardController | Confirmed Active |
| View Dashboard | Get dashboard | Organization | All | read | GET /dashboards/{tenantId}/{id} | DashboardController | Confirmed Active |
| Update Dashboard | Update dashboard | Organization | All | update | PATCH /dashboards/{tenantId}/{id} | DashboardController | Confirmed Active |
| Delete Dashboard | Delete dashboard | Organization | All | delete | DELETE /dashboards/{tenantId}/{id} | DashboardController | Confirmed Active |

### Module 20: AI Services - Providers

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View AI Providers | List AI providers | Organization | All | read | GET /ai/providers/{tenantId} | AiProviderController | Confirmed Active |
| Create AI Provider | Configure AI provider | Organization | Admin | settings.manage | POST /ai/providers | AiProviderController | Confirmed Active |
| View AI Provider | Get provider details | Organization | All | read | GET /ai/providers/{tenantId}/{id} | AiProviderController | Confirmed Active |
| Update AI Provider | Update provider | Organization | Admin | settings.manage | PATCH /ai/providers/{tenantId}/{id} | AiProviderController | Confirmed Active |
| Delete AI Provider | Remove provider | Organization | Admin | settings.manage | DELETE /ai/providers/{tenantId}/{id} | AiProviderController | Confirmed Active |
| Test AI Provider | Test provider config | Organization | Admin | settings.manage | POST /ai/providers/{tenantId}/{id}/test | AiProviderController | Confirmed Active |
| Activate Provider | Set as active | Organization | Admin | settings.manage | POST /ai/providers/{tenantId}/{id}/activate | AiProviderController | Confirmed Active |

### Module 21: AI Services - Prompt Templates

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Prompt Templates | List templates | Organization | Admin | settings.manage | GET /ai/prompt-templates/{tenantId} | AiPromptTemplateController | Confirmed Active |
| Create Prompt Template | Create template | Organization | Admin | settings.manage | POST /ai/prompt-templates | AiPromptTemplateController | Confirmed Active |
| View Prompt Template | Get template | Organization | Admin | settings.manage | GET /ai/prompt-templates/{tenantId}/{id} | AiPromptTemplateController | Confirmed Active |
| Update Prompt Template | Update template | Organization | Admin | settings.manage | PATCH /ai/prompt-templates/{tenantId}/{id} | AiPromptTemplateController | Confirmed Active |
| Delete Prompt Template | Delete template | Organization | Admin | settings.manage | DELETE /ai/prompt-templates/{tenantId}/{id} | AiPromptTemplateController | Confirmed Active |

### Module 22: AI Services - Evaluations

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View AI Evaluations | List evaluations | Organization | Admin | settings.manage | GET /ai/evaluations/{tenantId} | AiEvaluationController | Confirmed Active |
| Create Evaluation | Create evaluation | Organization | Admin | settings.manage | POST /ai/evaluations | AiEvaluationController | Confirmed Active |
| View Evaluation | Get evaluation | Organization | Admin | settings.manage | GET /ai/evaluations/{tenantId}/{id} | AiEvaluationController | Confirmed Active |
| Run Evaluation | Execute evaluation | Organization | Admin | settings.manage | POST /ai/evaluations/{tenantId}/{id}/run | AiEvaluationController | Confirmed Active |
| View Evaluation Results | Get results | Organization | Admin | settings.manage | GET /ai/evaluations/{tenantId}/{id}/results | AiEvaluationController | Confirmed Active |

### Module 23: AI Services - Feedback

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View AI Feedback | List feedback | Organization | All | read | GET /ai/feedback/{tenantId} | AiFeedbackController | Confirmed Active |
| Submit Feedback | Submit feedback | Organization | All | read | POST /ai/feedback | AiFeedbackController | Confirmed Active |
| View Feedback | Get feedback | Organization | All | read | GET /ai/feedback/{tenantId}/{id} | AiFeedbackController | Confirmed Active |

### Module 24: AI Services - Quotas

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View AI Quotas | List quotas | Organization | Admin | settings.manage | GET /ai/quotas/{tenantId} | AiQuotaController | Confirmed Active |
| Create Quota | Create quota | Organization | Admin | settings.manage | POST /ai/quotas | AiQuotaController | Confirmed Active |
| View Quota | Get quota | Organization | Admin | settings.manage | GET /ai/quotas/{tenantId}/{id} | AiQuotaController | Confirmed Active |
| Update Quota | Update quota | Organization | Admin | settings.manage | PATCH /ai/quotas/{tenantId}/{id} | AiQuotaController | Confirmed Active |
| Reset Quota | Reset usage | Organization | Admin | settings.manage | POST /ai/quotas/{tenantId}/{id}/reset | AiQuotaController | Confirmed Active |

### Module 25: AI Services - Workspace

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Sessions | List workspace sessions | Individual | All | read | GET /ai/workspace/sessions | AiWorkspaceController | Confirmed Active |
| Create Session | Start new session | Individual | All | create | POST /ai/workspace/sessions | AiWorkspaceController | Confirmed Active |
| View Messages | Get session messages | Individual | All | read | GET /ai/workspace/sessions/{sessionId}/messages | AiWorkspaceController | Confirmed Active |
| Send Message | Send message | Individual | All | read | POST /ai/workspace/sessions/{sessionId}/messages | AiWorkspaceController | Confirmed Active |
| Regenerate Response | Regenerate AI response | Individual | All | read | POST /ai/workspace/sessions/{sessionId}/messages/{messageId}/regenerate | AiWorkspaceController | Confirmed Active |
| Explain Response | Get AI explanation | Individual | All | read | POST /ai/workspace/sessions/{sessionId}/messages/{messageId}/explain | AiWorkspaceController | Confirmed Active |
| Get Follow-up | Get follow-up suggestions | Individual | All | read | GET /ai/workspace/sessions/{sessionId}/messages/{messageId}/follow-up | AiWorkspaceController | Confirmed Active |
| View History | Session history | Individual | All | read | GET /ai/workspace/sessions/{sessionId}/history | AiWorkspaceController | Confirmed Active |

### Module 26: Configuration - Settings

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Settings | List settings | Individual/Org | All | read | GET /settings/{tenantId} | SettingsController:31 | Confirmed Active |
| Set Setting | Update setting | Individual/Org | Admin | settings.manage | PUT /settings/{tenantId} | SettingsController:47 | Confirmed Active |

### Module 27: Configuration - Branding

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Branding | List branding | Organization | All | read | GET /branding/{tenantId} | BrandingController | Confirmed Active |
| Create Branding | Create branding | Organization | Admin | settings.manage | POST /branding | BrandingController | Confirmed Active |
| Update Branding | Update branding | Organization | Admin | settings.manage | PATCH /branding/{tenantId}/{id} | BrandingController | Confirmed Active |
| Delete Branding | Delete branding | Organization | Admin | settings.manage | DELETE /branding/{tenantId}/{id} | BrandingController | Confirmed Active |

### Module 28: Configuration - Themes

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Themes | List themes | Organization | Admin | settings.manage | GET /themes/{tenantId} | ThemeController | Confirmed Active |
| Create Theme | Create theme | Organization | Admin | settings.manage | POST /themes | ThemeController | Confirmed Active |
| View Theme | Get theme | Organization | Admin | settings.manage | GET /themes/{tenantId}/{id} | ThemeController | Confirmed Active |
| Update Theme | Update theme | Organization | Admin | settings.manage | PATCH /themes/{tenantId}/{id} | ThemeController | Confirmed Active |
| Delete Theme | Delete theme | Organization | Admin | settings.manage | DELETE /themes/{tenantId}/{id} | ThemeController | Confirmed Active |

### Module 29: Configuration - Forms

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Forms | List forms | Organization | All | read | GET /forms/{tenantId} | FormController | Confirmed Active |
| Create Form | Create form | Organization | Admin | settings.manage | POST /forms | FormController | Confirmed Active |
| View Form | Get form | Organization | All | read | GET /forms/{tenantId}/{id} | FormController | Confirmed Active |
| Update Form | Update form | Organization | Admin | settings.manage | PATCH /forms/{tenantId}/{id} | FormController | Confirmed Active |
| Delete Form | Delete form | Organization | Admin | settings.manage | DELETE /forms/{tenantId}/{id} | FormController | Confirmed Active |

### Module 30: Configuration - Config Versions

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Versions | List config versions | Organization | Admin | settings.manage | GET /config-versions/{tenantId} | ConfigVersionController | Confirmed Active |
| Create Version | Create version | Organization | Admin | settings.manage | POST /config-versions | ConfigVersionController | Confirmed Active |
| View Version | Get version | Organization | Admin | settings.manage | GET /config-versions/{tenantId}/{id} | ConfigVersionController | Confirmed Active |
| Activate Version | Activate version | Organization | Admin | settings.manage | POST /config-versions/{tenantId}/{id}/activate | ConfigVersionController | Confirmed Active |
| Rollback Version | Rollback version | Organization | Admin | settings.manage | POST /config-versions/{tenantId}/{id}/rollback | ConfigVersionController | Confirmed Active |

### Module 31: Taxonomy - Roles

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Roles | List roles | Organization | Admin | settings.manage | GET /roles/{tenantId} | RoleController:18 | Confirmed Active |
| Create Role | Create role | Organization | Admin | settings.manage | POST /roles | RoleController:33 | Confirmed Active |
| View Role | Get role | Organization | Admin | settings.manage | GET /roles/{tenantId}/{id} | RoleController:26 | Confirmed Active |
| Update Role | Update role | Organization | Admin | settings.manage | PATCH /roles/{tenantId}/{id} | RoleController:50 | Confirmed Active |
| Delete Role | Delete role | Organization | Admin | settings.manage | DELETE /roles/{tenantId}/{id} | RoleController:67 | Confirmed Active |

### Module 32: Taxonomy - Skills

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Skills | List skills | Organization | Admin | settings.manage | GET /skills/{tenantId} | SkillController | Confirmed Active |
| Create Skill | Create skill | Organization | Admin | settings.manage | POST /skills | SkillController | Confirmed Active |
| View Skill | Get skill | Organization | Admin | settings.manage | GET /skills/{tenantId}/{id} | SkillController | Confirmed Active |
| Update Skill | Update skill | Organization | Admin | settings.manage | PATCH /skills/{tenantId}/{id} | SkillController | Confirmed Active |
| Delete Skill | Delete skill | Organization | Admin | settings.manage | DELETE /skills/{tenantId}/{id} | SkillController | Confirmed Active |

### Module 33: Taxonomy - Competencies

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Competencies | List competencies | Organization | Admin | settings.manage | GET /competencies/{tenantId} | CompetencyController | Confirmed Active |
| Create Competency | Create competency | Organization | Admin | settings.manage | POST /competencies | CompetencyController | Confirmed Active |
| View Competency | Get competency | Organization | Admin | settings.manage | GET /competencies/{tenantId}/{id} | CompetencyController | Confirmed Active |
| Update Competency | Update competency | Organization | Admin | settings.manage | PATCH /competencies/{tenantId}/{id} | CompetencyController | Confirmed Active |
| Delete Competency | Delete competency | Organization | Admin | settings.manage | DELETE /competencies/{tenantId}/{id} | CompetencyController | Confirmed Active |

### Module 34: Data Management - Import

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Imports | List imports | Organization | Admin | settings.manage | GET /imports/{tenantId} | ImportController:21 | Confirmed Active |
| View Import | Get import | Organization | Admin | settings.manage | GET /imports/{tenantId}/{id} | ImportController:30 | Confirmed Active |
| Validate Import | Validate file | Organization | Admin | settings.manage | POST /imports/validate | ImportController:37 | Confirmed Active |
| Preview Import | Preview data | Organization | Admin | settings.manage | POST /imports/preview | ImportController:52 | Confirmed Active |
| Detect Duplicates | Find duplicates | Organization | Admin | settings.manage | POST /imports/detect-duplicates | ImportController:67 | Confirmed Active |
| Start Import | Start import | Organization | Admin | settings.manage | POST /imports | ImportController:80 | Confirmed Active |
| Process Import | Process import | Organization | Admin | settings.manage | POST /imports/{tenantId}/{id}/process | ImportController:98 | Confirmed Active |
| Rollback Import | Rollback import | Organization | Admin | settings.manage | POST /imports/{tenantId}/{id}/rollback | ImportController:105 | Confirmed Active |
| View Import Logs | View logs | Organization | Admin | settings.manage | GET /imports/{tenantId}/{id}/logs | ImportController:112 | Confirmed Active |

### Module 35: Data Management - Onboarding

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Onboarding | List sessions | Organization | Admin | settings.manage | GET /onboarding/{tenantId} | OnboardingController | Confirmed Active |
| Start Onboarding | Start session | Organization | Admin | settings.manage | POST /onboarding/{tenantId}/start | OnboardingController | Confirmed Active |
| View Onboarding Session | Get session | Organization | Admin | settings.manage | GET /onboarding/{tenantId}/{id} | OnboardingController | Confirmed Active |
| Complete Step | Mark step done | Organization | Admin | settings.manage | POST /onboarding/{tenantId}/{id}/complete-step | OnboardingController | Confirmed Active |
| Activate Session | Activate | Organization | Admin | settings.manage | POST /onboarding/{tenantId}/{id}/activate | OnboardingController | Confirmed Active |
| Run Readiness | Run checks | Organization | Admin | settings.manage | POST /onboarding/{tenantId}/{id}/readiness/run | OnboardingController | Confirmed Active |

### Module 36: Observability

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| Health Check | System health | Organization | All | read | GET /observability/health | ObservabilityController | Confirmed Active |
| Database Health | DB connectivity | Organization | All | read | GET /observability/health/database | ObservabilityController | Confirmed Active |
| Event Health | Event system | Organization | All | read | GET /observability/health/events | ObservabilityController | Confirmed Active |
| System Metrics | System metrics | Organization | All | read | GET /observability/metrics/system | ObservabilityController | Confirmed Active |
| Tenant Metrics | Tenant metrics | Organization | All | read | GET /observability/metrics/{tenantId} | ObservabilityController | Confirmed Active |

### Module 37: Conversations

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| Search Sessions | Search conversations | Organization | All | read | GET /conversations/sessions/{tenantId}/search | ConversationController | Confirmed Active |
| View Sessions | List sessions | Organization | All | read | GET /conversations/sessions/{tenantId} | ConversationController | Confirmed Active |
| Create Session | Start session | Organization | All | create | POST /conversations/sessions | ConversationController | Confirmed Active |
| View Messages | Get messages | Organization | All | read | GET /conversations/sessions/{tenantId}/{id}/messages | ConversationController | Confirmed Active |
| Send Message | Send message | Organization | All | create | POST /conversations/sessions/{tenantId}/{id}/messages | ConversationController | Confirmed Active |
| Pin Session | Pin conversation | Organization | All | update | PATCH /conversations/sessions/{tenantId}/{id}/pin | ConversationController | Confirmed Active |
| Rename Session | Rename conversation | Organization | All | update | PATCH /conversations/sessions/{tenantId}/{id}/rename | ConversationController | Confirmed Active |
| Delete Session | Delete conversation | Organization | All | delete | DELETE /conversations/sessions/{tenantId}/{id} | ConversationController | Confirmed Active |

### Module 38: Audit

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Audit Logs | List audit entries | Organization | All | read | GET /audit | AuditController:20 | Confirmed Active |
| View Activity | Recent activity | Organization | All | read | GET /audit/activity | AuditController:37 | Confirmed Active |
| Audit Statistics | Audit stats | Organization | All | read | GET /audit/stats | AuditController:45 | Confirmed Active |

### Module 39: Notifications

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Notifications | List notifications | Individual | All | read | GET /notifications/{tenantId} | NotificationController:34 | Confirmed Active |
| Unread Count | Count unread | Individual | All | read | GET /notifications/{tenantId}/unread-count | NotificationController:45 | Confirmed Active |
| Mark Read | Mark as read | Individual | All | read | PATCH /notifications/{tenantId}/{id}/read | NotificationController:52 | Confirmed Active |
| Mark All Read | Mark all read | Individual | All | read | POST /notifications/{tenantId}/read-all | NotificationController:62 | Confirmed Active |

### Module 40: Events Management

| Capability | Description | Scope | Roles | Permissions | Route | Controller | Status |
|------------|-------------|-------|-------|-------------|-------|------------|--------|
| View Events | List events | Organization | All | read | GET /events | EventController | Confirmed Active |
| Event Statistics | Event stats | Organization | All | read | GET /events/stats/summary | EventController | Confirmed Active |
| View DLQ | Dead letter queue | Organization | All | read | GET /events/dlq | EventController | Confirmed Active |
| Retry Failed | Retry failures | Organization | Admin | events.manage | POST /events/retry/failed | EventController | Confirmed Active |
| Retry DLQ | Retry DLQ item | Organization | Admin | events.manage | POST /events/dlq/{id}/retry | EventController | Confirmed Active |
| Delete DLQ | Delete DLQ item | Organization | Admin | events.manage | DELETE /events/dlq/{id} | EventController | Confirmed Active |
| Replay Event | Replay event | Organization | Admin | events.manage | POST /events/{id}/replay | EventController | Confirmed Active |

---

## API Inventory

### Public Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | /auth/login | Login | None |
| POST | /auth/logout | Logout | None |
| POST | /auth/refresh | Refresh token | None |

### Authenticated Endpoints

| Method | Endpoint | Module | Auth | Permission |
|--------|----------|--------|------|------------|
| POST | /auth/change-password | Auth | JWT | Self |
| GET | /organizations/{tenantId} | Organization | JWT | read |
| POST | /organizations | Organization | JWT | tenant.manage |
| GET | /organizations/{tenantId}/{id} | Organization | JWT | read |
| PATCH | /organizations/{tenantId}/{id} | Organization | JWT | update |
| POST | /organizations/{tenantId}/{id}/archive | Organization | JWT | update |
| GET | /organizations/{tenantId}/{id}/audit | Organization | JWT | read |
| GET | /organizations/{tenantId}/{id}/structure | Organization | JWT | read |
| GET | /organizations/{tenantId}/{id}/data-quality | Organization | JWT | read |
| GET | /departments/{tenantId} | Department | JWT | read |
| POST | /departments | Department | JWT | create |
| GET | /departments/{tenantId}/{id} | Department | JWT | read |
| PATCH | /departments/{tenantId}/{id} | Department | JWT | update |
| GET | /people/{tenantId}/search | Person | JWT | read |
| GET | /people/{tenantId} | Person | JWT | read |
| POST | /people | Person | JWT | create |
| GET | /people/{tenantId}/{id} | Person | JWT | read |
| GET | /capabilities/{tenantId} | Capability | JWT | read |
| GET | /capabilities/{tenantId}/search | Capability | JWT | read |
| POST | /capabilities | Capability | JWT | create |
| GET | /capabilities/{tenantId}/{id} | Capability | JWT | read |
| PATCH | /capabilities/{tenantId}/{id} | Capability | JWT | update |
| POST | /capabilities/{tenantId}/{id}/version | Capability | JWT | create |
| GET | /capabilities/{tenantId}/{id}/versions | Capability | JWT | read |
| POST | /capabilities/{tenantId}/{id}/archive | Capability | JWT | update |
| POST | /capabilities/{tenantId}/{id}/assign | Capability | JWT | update |
| GET | /capabilities/{tenantId}/{id}/assignments | Capability | JWT | read |
| GET | /capabilities/{tenantId}/{id}/audit | Capability | JWT | read |
| GET | /signals/{tenantId} | Signal | JWT | read |
| POST | /signals | Signal | JWT | create |
| GET | /signals/{tenantId}/{id} | Signal | JWT | read |
| PATCH | /signals/{tenantId}/{id}/status | Signal | JWT | update |
| POST | /signals/generate | Signal | JWT | read |
| GET | /evidence/{tenantId} | Evidence | JWT | read |
| POST | /evidence | Evidence | JWT | create |
| GET | /evidence/{tenantId}/{id} | Evidence | JWT | read |
| GET | /evidence/{tenantId}/signal/{signalId} | Evidence | JWT | read |
| GET | /cases/{tenantId} | Case | JWT | read |
| POST | /cases | Case | JWT | create |
| GET | /cases/{tenantId}/{id} | Case | JWT | read |
| PATCH | /cases/{tenantId}/{id}/transition | Case | JWT | update |
| POST | /cases/{tenantId}/{id}/evidence | Case | JWT | update |
| GET | /cases/{tenantId}/{id}/evidence | Case | JWT | read |
| GET | /hypotheses/{tenantId}/case/{caseId} | Hypothesis | JWT | read |
| POST | /hypotheses | Hypothesis | JWT | create |
| POST | /hypotheses/{tenantId}/{id}/status | Hypothesis | JWT | update |
| POST | /hypotheses/{tenantId}/case/{caseId}/{id}/reject | Hypothesis | JWT | update |
| POST | /hypotheses/{tenantId}/case/{caseId}/{id}/support | Hypothesis | JWT | update |
| POST | /hypotheses/{tenantId}/case/{caseId}/{id}/confirm | Hypothesis | JWT | update |
| GET | /reasoning/{tenantId}/signal/{signalId} | Reasoning | JWT | read |
| POST | /reasoning | Reasoning | JWT | create |
| GET | /reasoning-engine/{tenantId}/missing-evidence | Reasoning | JWT | read |
| GET | /reasoning-engine/{tenantId}/duplicate-signals | Reasoning | JWT | read |
| GET | /reasoning-engine/{tenantId}/early-warnings | Reasoning | JWT | read |
| POST | /reasoning-engine/{tenantId}/explain | Reasoning | JWT | read |
| POST | /reasoning-engine/{tenantId}/assess | Reasoning | JWT | read |
| GET | /reasoning-engine/{tenantId}/memory-stats | Reasoning | JWT | read |
| POST | /reasoning-engine/{tenantId}/recommend | Reasoning | JWT | create |
| POST | /reasoning-engine/{tenantId}/evaluate | Reasoning | JWT | create |
| GET | /recommendations/{tenantId} | Recommendation | JWT | read |
| POST | /recommendations | Recommendation | JWT | create |
| GET | /recommendations/{tenantId}/{id} | Recommendation | JWT | read |
| GET | /decisions/{tenantId} | Decision | JWT | read |
| POST | /decisions | Decision | JWT | create |
| GET | /decisions/{tenantId}/{id} | Decision | JWT | read |
| POST | /decisions/{tenantId}/{id}/approve | Decision | JWT | decision.approve |
| GET | /eso-executions/{tenantId} | Execution | JWT | read |
| POST | /eso-executions | Execution | JWT | eso.execute |
| PATCH | /eso-executions/{tenantId}/{id}/transition | Execution | JWT | eso.execute |
| POST | /eso-executions/{tenantId}/{id}/rollback | Execution | JWT | eso.execute |
| GET | /eso-executions/{tenantId}/eso/{esoId} | Execution | JWT | read |
| POST | /measurement-plans | Measurement | JWT | create |
| GET | /executors/{tenantId} | Executor | JWT | read |
| POST | /executors | Executor | JWT | create |
| GET | /executors/{tenantId}/{id} | Executor | JWT | read |
| GET | /outcomes/{tenantId} | Outcome | JWT | read |
| POST | /outcomes | Outcome | JWT | create |
| GET | /outcomes/{tenantId}/{id} | Outcome | JWT | read |
| GET | /learnings/{tenantId}/reusable | Learning | JWT | read |
| GET | /learnings/{tenantId} | Learning | JWT | read |
| POST | /learnings | Learning | JWT | create |
| GET | /knowledge-library/{tenantId}/search | Knowledge | JWT | read |
| GET | /knowledge-library/{tenantId} | Knowledge | JWT | read |
| POST | /knowledge-library | Knowledge | JWT | create |
| POST | /knowledge-library/{tenantId}/{id}/reuse | Knowledge | JWT | update |
| GET | /analytics/{tenantId} | Analytics | JWT | read |
| GET | /analytics/{tenantId}/executive-summary | Analytics | JWT | read |
| GET | /analytics/{tenantId}/decision-intelligence | Analytics | JWT | read |
| GET | /analytics/{tenantId}/decisions/export.csv | Analytics | JWT | read |
| GET | /analytics/{tenantId}/reports/organization | Analytics | JWT | read |
| GET | /analytics/{tenantId}/reports/people | Analytics | JWT | read |
| GET | /analytics/{tenantId}/reports/intelligence | Analytics | JWT | read |
| GET | /ai/providers/{tenantId} | AI Provider | JWT | read |
| POST | /ai/providers | AI Provider | JWT | settings.manage |
| GET | /ai/providers/{tenantId}/{id} | AI Provider | JWT | read |
| PATCH | /ai/providers/{tenantId}/{id} | AI Provider | JWT | settings.manage |
| DELETE | /ai/providers/{tenantId}/{id} | AI Provider | JWT | settings.manage |
| POST | /ai/providers/{tenantId}/{id}/test | AI Provider | JWT | settings.manage |
| POST | /ai/providers/{tenantId}/{id}/activate | AI Provider | JWT | settings.manage |
| GET | /ai/prompt-templates/{tenantId} | AI Template | JWT | settings.manage |
| POST | /ai/prompt-templates | AI Template | JWT | settings.manage |
| GET | /ai/prompt-templates/{tenantId}/{id} | AI Template | JWT | settings.manage |
| PATCH | /ai/prompt-templates/{tenantId}/{id} | AI Template | JWT | settings.manage |
| DELETE | /ai/prompt-templates/{tenantId}/{id} | AI Template | JWT | settings.manage |
| GET | /ai/prompt-templates/{tenantId}/{id}/versions | AI Template | JWT | settings.manage |
| GET | /ai/prompt-templates/{tenantId}/{id}/render | AI Template | JWT | settings.manage |
| GET | /ai/evaluations/{tenantId} | AI Eval | JWT | settings.manage |
| POST | /ai/evaluations | AI Eval | JWT | settings.manage |
| GET | /ai/evaluations/{tenantId}/{id} | AI Eval | JWT | settings.manage |
| POST | /ai/evaluations/{tenantId}/{id}/run | AI Eval | JWT | settings.manage |
| GET | /ai/evaluations/{tenantId}/{id}/results | AI Eval | JWT | settings.manage |
| GET | /ai/feedback/{tenantId} | AI Feedback | JWT | read |
| POST | /ai/feedback | AI Feedback | JWT | read |
| GET | /ai/feedback/{tenantId}/{id} | AI Feedback | JWT | read |
| GET | /ai/quotas/{tenantId} | AI Quota | JWT | settings.manage |
| POST | /ai/quotas | AI Quota | JWT | settings.manage |
| GET | /ai/quotas/{tenantId}/{id} | AI Quota | JWT | settings.manage |
| PATCH | /ai/quotas/{tenantId}/{id} | AI Quota | JWT | settings.manage |
| POST | /ai/quotas/{tenantId}/{id}/reset | AI Quota | JWT | settings.manage |
| GET | /ai/workspace/sessions | AI Workspace | JWT | read |
| POST | /ai/workspace/sessions | AI Workspace | JWT | create |
| GET | /ai/workspace/sessions/{sessionId}/messages | AI Workspace | JWT | read |
| POST | /ai/workspace/sessions/{sessionId}/messages | AI Workspace | JWT | read |
| POST | /ai/workspace/sessions/{sessionId}/messages/{messageId}/regenerate | AI Workspace | JWT | read |
| POST | /ai/workspace/sessions/{sessionId}/messages/{messageId}/explain | AI Workspace | JWT | read |
| GET | /ai/workspace/sessions/{sessionId}/messages/{messageId}/follow-up | AI Workspace | JWT | read |
| GET | /ai/workspace/sessions/{sessionId}/history | AI Workspace | JWT | read |
| GET | /settings/{tenantId} | Settings | JWT | read |
| PUT | /settings/{tenantId} | Settings | JWT | settings.manage |
| GET | /audit | Audit | JWT | read |
| GET | /audit/activity | Audit | JWT | read |
| GET | /audit/stats | Audit | JWT | read |
| GET | /notifications/{tenantId} | Notification | JWT | read |
| GET | /notifications/{tenantId}/unread-count | Notification | JWT | read |
| PATCH | /notifications/{tenantId}/{id}/read | Notification | JWT | read |
| POST | /notifications/{tenantId}/read-all | Notification | JWT | read |
| GET | /events | Events | JWT | read |
| GET | /events/stats/summary | Events | JWT | read |
| GET | /events/dlq | Events | JWT | read |
| POST | /events/retry/failed | Events | JWT | events.manage |
| POST | /events/dlq/{id}/retry | Events | JWT | events.manage |
| DELETE | /events/dlq/{id} | Events | JWT | events.manage |
| POST | /events/{id}/replay | Events | JWT | events.manage |
| GET | /events/{id} | Events | JWT | read |
| GET | /observability/health | Observability | JWT | read |
| GET | /observability/health/database | Observability | JWT | read |
| GET | /observability/health/neo4j | Observability | JWT | read |
| GET | /observability/health/events | Observability | JWT | read |
| GET | /observability/health/system | Observability | JWT | read |
| GET | /observability/metrics/system | Observability | JWT | read |
| GET | /observability/metrics/{tenantId} | Observability | JWT | read |

---

## Scope Enforcement Analysis

### Individual Level

Individual-level access is enforced through:

1. **Actor ID Filtering** (`app/Http/Controllers/Controller.php:22-25`)
   - Controllers extract actor ID from JWT
   - Notifications scoped by `user_id = actorId()`

2. **Notification Controller** (`app/Http/Controllers/Api/NotificationController.php:27-32`)
   ```php
   private function scope(Request $request) {
       return DB::table(self::TABLE)
           ->where('tenant_id', $this->tenantId($request))
           ->where('user_id', $this->actorId($request));
   }
   ```

3. **Settings Controller** (`app/Http/Controllers/Api/SettingsController.php:37-41`)
   - Personal settings: `user_id = actor`
   - Organization settings: `user_id IS NULL`

4. **AI Workspace Sessions** (`app/Http/Controllers/Api/AiWorkspaceController.php`)
   - Sessions scoped to individual user

### Department Level

Department-level access is enforced through:

1. **Department ID Filtering**
   - Signals with `department_id`
   - Department-scoped capabilities

2. **Department Repository Queries** (`app/Repositories/DepartmentRepository.php`)
   - Queries scoped by `sub_institute_id` and `department_id`

### Organization Level

Organization-level access is enforced through:

1. **Tenant Middleware** (`app/Http/Middleware/EnsureTenantScope.php:44-101`)
   - Resolves tenant from JWT token
   - All data queries include `tenant_id` filter

2. **Admin Cross-Tenant Exception**
   - Admins can address other organizations
   - Only if organization exists in `institute_detail`

---

## Security Observations

### Finding 1: Missing Authorization on Some GET Routes

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| Medium | Routes like `GET /organizations/{tenantId}/{id}/structure` only require `permission:read` | Add explicit permissions for sensitive data |

### Finding 2: Organization Audit Logs May Expose Sensitive Info

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| Low | `GET /organizations/{tenantId}/{id}/audit` exposes all audit entries | Implement row-level filtering by permission |

### Finding 3: AI Summarization Without Audit Trail

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| Medium | `POST /ai/summarize-evidence` is POST but writes nothing | Add audit logging for AI operations |

### Finding 4: Bulk Import Without Row-Level Permissions

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| High | `POST /imports` can import any entity type | Add entity-specific permissions |

### Finding 5: Session Deletion Without Ownership Check

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| Medium | `DELETE /conversations/sessions/{id}` only checks permission | Add ownership verification |

### Finding 6: Cross-Tenant Admin Access

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| Low | Admins can address any organization | Document and monitor cross-tenant access |

### Finding 7: Hardcoded Provider Validation

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| Low | `AiController.php:31` hardcodes `['anthropic', 'openai', 'gemini', 'ollama']` | Externalize to configuration |

### Finding 8: Evidence Hash Not Verifiable

| Severity | Evidence | Recommendation |
|----------|----------|----------------|
| Low | `EvidenceController.php:166-168` hash computed but never verified | Implement hash verification |

---

## Unverified Features

The following features appear to have incomplete implementations or lack supporting evidence:

| Feature | Evidence | Missing Evidence | Scope | Confidence |
|---------|----------|------------------|-------|------------|
| Neo4j Health Check | `ObservabilityController.php` | Actual Neo4j connectivity implementation | Organization | Medium |
| Mental Model Domains | `MentalModelController.php` | Domain enumeration and categorization | Organization | Medium |
| Risk Assessment | `RiskController.php` | Risk scoring algorithm | Organization | Medium |
| KASBA Heatmap | `KasbaController.php` | Actual proficiency calculation | Department | Medium |
| Feature Flags | `FeatureFlagController.php` | Flag evaluation logic | Organization | Medium |

---

## Role & Permission Matrix

| Role | Individual | Department | Organization | Admin Cross-Tenant | Evidence |
|------|------------|------------|--------------|-------------------|----------|
| Viewer | Read own notifications, settings | Read signals, evidence | Full read access | No | `Role.php:36-38` |
| Analyst | Read own notifications, settings | Full department access | Create signals, evidence, cases | No | `Role.php:39-44` |
| Manager | Read own notifications, settings | Full department access | Analyst + Approve decisions, Execute ESOs | No | `Role.php:47-54` |
| Admin | Read own notifications, settings | Full department access | All permissions | Yes | `Role.php:55` |
| Tenant Admin | Read own notifications, settings | Full department access | All permissions except cross-tenant | No | `Role.php:55` |

---

## Evidence References

### Middleware
- `app/Http/Middleware/AuthenticateJwt.php:23-55`
- `app/Http/Middleware/EnsureTenantScope.php:44-101`
- `app/Http/Middleware/RequirePermission.php:28-124`

### Authorization
- `app/Domain/Authorization/Role.php:22-68`
- `app/Domain/Authorization/Permission.php:7-26`

### Controllers
- `app/Http/Controllers/Api/AuthController.php:1-393`
- `app/Http/Controllers/Api/OrganizationController.php:1-257`
- `app/Http/Controllers/Api/SignalController.php:1-109`
- `app/Http/Controllers/Api/EvidenceController.php:1-171`
- `app/Http/Controllers/Api/CaseController.php:1-140`
- `app/Http/Controllers/Api/DecisionController.php:1-240`
- `app/Http/Controllers/Api/OutcomeController.php:1-182`
- `app/Http/Controllers/Api/NotificationController.php:1-69`
- `app/Http/Controllers/Api/SettingsController.php:1-77`
- `app/Http/Controllers/Api/AiController.php:1-156`
- `app/Http/Controllers/Api/AiWorkspaceController.php`
- `app/Http/Controllers/Api/AnalyticsController.php:1-360`
- `app/Http/Controllers/Api/AuditController.php:1-58`

### Routes
- `routes/api.php:113-229`

### Tests
- `tests/Feature/ApiAuthorizationTest.php:1-317`
- `tests/Feature/SecurityMatrixTest.php`
- `tests/Feature/TenantIsolationMatrixTest.php`
