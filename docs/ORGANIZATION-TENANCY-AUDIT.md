# Organization Tenancy Audit

## 1. All Tables Used by the Application

### ERP-Owned Tables (read-mostly, never dropped by migrations)

| Table | Owner | Purpose |
|---|---|---|
| `institute_detail` | Institute ERP | Organizations |
| `org_details` | Institute ERP | Organization legal details, logo |
| `hrms_departments` | Institute ERP | Departments |
| `tbluser` | Institute ERP | People / employees |
| `tbluserprofilemaster` | Institute ERP | User profiles (e.g. Employee) |

### Brain-Owned Tables (hpbrain_ prefix)

| Table | Purpose |
|---|---|
| `hpbrain_auth_users` | Authentication identities |
| `hpbrain_organizations` | Brain organization metadata |
| `hpbrain_departments` | Brain department metadata |
| `hpbrain_people` | Brain person metadata |
| `hpbrain_capabilities` | Capability library |
| `hpbrain_capability_assignments` | Capability-to-person/department assignments |
| `hpbrain_capability_proficiency` | KASBA proficiency assessments |
| `hpbrain_job_role_capability_requirements` | Role capability requirements |
| `hpbrain_signals` | Intelligence signals |
| `hpbrain_evidence` | Evidence records |
| `hpbrain_cases` | Investigation cases |
| `hpbrain_hypotheses` | Hypotheses |
| `hpbrain_reasoning_steps` | Reasoning chain steps |
| `hpbrain_recommendations` | Recommendations |
| `hpbrain_decisions` | Decisions |
| `hpbrain_eso_executions` | Execution records |
| `hpbrain_outcomes` | Outcomes |
| `hpbrain_learnings` | Learnings |
| `hpbrain_risks` | Risks |
| `hpbrain_policies` | Policies |
| `hpbrain_mental_models` | Mental models |
| `hpbrain_conversation_sessions` | AI conversation sessions |
| `hpbrain_conversation_messages` | AI conversation messages |
| `hpbrain_prompt_templates` | Prompt templates |
| `hpbrain_notifications` | Notifications |
| `hpbrain_settings` | Settings |
| `hpbrain_event_store` | Event store |
| `hpbrain_dead_letter_queue` | DLQ |
| `hpbrain_audit_logs` | Audit logs |
| `hpbrain_guardians` | Guardians |
| `hpbrain_api_keys` | API keys |
| `hpbrain_knowledge_assets` | Knowledge assets |
| `hpbrain_measurement_plans` | Measurement plans |
| `hpbrain_executors` | Executors |
| `hpbrain_ai_executions` | AI executions |
| `hpbrain_process_library` | Process library |
| `hpbrain_context_library` | Context library |
| `hpbrain_reasoning_pattern_library` | Reasoning patterns |
| `hpbrain_eso_definition_library` | ESO definitions |
| `hpbrain_evidence_ledger` | Evidence ledger |
| `hpbrain_learning_efficacy` | Learning efficacy |
| `hpbrain_policy_library_additions` | Policy additions |
| `hpbrain_telemetry_library` | Telemetry |
| `hpbrain_placement_taxonomy` | Placement taxonomy |
| `hpbrain_accreditation_framework` | Accreditation |
| `hpbrain_career_taxonomy` | Career taxonomy |
| `hpbrain_eso_execution_evidence` | ESO execution evidence |
| `hpbrain_kasba_task_hierarchy` | KASBA task hierarchy |
| `hpbrain_tenants` | Tenants |
| `hpbrain_capability_state` | Capability state |
| `hpbrain_case_engine` | Case engine |
| `hpbrain_department_scoped_signals` | Department scoped signals |
| `hpbrain_mental_model_reinforcement` | Mental model reinforcement |
| `hpbrain_decision_intelligence` | Decision intelligence |
| `hpbrain_executors` | Executors |
| `hpbrain_notifications_and_settings` | Notifications and settings |
| `hpbrain_conversation_pinning` | Conversation pinning |
| `hpbrain_conversation_engine` | Conversation engine |
| `hpbrain_ai_governance` | AI governance |
| `hpbrain_knowledge_assets` | Knowledge assets |

## 2. Organization Column in Each Table

### ERP Tables

| Table | Organization Column | Type |
|---|---|---|
| `institute_detail` | `sub_institute_id` | String/numeric |
| `org_details` | `sub_institute_id` | String/numeric |
| `hrms_departments` | `sub_institute_id` | String/numeric |
| `tbluser` | `sub_institute_id` | String/numeric |
| `tbluserprofilemaster` | `sub_institute_id` | String/numeric |

### Brain Tables

