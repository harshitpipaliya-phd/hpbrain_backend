# Prompt 3.2 — Universal Organization Engine

## Overview

Prompt 3.2 extends the HP Enterprise Brain with a universal organization engine that replaces hardcoded department and person tables with a flexible, multi-industry taxonomy. This enables the platform to serve education, healthcare, corporate, and government sectors without schema changes.

## New Database Tables (16)

| Table | Purpose |
|-------|---------|
| `hpbrain_organization_types` | Defines organization type taxonomy (department, school, faculty, branch, clinical_unit, division, business_unit, custom) |
| `hpbrain_organization_units` | Universal organizational units with parent-child hierarchy |
| `hpbrain_roles` | Universal role definitions with JSON permissions |
| `hpbrain_positions` | Position definitions linked to units with employment type |
| `hpbrain_skills` | Skill catalog with proficiency levels |
| `hpbrain_competencies` | Competency framework with level descriptors |
| `hpbrain_person_roles` | Person-to-role assignments with date ranges |
| `hpbrain_person_skills` | Person-to-skill assignments with proficiency scoring |
| `hpbrain_person_competencies` | Person-to-competency assignments with target levels |
| `hpbrain_location_types` | Location type definitions |
| `hpbrain_locations` | Physical locations linked to organizations |
| `hpbrain_reporting_structures` | Reporting relationships (direct, functional, matrix) |
| `hpbrain_onboarding_sessions` | Onboarding wizard state with step tracking |
| `hpbrain_import_jobs` | Import job tracking with async queue support |
| `hpbrain_import_logs` | Per-row import logs for audit and rollback |
| `hpbrain_readiness_checks` | Onboarding readiness validation |
| `hpbrain_template_overrides` | Multi-level template inheritance overrides |

## Repositories (16)

Each table has a dedicated repository extending `BaseRepository`:
- `OrganizationTypeRepository`
- `OrganizationUnitRepository`
- `RoleRepository`
- `PositionRepository`
- `SkillRepository`
- `CompetencyRepository`
- `PersonRoleRepository`
- `PersonSkillRepository`
- `PersonCompetencyRepository`
- `LocationTypeRepository`
- `LocationRepository`
- `ReportingStructureRepository`
- `OnboardingSessionRepository`
- `ImportJobRepository`
- `ImportLogRepository`
- `ReadinessCheckRepository`
- `TemplateOverrideRepository`

All repositories follow the established pattern:
- `scoped(tenantId)` for tenant isolation
- `newId()` for UUID generation
- `now()` for MySQL-legal timestamps
- `hydrate()` for JSON column decoding
- `jsonColumns()` declaration

## Services (5)

### OrganizationEngine

Core organization management:
- `createOrganization(string $tenantId, array $data)` — Creates org with units, roles
- `updateOrganization(string $tenantId, string $orgId, array $data)` — Updates org
- `archiveOrganization(string $tenantId, string $orgId)` — Archives org units
- `getHierarchy(string $tenantId, string $orgId)` — Returns org unit tree
- `getReportingStructure(string $tenantId, string $orgId, string $personId)` — Returns reporting chain
- `assignRole(string $tenantId, string $personId, string $roleId, array $data)` — Assigns role
- `assignSkill(string $tenantId, string $personId, string $skillId, array $data)` — Assigns skill
- `assignCompetency(string $tenantId, string $personId, string $competencyId, array $data)` — Assigns competency

### OnboardingEngine

Onboarding workflow management:
- `startOnboarding(string $tenantId, array $initialData)` — Creates onboarding session
- `completeStep(string $sessionId, string $step, array $data)` — Marks step complete
- `getNextStep(string $sessionId)` — Returns next onboarding step
- `validateStep(string $sessionId, string $step)` — Validates step data
- `activateOrganization(string $sessionId)` — Activates org after onboarding
- `getReadinessStatus(string $orgId)` — Gets readiness checks
- `runReadinessChecks(string $orgId)` — Runs all readiness checks
- `abandonOnboarding(string $sessionId)` — Abandons session

### TemplateInheritanceEngine

Multi-level template inheritance:
- `resolve(string $tenantId, string $orgId, string $templateType, string $templateKey, string $context)` — Resolves value with inheritance chain
- `getEffectiveValue(string $tenantId, string $orgId, string $templateType, string $templateKey)` — Gets resolved value
- `setOverride(string $tenantId, string $orgId, string $templateType, string $templateKey, string $level, array $data)` — Sets override at level

Inheritance chain: platform default → industry template → organization override → role override → user preference

### ImportEngine

Secure CSV/XLSX import:
- `validateFile(string $filePath, string $entityType)` — Validates file format
- `previewImport(string $tenantId, string $orgId, string $filePath, string $entityType)` — Returns preview with validation
- `detectDuplicates(string $tenantId, array $rows, string $entityType)` — Detects duplicate records
- `startImport(string $tenantId, string $orgId, array $rows, string $entityType, array $options)` — Creates async import job
- `processImport(string $jobId)` — Processes import job
- `rollbackImport(string $jobId)` — Rolls back import
- `getImportLogs(string $jobId)` — Gets import logs

