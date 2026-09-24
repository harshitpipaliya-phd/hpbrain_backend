# Universal Organization Engine

## Purpose
Provide a single, configuration-driven organization model that works for Healthcare, K-12, Higher Education, Corporate, Manufacturing, Retail, Government, BFSI, NGOs, and future industries without code changes.

## Core Entities
- `hpbrain_organization_types` - Organization type catalog
- `hpbrain_organization_units` - Universal org units (department/school/faculty/branch/clinical_unit/division/business_unit/custom)
- `hpbrain_roles` - Role definitions with permission templates
- `hpbrain_positions` - Position instances linked to units
- `hpbrain_skills` - Skill library
- `hpbrain_competencies` - Competency framework
- `hpbrain_person_roles` - Person-to-role assignments
- `hpbrain_person_skills` - Person-to-skill assignments
- `hpbrain_person_competencies` - Person-to-competency assignments
- `hpbrain_locations` - Organization locations
- `hpbrain_reporting_structures` - Reporting relationships

## Lifecycle
Organizations progress through: draft -> onboarding -> active -> suspended -> archived.

## API Surface
All endpoints live under `/api/v1` and are tenant-scoped. See `routes/api.php` for the full list.

## Backward Compatibility
Existing `hpbrain_organizations`, `hpbrain_departments`, and `hpbrain_people` tables are unchanged. The new engine coexists with ERP-backed reads.