| Table | Organization Column | Type |
|---|---|---|
| `hpbrain_auth_users` | `tenant_id` | VARCHAR(36) |
| `hpbrain_organizations` | `tenant_id` | VARCHAR(36) |
| `hpbrain_departments` | `tenant_id` | VARCHAR(36) |
| `hpbrain_people` | `tenant_id` | VARCHAR(36), `org_id` | VARCHAR(36) |
| `hpbrain_capabilities` | `tenant_id` | VARCHAR(36) |
| `hpbrain_capability_assignments` | `tenant_id` | VARCHAR(36) |
| `hpbrain_capability_proficiency` | `tenant_id` | VARCHAR(36) |
| `hpbrain_job_role_capability_requirements` | `tenant_id` | VARCHAR(36) |
| `hpbrain_signals` | `tenant_id` | VARCHAR(36) |
| `hpbrain_evidence` | `tenant_id` | VARCHAR(36) |
| `hpbrain_cases` | `tenant_id` | VARCHAR(36) |
| `hpbrain_hypotheses` | `tenant_id` | VARCHAR(36) |
| `hpbrain_reasoning_steps` | `tenant_id` | VARCHAR(36) |
| `hpbrain_recommendations` | `tenant_id` | VARCHAR(36) |
| `hpbrain_decisions` | `tenant_id` | VARCHAR(36) |
| `hpbrain_eso_executions` | `tenant_id` | VARCHAR(36) |
| `hpbrain_outcomes` | `tenant_id` | VARCHAR(36) |
| `hpbrain_learnings` | `tenant_id` | VARCHAR(36) |
| `hpbrain_risks` | `tenant_id` | VARCHAR(36) |
| `hpbrain_policies` | `tenant_id` | VARCHAR(36) |
| `hpbrain_mental_models` | `tenant_id` | VARCHAR(36) |
| `hpbrain_conversation_sessions` | `tenant_id` | VARCHAR(36) |
| `hpbrain_conversation_messages` | `tenant_id` | VARCHAR(36) |
| `hpbrain_prompt_templates` | `tenant_id` | VARCHAR(36) |
| `hpbrain_notifications` | `tenant_id` | VARCHAR(36) |
| `hpbrain_settings` | `tenant_id` | VARCHAR(36) |
| `hpbrain_event_store` | `tenant_id` | VARCHAR(36) |
| `hpbrain_audit_logs` | `tenant_id` | VARCHAR(36) |
| `hpbrain_guardians` | `tenant_id` | VARCHAR(36) |
| `hpbrain_api_keys` | `tenant_id` | VARCHAR(36) |
| `hpbrain_knowledge_assets` | `tenant_id` | VARCHAR(36) |
| `hpbrain_measurement_plans` | `tenant_id` | VARCHAR(36) |
| `hpbrain_executors` | `tenant_id` | VARCHAR(36) |
| `hpbrain_ai_executions` | `tenant_id` | VARCHAR(36) |

## 3. Tables Using `sub_institute_id`

These are the ERP tables and Brain tables that use `sub_institute_id`:

- `institute_detail` (primary key for organization identity)
- `org_details`
- `hrms_departments`
- `tbluser`
- `tbluserprofilemaster`

## 4. Tables Using `tenant_id`

All `hpbrain_*` tables use `tenant_id` except:
- `hpbrain_dead_letter_queue` — has no tenant_id
- `hpbrain_tenants` — may have its own structure

## 5. Tables Indirectly Connected to an Organization

- `hpbrain_people` links to `tbluser` via `employee_id`, and `tbluser` has `sub_institute_id`
- `hpbrain_capability_assignments` with `target_type = 'Person'` links to `hpbrain_people` or `tbluser`
- `hpbrain_capability_assignments` with `target_type = 'Department'` links to `hpbrain_departments` or `hrms_departments`
- `hpbrain_capability_proficiency` links via `assignment_id` to assignments
- `hpbrain_guardians` links via `student_person_id` to people
- `hpbrain_notifications` links via `user_id` to `hpbrain_auth_users` which has `tenant_id`

## 6. Tables Currently Without Organization Filter

### Critical leaks (ERP tables queried without sub_institute_id):

1. **`DepartmentController::index`** — queries ALL `hrms_departments` without `sub_institute_id` filter
2. **`DepartmentController::show`** — queries by `id` only, no `sub_institute_id`
3. **`DepartmentController::update`** — updates by `id` only, no `sub_institute_id`
4. **`DepartmentController::archive`** — archives by `id` only, no `sub_institute_id`
5. **`PersonController::index`** (via `query()`) — queries ALL `tbluser` without `sub_institute_id`
6. **`PersonController::show`** — queries by `id` only, no `sub_institute_id`
7. **`PersonController::search`** — searches ALL `tbluser` without `sub_institute_id`
8. **`PersonController::update`** — updates by `id` only, no `sub_institute_id`
9. **`PersonController::archive`** — archives by `id` only, no `sub_institute_id`

