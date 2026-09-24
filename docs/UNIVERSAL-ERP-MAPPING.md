# UNIVERSAL ERP MAPPING

## Overview

The ERP Mapping system allows the Brain to translate ERP-specific entities and fields into its universal domain model. This is critical for multi-ERP support.

## Mapping Structure

```json
{
  "source_system": "erp",
  "source_entity": "tbluser",
  "source_field": "id",
  "universal_entity": "Person",
  "universal_field": "id",
  "mapping_type": "direct",
  "transform_expression": null,
  "lookup_table": null
}
```

## Mapping Types

| Type | Description |
|------|-------------|
| direct | Field maps directly without transformation |
| transform | Field requires a transformation expression |
| lookup | Field requires a lookup in another table |

## Source Systems

| System | Description |
|--------|-------------|
| erp | Enterprise Resource Planning |
| lms | Learning Management System |
| hris | Human Resource Information System |

## Universal Entities

| Universal Entity | ERP Source | Description |
|------------------|------------|-------------|
| Person | tbluser | Individual person |
| OrganizationUnit | hrms_departments | Department or unit |
| Role | tbluserprofilemaster | User role or profile |
| Skill | Custom skill tables | Individual skills |
| Competency | Custom competency tables | Competency frameworks |
| Capability | Capability definitions | Organizational capabilities |

## Creating Mappings

```http
POST /api/v1/entity-mappings
{
  "source_system": "erp",
  "source_entity": "tbluser",
  "source_field": "department_id",
  "universal_entity": "OrganizationUnit",
  "universal_field": "id",
  "mapping_type": "lookup",
  "lookup_table": "hrms_departments"
}
```

## Transform Expressions

For `transform` type mappings, use JavaScript-like expressions:

```json
{
  "mapping_type": "transform",
  "transform_expression": "value ? value.toUpperCase() : null"
}
```

## Backward Compatibility

The existing ERP mappings for Healthcare and Scholar are preserved. New mappings are added alongside existing ones, not replacing them.
