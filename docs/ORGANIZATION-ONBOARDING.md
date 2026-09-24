# Organization Onboarding

## Overview
A 12-step wizard that guides a new organization from basic details through activation, without developer intervention.

## Steps
1. Basic organization details
2. Industry/template selection
3. Terminology
4. Organization structure
5. Roles and permissions
6. Modules
7. Branding
8. Data source and ERP mapping
9. Import users and departments
10. Validate data
11. Preview
12. Activate

## Session Model
`hpbrain_onboarding_sessions` tracks progress via `current_step`, `completed_steps`, and `status`. Sessions can be resumed.

## Readiness Gates
`hpbrain_readiness_checks` enforces activation gates before an organization can go live.

## API
- `POST /api/v1/onboarding/{tenantId}/start`
- `POST /api/v1/onboarding/{tenantId}/{id}/complete-step`
- `GET /api/v1/onboarding/{tenantId}/{id}/next-step`
- `POST /api/v1/onboarding/{tenantId}/{id}/activate`
- `GET /api/v1/onboarding/{tenantId}/{id}/readiness`

## Import Support
Secure CSV/XLSX import with preview, duplicate detection, validation, rollback, async processing, logs, and error reports.
