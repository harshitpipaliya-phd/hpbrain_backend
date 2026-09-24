# INDUSTRY TEMPLATES

## Overview

Industry templates provide pre-configured baselines for each industry type. They are stored in `hpbrain_industry_templates` and include:

- **terminology**: Industry-specific entity names
- **modules**: Default module configurations
- **navigation**: Default navigation structure
- **dashboards**: Default dashboard layouts
- **branding**: Default branding settings
- **workflows**: Industry-specific workflows
- **integrations**: Industry-specific integrations

## Supported Industries

| Code | Name | Default Terminology |
|------|------|---------------------|
| healthcare | Healthcare | Person → Patient |
| k12_education | K-12 Education | Person → Student |
| higher_education | Higher Education | Person → Student |
| corporate | Corporate | Person → Employee |
| manufacturing | Manufacturing | Person → Worker |
| retail | Retail | Person → Associate |
| government | Government | Person → Citizen |
| bfsi | BFSI | Person → Customer |
| ngo | NGO | Person → Beneficiary |
| technology | Technology | Person → Engineer |

## Template Inheritance

When a new organization is created:
1. Look up the industry template for the organization's industry
2. Apply the template's defaults as the baseline
3. Allow organization-level overrides

## Creating a Custom Template

```php
$repo = app(IndustryTemplateRepository::class);
$repo->create($tenantId, [
    'industry_code' => 'healthcare',
    'template_name' => 'Custom Healthcare',
    'terminology'   => ['Person' => 'Patient', 'OrganizationUnit' => 'Department'],
    'modules'       => ['intelligence', 'capabilities', 'decisions'],
    'navigation'    => [...],
    'dashboards'    => [...],
    'branding'      => [...],
    'workflows'     => [...],
    'integrations'  => [...],
    'created_by'    => $userId,
]);
```

## Template Activation

Templates are activated when an organization is created or when an admin applies a new template. The `ConfigVersionService` tracks template changes with full versioning.
