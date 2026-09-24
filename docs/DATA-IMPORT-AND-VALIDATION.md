# Data Import and Validation

## Import Jobs
`hpbrain_import_jobs` tracks async import jobs with status, row counts, error reports, and rollback data.

## Import Logs
`hpbrain_import_logs` captures row-level outcomes: created, updated, skipped, error, duplicate.

## Engine
`App\Services\ImportEngine` handles:
- File validation
- Preview generation
- Duplicate detection
- Async processing via Laravel queue
- Rollback with pre-import state restoration
- Error reporting

## API
- `POST /api/v1/imports/preview`
- `POST /api/v1/imports`
- `POST /api/v1/imports/{tenantId}/{id}/process`
- `POST /api/v1/imports/{tenantId}/{id}/rollback`
- `GET /api/v1/imports/{tenantId}/{id}/logs`

## Supported Entities
- People
- Organization units
- Roles
- Skills
- Competencies
- Assessments
- Reporting relationships

## Safety
- Tenant-scoped throughout
- Idempotent processing
- Dead-letter handling for poison rows
- Pre-import snapshot for rollback