### Moderate leaks:

10. **`OrganizationController::index`** — returns ALL organizations via `OrganizationRepository::list()`
11. **`OrganizationController::show`** — finds organization by `id` without verifying it belongs to caller's org

### Properly scoped:

- All `hpbrain_*` Brain tables are properly scoped via `BaseRepository::scoped()` or explicit `where('tenant_id', ...)` in controllers.
- `NotificationController` correctly scopes by both `tenant_id` AND `user_id`.
- `AuditController` correctly scopes by `tenant_id`.
- `SignalController`, `EvidenceController`, `CaseController`, etc. all use repositories or explicit tenant filters.

## 7. Every API Endpoint and Tenant Scoping

### Public endpoints (no auth required)

| Endpoint | Method | Controller | Tenant Scoped | Notes |
|---|---|---|---|---|
| `/api/v1/auth/login` | POST | AuthController | N/A | Uses `tenantId` from body — should be removed |
| `/api/v1/auth/refresh` | POST | AuthController | N/A | Token-based, OK |
| `/api/v1/auth/external-login` | POST | AuthController | N/A | External proxy — should be removed |
| `/api/v1/auth/erp-login` | POST | AuthController | N/A | Uses `tbluser` — current frontend uses this, should become the unified login |
| `/api/v1/auth/dev-token` | POST | AuthController | N/A | Dev only — must be removed |

### Authenticated endpoints

| Endpoint | Method | Controller | Tenant Scoped | Notes |
|---|---|---|---|---|
| `/api/v1/auth/change-password` | POST | AuthController | hpbrain_auth_users | OK |
| `/api/v1/organizations/{tenantId}` | GET | OrganizationController | NO | Returns ALL orgs |
| `/api/v1/organizations` | POST | OrganizationController | Partial | Creates with `sub_institute_id` from body |
| `/api/v1/organizations/{tenantId}/{id}` | GET | OrganizationController | NO | Finds by ID only |
| `/api/v1/departments/{tenantId}` | GET | DepartmentController | NO | Returns ALL departments |
| `/api/v1/departments` | POST | DepartmentController | Uses body `orgId` | Should use token org |
| `/api/v1/departments/{tenantId}/{id}` | GET | DepartmentController | NO | Queries by ID only |
| `/api/v1/people/{tenantId}/search` | GET | PersonController | NO | Searches ALL people |
| `/api/v1/people/{tenantId}` | GET | PersonController | NO | Returns ALL people |
| `/api/v1/people` | POST | PersonController | Uses body `orgId` | Should use token org |
| `/api/v1/people/{tenantId}/{id}` | GET | PersonController | NO | Queries by ID only |
| `/api/v1/capabilities/{tenantId}` | GET | CapabilityController | YES | Uses `tenant_id` |
| `/api/v1/signals/{tenantId}` | GET | SignalController | YES | Uses `tenant_id` |
| `/api/v1/evidence/{tenantId}` | GET | EvidenceController | YES | Uses `tenant_id` |
| `/api/v1/cases/{tenantId}` | GET | CaseController | YES | Uses `tenant_id` |
| `/api/v1/decisions/{tenantId}` | GET | DecisionController | YES | Uses `tenant_id` |
| `/api/v1/workspace/{tenantId}` | GET | WorkspaceController | YES | Uses `tenant_id` |
| `/api/v1/analytics/{tenantId}` | GET | AnalyticsController | YES | Uses `tenant_id` |
| `/api/v1/search/{tenantId}` | GET | SearchController | YES | Uses `tenant_id` |
| `/api/v1/notifications/{tenantId}` | GET | NotificationController | YES + user | Uses `tenant_id` + `user_id` |
| `/api/v1/audit` | GET | AuditController | YES | Uses `tenant_id` |

## 8. Every Frontend Screen and API Dependencies

### Auth
- `Login.tsx` → `/auth/erp-login` (POST)

### Organization
- `OrganizationList.tsx` → `/organizations/{tenantId}` (GET)
- `OrganizationDetails.tsx` → `/organizations/{tenantId}/{id}` (GET)
- `OrganizationCreate.tsx` → `/organizations` (POST)
- `OrganizationEdit.tsx` → `/organizations/{tenantId}/{id}` (PATCH)
- `OrganizationArchiveConfirm.tsx` → `/organizations/{tenantId}/{id}/archive` (POST)

