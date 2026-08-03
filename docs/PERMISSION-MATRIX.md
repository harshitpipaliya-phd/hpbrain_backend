# Permission Matrix

## Roles

| Role | Description | Source |
|---|---|---|
| `platform_super_admin` | Full access across all organizations | Hardcoded / special token |
| `admin` | Platform admin, can cross tenants | JWT claim |
| `tenant_admin` | Organization admin, single tenant | JWT claim |
| `manager` | Department/team manager | JWT claim |
| `analyst` | Data analyst, read/write but no approval | JWT claim |
| `viewer` | Read-only access | JWT claim |
| `member` | Basic employee access | JWT claim |

## Permissions

| Permission | Description |
|---|---|
| `read` | Read any resource in the tenant |
| `create` | Create new records |
| `update` | Modify existing records |
| `delete` | Delete records |
| `evidence.curate` | Curate and manage evidence |
| `decision.approve` | Approve decisions |
| `eso.execute` | Execute ESOs |
| `settings.manage` | Manage tenant settings |
| `apikey.manage` | Manage API keys |
| `events.manage` | Manage event processing |
| `tenant.manage` | Manage tenants/organizations |

## Role-Permission Matrix

| Permission | viewer | analyst | manager | admin | tenant_admin |
|---|---|---|---|---|---|
| `read` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `create` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `update` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `delete` | ✗ | ✗ | ✗ | ✓ | ✓ |
| `evidence.curate` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `decision.approve` | ✗ | ✗ | ✓ | ✓ | ✓ |
| `eso.execute` | ✗ | ✗ | ✓ | ✓ | ✓ |
| `settings.manage` | ✗ | ✗ | ✗ | ✓ | ✓ |
| `apikey.manage` | ✗ | ✗ | ✗ | ✓ | ✓ |
| `events.manage` | ✗ | ✗ | ✗ | ✓ | ✓ |
| `tenant.manage` | ✗ | ✗ | ✗ | ✓ | ✓ |

## Role Resolution

Roles are resolved from `tbluser.user_profile_id` by looking up `tbluserprofilemaster.name`:

| Profile Name Pattern | Resolved Role |
|---|---|
| `super admin`, `superadmin` | `admin` |
| `admin` | `tenant_admin` |
| `manager`, `head` | `manager` |
| `analyst` | `analyst` |
| `viewer`, `readonly`, `read-only` | `viewer` |
| anything else | `member` |

## Frontend Navigation by Role

### Platform Super Admin
- Home
- All organizations
- All settings
- Event processing
- Audit logs

### Organization Admin
- Home
- Organization profile
- Departments
- People
- Capabilities
- Intelligence
- Reports
- Administration
- Audit logs

### Manager
- Home
- My Department
- People
- Skills
- Assessments
- Actions
- Reports

### Analyst
- Home
- Departments
- People
- Capabilities
- Evidence
- Intelligence
- Reports

### Viewer
- Home
- Departments (read-only)
- People (read-only)
- Reports (read-only)

### Employee
- Home
- My Profile
- My Skills
- My Assessments

## Enforcement

- Backend: `RequirePermission` middleware on routes
- Frontend: Role-based menu rendering
- Both required: frontend hiding is not security
