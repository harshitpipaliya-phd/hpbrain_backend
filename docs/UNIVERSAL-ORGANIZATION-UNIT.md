# Universal Organization Unit

## Unit Types
The platform supports these unit types out of the box:
- department
- school
- faculty
- branch
- clinical_unit
- division
- business_unit
- custom

## Registry
`App\Services\UnitTypeRegistry` manages type definitions. New types can be registered without database schema changes.

## Table
`hpbrain_organization_units` stores the universal unit with:
- `unit_type` - one of the supported types
- `parent_unit_id` - recursive hierarchy
- `head_id` - optional leader
- `cost_center` - financial tracking
- `metadata` - extensible JSON

## Hierarchy API
- `GET /api/v1/organization-units/{tenantId}/hierarchy`
- `GET /api/v1/organization-units/{tenantId}/tree`

## Terminology Mapping
Unit display names are resolved via `hpbrain_terminology` so a unit can render as Department, School, Faculty, Branch, Clinical Unit, Division, or Business Unit depending on industry.
