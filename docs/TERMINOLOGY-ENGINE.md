# TERMINOLOGY ENGINE

## Overview

The Terminology Engine allows the platform to adapt its vocabulary based on industry and entity type. This is critical for the multi-industry foundation.

## Terminology Structure

Each terminology entry has:
- `industry_code`: The industry this applies to
- `entity_type`: The universal entity type (Person, OrganizationUnit, Role, etc.)
- `display_name`: The singular display name
- `plural_name`: The plural display name

## Resolving Terminology

```php
$engine = app(ConfigurationEngine::class);
$displayName = $engine->resolveTerminology($tenantId, 'healthcare', 'Person');
// Returns "Patient"
```

## Fallback Behavior

1. Look for tenant-specific terminology
2. Look for industry template terminology
3. Return null if not found

## Standard Entity Types

| Entity Type | Healthcare | K-12 Education | Corporate |
|-------------|------------|----------------|-----------|
| Person | Patient | Student | Employee |
| OrganizationUnit | Department | School | Department |
| Role | Provider | Teacher | Manager |
| Skill | Clinical Skill | Teaching Skill | Technical Skill |
| Competency | Clinical Competency | Teaching Competency | Leadership Competency |
| Capability | Medical Capability | Educational Capability | Business Capability |

## Custom Terminology

Organizations can add custom terminology via the Terminology API:

```http
POST /api/v1/terminology
{
  "industry_code": "healthcare",
  "entity_type": "Person",
  "display_name": "Patient",
  "plural_name": "Patients"
}
```

## UI Integration

The frontend `ConfigContext` provides terminology resolution:

```tsx
const { terminology } = useConfig();
const personLabel = terminology['Person'] || 'Person';
```
