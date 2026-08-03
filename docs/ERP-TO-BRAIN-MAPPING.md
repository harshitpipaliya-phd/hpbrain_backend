# ERP to Brain Entity Mapping

## Mapping Principles

1. **Read-mostly for ERP**: The Brain reads ERP tables directly; it does not duplicate master data.
2. **Brain-owns intelligence**: Signals, evidence, cases, recommendations, decisions, executions, outcomes, and learnings are Brain-owned.
3. **Normalized, not copied**: ERP entities are referenced by their native IDs; Brain entities use UUIDs.
4. **Events bridge the gap**: When ERP data changes, events capture the delta; Brain processes them.
5. **Evidence, not replacement**: ERP data is cited as evidence in Brain reasoning; it is not overwritten.

## Entity Mapping

### Organization

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| `institute_detail` | `sub_institute_id` | `Organization` | `tenant_id` | Direct — Brain tenant = ERP org ID |
| `institute_detail` | `organization_name` | `Organization` | `name` | Direct |
| `institute_detail` | `organization_code` | `Organization` | `org_code` | Direct |
| `institute_detail` | `industry_type` | `Organization` | `industry` | Direct |
| `org_details` | `legal_name` | `Organization` | `legal_name` | Direct |
| `org_details` | `logo` | `Organization` | `logo` | Direct |

**Strategy**: Read live from ERP. No Brain-side copy. Organization identity is derived from JWT `tenantId` claim which equals `tbluser.sub_institute_id`.

### Department

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| `hrms_departments` | `id` | `Department` | `employee_id` (ref) | Referenced |
| `hrms_departments` | `sub_institute_id` | `Department` | `tenant_id` | Direct |
| `hrms_departments` | `department` | `Department` | `name` | Direct |
| `hrms_departments` | `roles_responsibility` | `Department` | `description` | Direct |
| `hrms_departments` | `parent_id` | `Department` | `parent_department_id` | Direct |
| `hrms_departments` | `status` | `Department` | `status` | Direct |
| `tbluser` | `department_id` | `Department` | (computed) | Head count |

**Strategy**: Read live from ERP for master data. Brain `hpbrain_departments` may store additional metadata (type, org_id). ERP `hrms_departments.id` is referenced by `tbluser.department_id`.

### Person

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| `tbluser` | `id` | `Person` | `employee_id` | Direct |
| `tbluser` | `employee_no` | `Person` | `employee_id` | Direct |
| `tbluser` | `first_name` | `Person` | `first_name` | Direct |
| `tbluser` | `last_name` | `Person` | `last_name` | Direct |
| `tbluser` | `email` | `Person` | `email` | Direct |
| `tbluser` | `mobile` | `Person` | `phone` | Direct |
| `tbluser` | `gender` | `Person` | `gender` | Direct |
| `tbluser` | `birthdate` | `Person` | `date_of_birth` | Direct |
| `tbluser` | `joined_date` | `Person` | `joining_date` | Direct |
| `tbluser` | `department_id` | `Person` | `department_id` | Foreign key |
| `tbluser` | `jobtitle_id` | `Person` | `designation` | Indirect |
| `tbluser` | `city` | `Person` | `location` | Direct |
| `tbluser` | `sub_institute_id` | `Person` | `tenant_id` | Direct |
| `tbluser` | `user_profile_id` | `Person` | `profile_id` | Foreign key |
| `tbluser` | `status` | `Person` | `status` | Direct |
| `tbluser` | `image` | `Person` | `profile_photo` | Direct |

**Strategy**: Read live from ERP. Brain `hpbrain_people` stores computed/enriched fields (display_name, employment_type, manager_id, reporting_manager_id, org_id). ERP `tbluser.id` is the authoritative person ID.

### Role/Profile

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| `tbluserprofilemaster` | `id` | `Role` | (resolved) | Direct |
| `tbluserprofilemaster` | `name` | `Role` | `name` | Direct |
| `tbluserprofilemaster` | `sub_institute_id` | `Role` | `tenant_id` | Direct |
| `tbluser` | `user_profile_id` | `Person` | `role` | Foreign key |

**Strategy**: Profile name is mapped to Brain role at login time. No Brain-side role table needed; mapping is in `AuthController::resolveRole()`.

### Skill

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| (none) | — | `Skill` | `hpbrain_skills.*` | Brain-owned |

**Strategy**: No ERP skill table exists. Skills are defined and managed within the Brain. Skill assignments may reference `tbluser.id` (person) and `hrms_departments.id` (department).

### Competency/Assessment

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| (none) | — | `CapabilityProficiency` | `hpbrain_capability_proficiency.*` | Brain-owned |

**Strategy**: No ERP assessment table exists. Competency assessments (KASBA dimensions) are Brain-owned. They reference `hpbrain_people.employee_id` (which maps to `tbluser.id`) and `hpbrain_capabilities.id`.

### Capability

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| (none) | — | `Capability` | `hpbrain_capabilities.*` | Brain-owned |

**Strategy**: Capabilities are defined within the Brain. They may be linked to departments (`hrms_departments.id`) and people (`tbluser.id`) through Brain-side relationships.

### Signal

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| (none) | — | `Signal` | `hpbrain_signals.*` | Brain-owned |

**Strategy**: Signals are generated by the Brain from ERP data conditions or manual entry. They cite ERP entities as evidence.

### Evidence

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| (ERP records) | various | `Evidence` | `hpbrain_evidence.*` | Cites ERP data |

**Strategy**: Evidence cites ERP records (e.g., "tbluser row 123 has null department_id"). The `source` field identifies the ERP table and the `content` field carries the relevant data.

### Case, Hypothesis, Recommendation, Decision, Execution, Outcome, Learning

| ERP Table | ERP Column | Brain Entity | Brain Column | Relationship |
|---|---|---|---|---|
| (none) | — | Various | `hpbrain_*.*` | Brain-owned |

**Strategy**: All intelligence-loop entities are Brain-owned. They reference ERP entities through evidence and foreign keys.

## Data Flow

```
ERP Tables (read-mostly)
    ↓
Brain Repositories (direct SQL queries)
    ↓
Brain Controllers (tenant-scoped)
    ↓
Brain Events (OBSERVATION_MADE, etc.)
    ↓
Brain Consumers (signal generation, recommendation)
    ↓
Frontend Screens (real-time or cached)
```

## Reference Strategy

| Use Case | Strategy |
|---|---|
| Organization display | Live query `institute_detail` + `org_details` |
| Department list | Live query `hrms_departments` |
| Person list | Live query `tbluser` |
| Role resolution | Live query `tbluserprofilemaster` at login |
| Skill/assessment | Brain tables (`hpbrain_capabilities`, `hpbrain_capability_proficiency`) |
| Intelligence calculations | Brain tables + ERP live queries |
| Signal generation | ERP conditions → Brain events → Brain signals |
| Audit trail | Brain `hpbrain_audit_logs` |

## What Is NOT Copied

| ERP Data | Reason |
|---|---|
| Raw ERP table dumps | Violates single-source-of-truth; creates sync burden |
| ERP primary keys as Brain PKs | Brain uses UUIDs; ERP uses INTs |
| ERP timestamps as Brain timestamps | Brain has its own `created_date`/`updated_date` |
| ERP status flags | Brain has its own status enums |

## What IS Copied

| ERP Data | Destination | Why |
|---|---|---|
| Department head count | Brain analytics | Computed metric, not master data |
| Assessment coverage | Brain analytics | Aggregation of ERP + Brain data |
| Signal counts | Brain dashboard | Computed from Brain signals |
| Evidence references | Brain evidence | Citations to ERP rows |