Validates: required fields, data types, relationships, duplicates
Rollback: stores pre-import state for reversal

### UnitTypeRegistry

Universal organization unit types:
- `getTypes()` — Gets all unit types
- `getType(string $typeKey)` — Gets specific type definition
- `registerType(string $typeKey, array $definition)` — Registers new type

Built-in types: department, school, faculty, branch, clinical_unit, division, business_unit, custom

## Controllers (16)

- `OrganizationTypeController` — CRUD for organization types
- `OrganizationUnitController` — CRUD + hierarchy/tree endpoints
- `RoleController` — CRUD for roles
- `PositionController` — CRUD for positions
- `SkillController` — CRUD for skills
- `CompetencyController` — CRUD for competencies
- `PersonRoleController` — CRUD for person-role assignments
- `PersonSkillController` — CRUD for person-skill assignments
- `PersonCompetencyController` — CRUD for person-competency assignments
- `LocationTypeController` — CRUD for location types
- `LocationController` — CRUD for locations
- `ReportingStructureController` — CRUD for reporting relationships
- `OnboardingController` — Onboarding session management
- `ImportController` — Import job management
- `ReadinessCheckController` — Readiness check management
- `TemplateOverrideController` — Template override management

## API Endpoints (75+)

All endpoints are under `/api/v1/` and require `permission:settings.manage` for mutating operations.

### Organization Types
- `GET /organization-types/{tenantId}` — List types
- `POST /organization-types` — Create type
- `GET /organization-types/{tenantId}/{id}` — Show type
- `PATCH /organization-types/{tenantId}/{id}` — Update type
- `DELETE /organization-types/{tenantId}/{id}` — Delete type

### Organization Units
- `GET /organization-units/{tenantId}` — List units
- `POST /organization-units` — Create unit
- `GET /organization-units/{tenantId}/{id}` — Show unit
- `PATCH /organization-units/{tenantId}/{id}` — Update unit
- `DELETE /organization-units/{tenantId}/{id}` — Delete unit
- `GET /organization-units/{tenantId}/hierarchy` — Get hierarchy
- `GET /organization-units/{tenantId}/tree` — Get tree

### Roles
- `GET /roles/{tenantId}` — List roles
- `POST /roles` — Create role
- `GET /roles/{tenantId}/{id}` — Show role
- `PATCH /roles/{tenantId}/{id}` — Update role
- `DELETE /roles/{tenantId}/{id}` — Delete role

### Positions
- `GET /positions/{tenantId}` — List positions
- `POST /positions` — Create position
- `GET /positions/{tenantId}/{id}` — Show position
- `PATCH /positions/{tenantId}/{id}` — Update position
- `DELETE /positions/{tenantId}/{id}` — Delete position

### Skills
- `GET /skills/{tenantId}` — List skills
- `POST /skills` — Create skill
- `GET /skills/{tenantId}/{id}` — Show skill
- `PATCH /skills/{tenantId}/{id}` — Update skill
- `DELETE /skills/{tenantId}/{id}` — Delete skill

### Competencies
- `GET /competencies/{tenantId}` — List competencies
- `POST /competencies` — Create competency
- `GET /competencies/{tenantId}/{id}` — Show competency
- `PATCH /competencies/{tenantId}/{id}` — Update competency
- `DELETE /competencies/{tenantId}/{id}` — Delete competency

### Person Roles
- `GET /person-roles/{tenantId}` — List assignments
- `POST /person-roles` — Create assignment
- `GET /person-roles/{tenantId}/{id}` — Show assignment
- `PATCH /person-roles/{tenantId}/{id}` — Update assignment
- `DELETE /person-roles/{tenantId}/{id}` — Delete assignment

### Person Skills
- `GET /person-skills/{tenantId}` — List assignments
- `POST /person-skills` — Create assignment
- `GET /person-skills/{tenantId}/{id}` — Show assignment
- `PATCH /person-skills/{tenantId}/{id}` — Update assignment
- `DELETE /person-skills/{tenantId}/{id}` — Delete assignment

### Person Competencies
- `GET /person-competencies/{tenantId}` — List assignments
- `POST /person-competencies` — Create assignment
- `GET /person-competencies/{tenantId}/{id}` — Show assignment
- `PATCH /person-competencies/{tenantId}/{id}` — Update assignment
- `DELETE /person-competencies/{tenantId}/{id}` — Delete assignment

### Location Types
- `GET /location-types/{tenantId}` — List types
- `POST /location-types` — Create type
- `GET /location-types/{tenantId}/{id}` — Show type
- `PATCH /location-types/{tenantId}/{id}` — Update type
- `DELETE /location-types/{tenantId}/{id}` — Delete type

