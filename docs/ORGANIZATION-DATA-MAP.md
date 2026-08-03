# Organization Data Map

## ERP-Owned Tables (read-mostly)

### institute_detail
| Field | Type | Purpose |
|---|---|---|
| `sub_institute_id` | INT | Primary organization identifier |
| `organization_name` | VARCHAR | Display name |
| `organization_code` | VARCHAR | Org code |
| `industry_type` | VARCHAR | Industry classification |
| `created_by` | VARCHAR | Creator ID |
| `created_at` | TIMESTAMP | Creation time |
| `updated_at` | TIMESTAMP | Update time |
| `deleted_at` | TIMESTAMP | Soft delete |

- **Organization field**: `sub_institute_id`
- **Soft delete**: `deleted_at`
- **Used by**: `OrganizationRepository`, `AuthController::loadOrganization()`
- **Used by screens**: Organization list, Organization details

### org_details
| Field | Type | Purpose |
|---|---|---|
| `sub_institute_id` | INT | Foreign key to institute_detail |
| `legal_name` | VARCHAR | Legal entity name |
| `logo` | VARCHAR | Logo URL/path |
| `created_by` | VARCHAR | Creator ID |
| `created_at` | TIMESTAMP | Creation time |
| `updated_at` | TIMESTAMP | Update time |
| `deleted_at` | TIMESTAMP | Soft delete |

- **Organization field**: `sub_institute_id`
- **Used by**: `OrganizationRepository`, `AuthController::loadOrganization()`

### hrms_departments
| Field | Type | Purpose |
|---|---|---|
| `id` | INT | Primary key |
| `sub_institute_id` | INT | Organization ID |
| `department` | VARCHAR | Department name |
| `roles_responsibility` | TEXT | Description |
| `parent_id` | INT | Parent department |
| `status` | TINYINT | 1=active |
| `is_calculated` | TINYINT | System flag |
| `created_by` | VARCHAR | Creator ID |
| `created_at` | TIMESTAMP | Creation time |
| `updated_at` | TIMESTAMP | Update time |
| `deleted_at` | TIMESTAMP | Soft delete |

- **Organization field**: `sub_institute_id`
- **Soft delete**: `deleted_at`
- **Used by**: `DepartmentController`, `PersonController::twin()`
- **Used by screens**: Department list, Department details, Department twin

### tbluser
| Field | Type | Purpose |
|---|---|---|
| `id` | INT | Primary key |
| `employee_no` | VARCHAR | Employee number |
| `password` | VARCHAR | Password hash or legacy password |
| `plain_password` | VARCHAR | Legacy plaintext password |
| `first_name` | VARCHAR | First name |
| `last_name` | VARCHAR | Last name |
| `email` | VARCHAR | Email address |
| `mobile` | VARCHAR | Phone |
| `gender` | VARCHAR | Gender |
| `birthdate` | DATE | Date of birth |
| `joined_date` | DATE | Joining date |
| `department_id` | INT | Department ID |
| `jobtitle_id` | INT | Job title ID |
| `city` | VARCHAR | City |
| `state` | VARCHAR | State |
| `image` | VARCHAR | Profile image |
| `sub_institute_id` | INT | Organization ID |
| `user_profile_id` | INT | Profile ID |
| `status` | TINYINT | 1=active |
| `created_by` | VARCHAR | Creator ID |
| `created_at` | TIMESTAMP | Creation time |
| `updated_at` | TIMESTAMP | Update time |
| `deleted_at` | TIMESTAMP | Soft delete |

- **Organization field**: `sub_institute_id`
- **Soft delete**: `deleted_at`
- **Status field**: `status` (1=active)
- **Used by**: `AuthController`, `PersonController`, `KasbaController`
- **Used by screens**: Person list, Person details, Person twin, Login

### tbluserprofilemaster
| Field | Type | Purpose |
|---|---|---|
| `id` | INT | Primary key |
| `sub_institute_id` | INT | Organization ID |
| `name` | VARCHAR | Profile name |
| `status` | TINYINT | 1=active |

- **Organization field**: `sub_institute_id`
- **Used by**: `AuthController::resolveRole()`, `PersonController::store()`

## Brain-Owned Tables (hpbrain_ prefix)

All Brain tables use `tenant_id` for organization isolation unless noted otherwise.

### hpbrain_auth_users
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key (UUID) |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `email` | VARCHAR(255) | Email |
| `name` | VARCHAR(255) | Display name |
| `role` | VARCHAR(100) | Role |
| `password_hash` | TEXT | Bcrypt/argon hash |
| `created_date` | TIMESTAMP | Creation time |
| `updated_date` | TIMESTAMP | Update time |

- **Organization field**: `tenant_id`
- **Status**: No `deleted_at` or `status` field
- **Used by**: Legacy authentication only

### hpbrain_departments
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `name` | TEXT | Department name |
| `description` | TEXT | Description |
| `department_type` | TEXT | Type |
| `parent_department_id` | VARCHAR(36) | Parent |
| `head_id` | VARCHAR(36) | Head person |
| `org_id` | VARCHAR(36) | Organization ID |
| `status` | VARCHAR(255) | active/inactive/archived |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |
| `updated_date` | TIMESTAMP | Update time |

- **Organization field**: `tenant_id`, `org_id`
- **Used by**: Department operations

### hpbrain_people
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `employee_id` | VARCHAR(36) | Employee reference |
| `first_name` | TEXT | First name |
| `last_name` | TEXT | Last name |
| `email` | VARCHAR(255) | Email |
| `department_id` | VARCHAR(36) | Department |
| `manager_id` | VARCHAR(36) | Manager |
| `org_id` | VARCHAR(36) | Organization ID |
| `status` | VARCHAR(255) | active/inactive |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |
| `updated_date` | TIMESTAMP | Update time |

