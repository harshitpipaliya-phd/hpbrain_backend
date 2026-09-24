# PART 3.1 — UNIVERSAL PLATFORM ARCHITECTURE

## Overview

Prompt 3.1 implements the **Universal Platform Foundation** for HP Enterprise Brain, transforming it from a single-industry (Healthcare/Scholar) application into a multi-industry, configurable platform.

## Key Concepts

### Industry Abstraction
Every organization belongs to an industry. The platform provides:
- Industry-specific terminology (e.g., "Patient" for Healthcare, "Student" for Education)
- Industry-specific navigation and dashboards
- Industry-specific module configurations

### Configuration Inheritance
Configuration flows through a hierarchy:
1. **Platform defaults** → built-in constants
2. **Industry template** → per-industry baseline
3. **Organization override** → org-specific settings
4. **Role override** → role-specific settings
5. **User preference** → individual user settings

### Tenant Isolation
All data is scoped by `tenant_id`. Every query goes through `scoped($tenantId)` to prevent cross-tenant data leakage.

## Architecture Layers

### Data Layer
- 16 new database tables with `hpbrain_` prefix
- All IDs are VARCHAR(36) UUIDs
- All tables have `tenant_id`, `created_by`, `created_date`, `updated_date`

### Repository Layer
- 16 repositories extending `BaseRepository`
- Raw SQL queries with tenant scoping
- JSON column hydration via `jsonColumns()`

### Service Layer
- `ConfigurationEngine` — central config resolution
- `TenantConfigCache` — tenant-aware caching
- `ConfigVersionService` — versioning and rollback
- `NavigationBuilder` — dynamic navigation assembly
- `DashboardBuilder` — dashboard resolution

### API Layer
- 15 controllers with CRUD endpoints
- All routes under `/api/v1` prefix
- Permission-based access control

### Frontend Layer
- React components for state rendering
- Context providers for config and feature flags
- Dynamic navigation and dashboard components
- API clients for all endpoints

## Backward Compatibility

The existing `hpbrain_organizations`, `hpbrain_departments`, and `hpbrain_people` tables are NOT modified. The new `hpbrain_organization_configs` table provides Brain-owned org settings alongside ERP-owned data.

## Next Steps

- Prompt 3.2: Industry-specific implementations
- Prompt 3.3: Advanced configuration UI
- Prompt 4.x: Multi-tenant SaaS features