### Department
- `DepartmentList.tsx` → `/departments/{tenantId}` (GET)
- `DepartmentDetails.tsx` → `/departments/{tenantId}/{id}` (GET)
- `DepartmentCreate.tsx` → `/departments` (POST)
- `DepartmentEdit.tsx` → `/departments/{tenantId}/{id}` (PATCH)
- `DepartmentArchiveConfirm.tsx` → `/departments/{tenantId}/{id}/archive` (POST)
- `DepartmentApp.tsx` → all of the above

### Person
- `PersonList.tsx` → `/people/{tenantId}` (GET), `/people/{tenantId}/search` (GET)
- `PersonDetails.tsx` → `/people/{tenantId}/{id}` (GET)
- `PersonCreate.tsx` → `/people` (POST)
- `PersonEdit.tsx` → `/people/{tenantId}/{id}` (PATCH)
- `PersonArchiveConfirm.tsx` → `/people/{tenantId}/{id}/archive` (POST)
- `PersonTwin.tsx` → `/people/{tenantId}/{id}/twin` (GET)
- `PersonApp.tsx` → all of the above

### Capability
- `CapabilityList.tsx` → `/capabilities/{tenantId}` (GET), `/capabilities/{tenantId}/search` (GET)
- `CapabilityDetails.tsx` → `/capabilities/{tenantId}/{id}` (GET)
- `CapabilityCreate.tsx` → `/capabilities` (POST)
- `CapabilityEdit.tsx` → `/capabilities/{tenantId}/{id}` (PATCH)
- `CapabilityAssignment.tsx` → `/capabilities/{tenantId}/{id}/assign` (POST)
- `CapabilityApp.tsx` → all of the above

### Intelligence
- `SignalDashboard.tsx` → `/signals/{tenantId}` (GET)
- `EvidenceWorkspace.tsx` → `/evidence/{tenantId}` (GET)
- Various workspace components → `/workspace/{tenantId}` (GET)
- `DecisionAnalyticsPanel.tsx` → `/analytics/{tenantId}` (GET)
- `ExecutiveDashboard.tsx` → `/analytics/{tenantId}/executive-summary` (GET)
- `DecisionIntelligence.tsx` → `/analytics/{tenantId}/decision-intelligence` (GET)
- `GraphExplorer.tsx` → `/graph/{tenantId}/search` (GET)
- `AgentMonitor.tsx` → `/ai/executions/{tenantId}` (GET)
- `ConversationWorkspace.tsx` → `/conversations/sessions/{tenantId}` (GET)
- `TaskMonitor.tsx` → `/tasks/registry` (GET)
- `CommandCenter.tsx` → `/workspace/{tenantId}` (GET)
- `KasbaExplorer.tsx` → `/kasba/heatmap/{tenantId}` (GET)
- `GlobalSearch.tsx` → `/search/{tenantId}` (GET)

## 9. Existing Authentication Routes

| Route | Method | Controller | Status | Issues |
|---|---|---|---|---|
| `/api/v1/auth/login` | POST | AuthController::login | Uses `hpbrain_auth_users` | Requires `tenantId` in body |
| `/api/v1/auth/external-login` | POST | AuthController::externalLogin | Proxies to external API | Should be removed |
| `/api/v1/auth/erp-login` | POST | AuthController::erpLogin | Uses `tbluser` | Current frontend endpoint |
| `/api/v1/auth/dev-token` | POST | AuthController::devToken | Dev only | Should be removed |
| `/api/v1/auth/refresh` | POST | AuthController::refresh | Token-based | OK |
| `/api/v1/auth/change-password` | POST | AuthController::changePassword | Authenticated | OK |

## 10. Duplicate or Obsolete Authentication Logic

1. **Three login endpoints** (`login`, `external-login`, `erp-login`) — should be unified to one.
2. **`dev-bypass` token** in `AuthenticateJwt` middleware — security backdoor.
3. **`hpbrain_auth_users`** table exists alongside `tbluser` — creates identity divergence.
4. **Frontend calls `/auth/erp-login`** while docs describe `/auth/login` — inconsistency.
5. **`AuthController::login`** requires `tenantId` in request body — violates "tenant from JWT only" rule.
6. **`verifyErpPassword`** checks three password formats — migration debt, not a permanent design.
7. **No logout endpoint** — tokens are stateless but no revocation mechanism exists.
8. **Rate limiting only on login** — refresh endpoint is unthrottled.
9. **`PersonController::store`** creates ERP users with plain-text passwords in both `password` and `plain_password` columns.
10. **Frontend `DEFAULT_ORG_ID = '6'`** — hardcoded Scholar Clone fallback.
