# UNIVERSAL DOMAIN MODEL

## Entity Relationships

```
hpbrain_industries
  └─── hpbrain_industry_templates (1:1)
  └─── hpbrain_terminology (1:N)
  └─── hpbrain_navigation_items (1:N)

hpbrain_organization_configs
  └─── scoped by (tenant_id, org_id)

hpbrain_organization_modules
  └─── hpbrain_modules (N:1)
  └─── scoped by (tenant_id, org_id)

hpbrain_dashboards
  └─── hpbrain_dashboard_layouts (1:1)
  └─── hpbrain_dashboard_widgets (N:M via layout)

hpbrain_forms
  └─── scoped by (tenant_id, org_id, form_key, version)

hpbrain_config_versions
  └─── versioned config history
  └─── supports draft/active/archived/rolled_back

hpbrain_branding
  └─── scoped by (tenant_id, org_id) unique

hpbrain_themes
  └─── scoped by tenant_id

hpbrain_entity_mappings
  └─── maps ERP fields to universal entities

hpbrain_feature_flags
  └─── multi-level flags (platform/plan/organization/role/user)
```

## Core Entities

### Industry
The top-level organizational classification. Examples: Healthcare, K-12 Education, Corporate.

### Organization
An existing ERP organization (from `institute_detail`). Brain does NOT own this table.

### Terminology
Industry-specific names for universal entity types. Allows the platform to adapt its vocabulary.

### Module
A functional capability of the platform. Core modules are always available; others can be enabled per organization.

### Feature Flag
Controls feature rollout at multiple levels with percentage-based rollout support.

### Navigation
Role-based, industry-specific navigation tree with module and flag filtering.

### Dashboard
A named layout of widgets, configurable per organization, industry, and role.

### Widget
A reusable UI component with a config schema and default configuration.

### Branding
Visual identity configuration per organization.

### Theme
Color, typography, and spacing definitions for the UI.

### Form
Dynamic form definitions with validation rules.

### Config Version
Versioned configuration snapshots with activation and rollback support.

### Entity Mapping
Maps ERP-specific fields to universal Brain entities.
