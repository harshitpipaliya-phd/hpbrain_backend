# ERP Data Dictionary

## Source of Truth

The institute ERP is the source of truth for organizational data. The Enterprise Brain reads from these tables; it does not own, modify, or delete ERP master data.

## Database

- **Name**: `hp_erp` (configured in `.env` as `DB_DATABASE`)
- **Connection**: MySQL (default)
- **Access**: Read-mostly for Brain; ERP owns writes

## Tables

### institute_detail

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `id` | INT | NO | Auto-increment primary key |
| `sub_institute_id` | INT | NO | Organization identifier (used by Brain as tenant ID) |
| `organization_name` | VARCHAR(255) | NO | Display name |
| `organization_code` | VARCHAR(100) | YES | Organization code |
| `industry_type` | VARCHAR(100) | YES | Industry classification |
| `created_by` | VARCHAR(64) | YES | Creator identifier |
| `created_at` | TIMESTAMP | YES | Creation timestamp |
| `updated_at` | TIMESTAMP | YES | Update timestamp |
| `deleted_at` | TIMESTAMP | YES | Soft delete timestamp |

- **Organization field**: `sub_institute_id`
- **Soft delete**: `deleted_at`
- **Indexes**: `idx_institute_detail_sub (sub_institute_id)`
- **Used by**: `OrganizationRepository`, `AuthController::loadOrganization()`
- **Brain mapping**: `Organization`

### org_details

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `id` | INT | NO | Auto-increment primary key |
| `sub_institute_id` | INT | NO | Foreign key to `institute_detail.sub_institute_id` |
| `legal_name` | VARCHAR(255) | YES | Legal entity name |
| `logo` | VARCHAR(512) | YES | Logo URL or path |
| `created_by` | VARCHAR(64) | YES | Creator identifier |
| `created_at` | TIMESTAMP | YES | Creation timestamp |
| `updated_at` | TIMESTAMP | YES | Update timestamp |
| `deleted_at` | TIMESTAMP | YES | Soft delete timestamp |

- **Organization field**: `sub_institute_id`
- **Soft delete**: `deleted_at`
- **Indexes**: `idx_org_details_sub (sub_institute_id)`
- **Used by**: `OrganizationRepository`, `AuthController::loadOrganization()`
- **Brain mapping**: `Organization` (extended details)

### hrms_departments

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `id` | INT | NO | Auto-increment primary key |
| `sub_institute_id` | INT | NO | Organization identifier |
| `department` | VARCHAR(255) | NO | Department name |
| `roles_responsibility` | TEXT | YES | Description of roles and responsibilities |
| `parent_id` | INT | YES | Parent department ID (0 = root) |
| `status` | TINYINT | NO | 1 = active, 0 = inactive |
| `is_calculated` | TINYINT | NO | System-calculated flag |
| `created_by` | VARCHAR(64) | YES | Creator identifier |
| `created_at` | TIMESTAMP | YES | Creation timestamp |
| `updated_at` | TIMESTAMP | YES | Update timestamp |
| `deleted_at` | TIMESTAMP | YES | Soft delete timestamp |

- **Organization field**: `sub_institute_id`
- **Soft delete**: `deleted_at`
- **Status field**: `status` (1 = active)
- **Indexes**: `idx_hrms_departments_sub (sub_institute_id)`
- **Used by**: `DepartmentController`, `PersonController::twin()`
- **Brain mapping**: `Department`

### tbluserprofilemaster

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `id` | INT | NO | Auto-increment primary key |
| `sub_institute_id` | INT | NO | Organization identifier |
| `name` | VARCHAR(100) | NO | Profile name (mapped to Brain role) |
| `status` | TINYINT | NO | 1 = active, 0 = inactive |

- **Organization field**: `sub_institute_id`
- **Status field**: `status` (1 = active)
- **Indexes**: `idx_profile_sub (sub_institute_id)`
- **Used by**: `AuthController::resolveRole()`
- **Brain mapping**: `Role` (profile name → Brain role)