### Locations
- `GET /locations/{tenantId}` — List locations
- `POST /locations` — Create location
- `GET /locations/{tenantId}/{id}` — Show location
- `PATCH /locations/{tenantId}/{id}` — Update location
- `DELETE /locations/{tenantId}/{id}` — Delete location

### Reporting Structures
- `GET /reporting-structures/{tenantId}` — List relationships
- `POST /reporting-structures` — Create relationship
- `GET /reporting-structures/{tenantId}/{id}` — Show relationship
- `PATCH /reporting-structures/{tenantId}/{id}` — Update relationship
- `DELETE /reporting-structures/{tenantId}/{id}` — Delete relationship

### Onboarding
- `GET /onboarding/{tenantId}` — List sessions
- `POST /onboarding/{tenantId}/start` — Start onboarding
- `GET /onboarding/{tenantId}/{id}` — Show session
- `POST /onboarding/{tenantId}/{id}/complete-step` — Complete step
- `GET /onboarding/{tenantId}/{id}/next-step` — Get next step
- `POST /onboarding/{tenantId}/{id}/validate-step` — Validate step
- `POST /onboarding/{tenantId}/{id}/activate` — Activate org
- `POST /onboarding/{tenantId}/{id}/abandon` — Abandon session
- `GET /onboarding/{tenantId}/{id}/readiness` — Get readiness status
- `POST /onboarding/{tenantId}/{id}/readiness/run` — Run readiness checks

### Imports
- `GET /imports/{tenantId}` — List import jobs
- `GET /imports/{tenantId}/{id}` — Show import job
- `POST /imports/validate` — Validate file
- `POST /imports/preview` — Preview import
- `POST /imports/detect-duplicates` — Detect duplicates
- `POST /imports` — Start import
- `POST /imports/{tenantId}/{id}/process` — Process import
- `POST /imports/{tenantId}/{id}/rollback` — Rollback import
- `GET /imports/{tenantId}/{id}/logs` — Get import logs

### Readiness Checks
- `GET /readiness-checks/{tenantId}` — List checks
- `POST /readiness-checks` — Create check
- `GET /readiness-checks/{tenantId}/{id}` — Show check
- `PATCH /readiness-checks/{tenantId}/{id}` — Update check
- `DELETE /readiness-checks/{tenantId}/{id}` — Delete check

### Template Overrides
- `GET /template-overrides/{tenantId}` — List overrides
- `POST /template-overrides` — Create override
- `GET /template-overrides/{tenantId}/{id}` — Show override
- `PATCH /template-overrides/{tenantId}/{id}` — Update override
- `DELETE /template-overrides/{tenantId}/{id}` — Delete override

## Seeders

- `OrganizationTypeSeeder` — Seeds 8 organization types
- `RoleSeeder` — Seeds 6 default roles
- `SkillSeeder` — Seeds 6 default skills
- `CompetencySeeder` — Seeds 4 default competencies
- `LocationTypeSeeder` — Seeds 5 location types

## Frontend

### API Clients (`web/src/api/`)
- `organizationTypes.ts`
- `organizationUnits.ts`
- `roles.ts`
- `positions.ts`
- `skills.ts`
- `competencies.ts`
- `personRoles.ts`
- `personSkills.ts`
- `personCompetencies.ts`
- `locations.ts`
- `locationTypes.ts`
- `reportingStructure.ts`
- `onboarding.ts`
- `imports.ts`
- `readinessChecks.ts`
- `templateOverrides.ts`

### Components (`web/src/components/`)
- `organization/` — Organization management components
- `import/` — Import center components
- `templates/` — Template inheritance components

## Tests

- `tests/Feature/UniversalOrganizationEngineTest.php` — Feature tests for new endpoints
- `tests/Support/BuildsBrainSchema.php` — Updated with 16 new table schemas

## Implementation Rules Enforced

1. Every table has `tenant_id`
2. Every query uses `scoped($tenantId)`
3. All IDs are UUIDs via `newId()`
4. All timestamps use `$this->now()` for MySQL-legal formatting
5. All JSON columns listed in `jsonColumns()`
6. No Eloquent models — raw SQL via repositories
7. All controllers extend `App\Http\Controllers\Controller`
8. All routes under `v1` prefix
9. Import processing async via Laravel queue (engine supports it)
10. Import rollback stores pre-import state
11. Onboarding supports resume via `current_step` and `completed_steps`
12. Template inheritance: platform → industry → organization → role → user

## Backward Compatibility

- All new tables are additive — no existing tables modified
- Existing Prompt 3.1 APIs unchanged
- New controllers follow same authorization pattern
- Existing `OrganizationController` and `DepartmentController` unchanged
- ERP-owned tables (`institute_detail`, `hrms_departments`, `tbluser`) untouched

## Next Steps for Prompt 3.3

1. GraphQL API layer for complex organization queries
2. Real-time org chart visualization
3. Advanced import validation with field mapping UI
4. Multi-language support for terminology
5. Organization comparison and analytics
6. Bulk operations for org structure changes
7. Audit trail for all org mutations