- **Organization field**: `tenant_id`, `org_id`
- **Used by**: Person operations

### hpbrain_capabilities
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `name` | TEXT | Capability name |
| `description` | TEXT | Description |
| `capability_code` | VARCHAR(255) | Code |
| `version` | INT | Version |
| `status` | VARCHAR(255) | Status |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |
| `updated_date` | TIMESTAMP | Update time |

- **Organization field**: `tenant_id`
- **Used by**: Capability operations

### hpbrain_signals
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `source` | TEXT | Signal source |
| `classification` | TEXT | Classification |
| `priority` | VARCHAR(255) | Priority |
| `severity` | VARCHAR(255) | Severity |
| `confidence` | DECIMAL(6,4) | Confidence score |
| `metadata` | JSON | Additional data |
| `status` | VARCHAR(255) | Status |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Signal operations, Intelligence

### hpbrain_evidence
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `signal_id` | VARCHAR(36) | Signal reference |
| `source` | TEXT | Source |
| `evidence_type` | VARCHAR(36) | Type |
| `content` | TEXT | Content |
| `provenance` | TEXT | Provenance |
| `confidence` | DECIMAL(6,4) | Confidence |
| `hash` | TEXT | Content hash |
| `status` | VARCHAR(255) | Status |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Evidence operations

### hpbrain_cases
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `signal_id` | VARCHAR(36) | Signal reference |
| `title` | TEXT | Case title |
| `status` | VARCHAR(255) | Status |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Case operations

### hpbrain_decisions
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `recommendation_id` | VARCHAR(36) | Recommendation reference |
| `decided_by` | VARCHAR(36) | Decision maker |
| `executor_type` | VARCHAR(255) | Executor type |
| `rationale` | TEXT | Rationale |
| `alternatives_considered` | TEXT | Alternatives |
| `status` | VARCHAR(255) | Status |
| `approved_by` | VARCHAR(36) | Approver |
| `approved_date` | TIMESTAMP | Approval time |
| `approval_note` | TEXT | Note |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Decision operations

### hpbrain_recommendations
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `reasoning_step_id` | VARCHAR(36) | Reasoning reference |
| `title` | TEXT | Title |
| `category` | VARCHAR(255) | Category |
| `confidence` | DECIMAL(6,4) | Confidence |
| `priority` | VARCHAR(255) | Priority |
| `status` | VARCHAR(255) | Status |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Recommendation operations

### hpbrain_outcomes
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `decision_id` | VARCHAR(36) | Decision reference |
| `result` | VARCHAR(255) | Result |
| `metrics` | TEXT | Metrics |
| `kpis` | TEXT | KPIs |
| `evidence_ids` | TEXT | Evidence IDs |
| `feedback` | TEXT | Feedback |
| `confidence` | DECIMAL(6,4) | Confidence |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Outcome operations

### hpbrain_learnings
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `pattern` | TEXT | Pattern |
| `confidence` | DECIMAL(6,4) | Confidence |
| `reusable` | TINYINT | Reusable flag |
| `mental_model_id` | VARCHAR(36) | Model reference |
| `created_by` | TEXT | Creator |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Learning operations

### hpbrain_risks
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `category` | VARCHAR(255) | Category |
| `score` | DECIMAL | Risk score |
| `impact` | VARCHAR(255) | Impact |
| `status` | VARCHAR(255) | Status |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Risk operations

### hpbrain_audit_logs
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `entity_type` | VARCHAR(255) | Entity type |
| `entity_id` | VARCHAR(36) | Entity ID |
| `action` | VARCHAR(255) | Action |
| `actor_id` | VARCHAR(36) | Actor |
| `actor_name` | TEXT | Actor name |
| `changes` | TEXT | Changes |
| `ip_address` | VARCHAR(255) | IP |
| `user_agent` | TEXT | User agent |
| `created_at` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Audit operations

### hpbrain_notifications
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `user_id` | VARCHAR(36) | User ID |
| `title` | VARCHAR(255) | Title |
| `body` | TEXT | Body |
| `read_date` | TIMESTAMP | Read time |
| `created_date` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Notification operations
- **Additional filter**: `user_id`

### hpbrain_event_store
| Field | Type | Purpose |
|---|---|---|
| `id` | VARCHAR(36) | Primary key |
| `type` | VARCHAR(255) | Event type |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `entity_type` | VARCHAR(255) | Entity type |
| `entity_id` | VARCHAR(36) | Entity ID |
| `actor_id` | VARCHAR(36) | Actor |
| `payload` | TEXT | Payload |
| `metadata` | TEXT | Metadata |
| `correlation_id` | VARCHAR(36) | Correlation ID |
| `causation_id` | VARCHAR(36) | Causation ID |
| `idempotency_key` | VARCHAR(36) | Idempotency key |
| `status` | VARCHAR(255) | Status |
| `retry_count` | INT | Retry count |
| `created_at` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Event backbone

### hpbrain_refresh_tokens
| Field | Type | Purpose |
|---|---|---|
| `jti` | VARCHAR(36) | Primary key (token ID) |
| `tenant_id` | VARCHAR(36) | Organization ID |
| `user_id` | VARCHAR(36) | User ID |
| `expires_at` | TIMESTAMP | Expiry |
| `revoked_at` | TIMESTAMP | Revocation time |
| `created_at` | TIMESTAMP | Creation time |

- **Organization field**: `tenant_id`
- **Used by**: Refresh token revocation