### tbluser

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `id` | INT | NO | Auto-increment primary key |
| `employee_no` | VARCHAR(64) | YES | Employee number |
| `password` | VARCHAR(255) | YES | Password hash (bcrypt/legacy) |
| `plain_password` | VARCHAR(255) | YES | Legacy plaintext password (migration bridge) |
| `first_name` | VARCHAR(128) | YES | First name |
| `last_name` | VARCHAR(128) | YES | Last name |
| `email` | VARCHAR(255) | YES | Email address (login identifier) |
| `mobile` | VARCHAR(32) | YES | Phone number |
| `gender` | VARCHAR(16) | YES | Gender |
| `birthdate` | DATE | YES | Date of birth |
| `joined_date` | DATE | YES | Joining date |
| `department_id` | INT | YES | Department ID (references `hrms_departments.id`) |
| `jobtitle_id` | INT | YES | Job title ID (references external table) |
| `city` | VARCHAR(128) | YES | City |
| `state` | VARCHAR(128) | YES | State |
| `image` | VARCHAR(512) | YES | Profile image URL |
| `sub_institute_id` | INT | NO | Organization identifier |
| `user_profile_id` | INT | YES | Profile ID (references `tbluserprofilemaster.id`) |
| `status` | TINYINT | NO | 1 = active, 0 = inactive |
| `created_by` | VARCHAR(64) | YES | Creator identifier |
| `created_at` | TIMESTAMP | YES | Creation timestamp |
| `updated_at` | TIMESTAMP | YES | Update timestamp |
| `deleted_at` | TIMESTAMP | YES | Soft delete timestamp |

- **Organization field**: `sub_institute_id`
- **Soft delete**: `deleted_at`
- **Status field**: `status` (1 = active)
- **Indexes**: `idx_tbluser_sub (sub_institute_id)`, `idx_tbluser_dept (department_id)`
- **Used by**: `AuthController`, `PersonController`, `KasbaController`
- **Brain mapping**: `Person`

## Foreign Key Relationships (ERP)

| Child Table | Child Column | Parent Table | Parent Column | Notes |
|---|---|---|---|---|
| `tbluser` | `department_id` | `hrms_departments` | `id` | Not enforced at DB level |
| `tbluser` | `user_profile_id` | `tbluserprofilemaster` | `id` | Not enforced at DB level |
| `tbluser` | `sub_institute_id` | `institute_detail` | `sub_institute_id` | Not enforced at DB level |
| `tbluserprofilemaster` | `sub_institute_id` | `institute_detail` | `sub_institute_id` | Not enforced at DB level |
| `org_details` | `sub_institute_id` | `institute_detail` | `sub_institute_id` | Not enforced at DB level |

## Data Quality Observations

| Issue | Table | Severity | Notes |
|---|---|---|---|
| No DB-level foreign keys | All ERP tables | Medium | Orphaned records possible; Brain validates at write time |
| `plain_password` column | `tbluser` | High | Legacy plaintext passwords; migration plan exists |
| `jobtitle_id` references unknown table | `tbluser` | Low | External job title table not in Brain scope |
| `parent_id = 0` for root departments | `hrms_departments` | Low | Convention, not NULL |
| No skill/assessment tables | N/A | High | Skill framework must be built in Brain tables |
| No assessment/completion tables | N/A | High | No ERP source for skill assessments |
| No learning/training tables | N/A | Medium | Learning must be tracked in Brain tables |

## Data Freshness

| Table | Update Frequency | Sync Method |
|---|---|---|
| `institute_detail` | Rare (org changes) | Direct read |
| `org_details` | Rare (org changes) | Direct read |
| `hrms_departments` | Occasional (reorgs) | Direct read |
| `tbluserprofilemaster` | Rare (role changes) | Direct read |
| `tbluser` | Frequent (HR updates) | Direct read |

All ERP tables are read directly by the Brain. No sync mechanism exists; data is fresh at query time.
