# Database Integrity Audit

## Scope

This audit covers:
- Brain-owned tables (`hpbrain_*`)
- ERP-owned tables referenced by the Brain (`institute_detail`, `org_details`, `hrms_departments`, `tbluser`, `tbluserprofilemaster`)

## Brain-Owned Tables

### hpbrain_organizations
| Field | Issue |
|---|---|
| `org_code` | `UNIQUE` constraint exists. OK. |
| `tenant_id` | Indexed. OK. |
| `status` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. Archive is handled via `status`. |
| Missing | No foreign key to `institute_detail`. Intentional: Brain does not own ERP data. |

### hpbrain_departments
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| `org_id` | Indexed. Redundant with `tenant_id`. |
| `status` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |
| Missing | No foreign key to `hpbrain_organizations` or ERP `hrms_departments`. |
| Risk | `name` is `TEXT`, not `VARCHAR(255)`. Collation-dependent ordering. |

### hpbrain_people
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. Unique constraint on `(tenant_id, employee_id)`. OK. |
| `email` | Unique constraint on `(tenant_id, email)`. OK. |
| `org_id` | Indexed. Redundant with `tenant_id`. |
| `status` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |
| Missing | No foreign key to `hpbrain_departments`. |

### hpbrain_capabilities
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| `status` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_signals
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_evidence
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_cases
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_decisions
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_recommendations
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_outcomes
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_learnings
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_risks
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_audit_logs
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |
| Risk | No TTL policy. Table grows indefinitely. |

### hpbrain_notifications
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| `user_id` | Not indexed. Queries filter by both. |
| Missing | No `deleted_at` soft-delete field. |
| Risk | No TTL policy. |

### hpbrain_event_store
| Field | Issue |
|---|---|
| `tenant_id` | Not indexed. Should be indexed for tenant-scoped queries. |
| `idempotency_key` | Not indexed. Should be unique or indexed. |
| `status` | Not indexed. Should be indexed for pending/processing queries. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_refresh_tokens
| Field | Issue |
|---|---|
| `jti` | Primary key. OK. |
| `tenant_id` | Indexed (composite with `user_id`). OK. |
| `user_id` | Indexed (composite). OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_dead_letter_queue
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

### hpbrain_consumer_state
| Field | Issue |
|---|---|
| `tenant_id` | Indexed. OK. |
| Missing | No `deleted_at` soft-delete field. |

## ERP-Owned Tables

### institute_detail
| Field | Issue |
|---|---|
| `sub_institute_id` | Primary key. OK. |
| `deleted_at` | Soft delete present. OK. |
| Missing | No `status` field in this table (status is in `tbluser`). |

### org_details
| Field | Issue |
|---|---|
| `sub_institute_id` | Foreign key to `institute_detail`. No DB-level FK constraint. |
| `deleted_at` | Soft delete present. OK. |

### hrms_departments
| Field | Issue |
|---|---|
| `sub_institute_id` | Organization field. OK. |
| `status` | 1=active. OK. |
| `deleted_at` | Soft delete present. OK. |
| Missing | No DB-level foreign key to `institute_detail`. |
| Missing | No `parent_department_id` foreign key to self. |

### tbluser
| Field | Issue |
|---|---|
| `sub_institute_id` | Organization field. OK. |
| `status` | 1=active. OK. |
| `deleted_at` | Soft delete present. OK. |
| `plain_password` | Legacy plaintext password column. Scheduled for removal. |
| Missing | No DB-level foreign key to `institute_detail`. |
| Missing | No DB-level foreign key to `tbluserprofilemaster`. |
| Missing | No DB-level foreign key to `hrms_departments` (department_id). |
| Missing | No DB-level foreign key to job title table (jobtitle_id). |

### tbluserprofilemaster
| Field | Issue |
|---|---|
| `sub_institute_id` | Organization field. OK. |
| `status` | 1=active. OK. |
| Missing | No `deleted_at` soft-delete field. |
| Missing | No DB-level foreign key to `institute_detail`. |

## Integrity Risks

| Risk | Severity | Mitigation |
|---|---|---|
| No foreign keys between Brain and ERP tables | Medium | ERP is read-mostly; FK violations unlikely. Documented in ADR-006. |
| No `deleted_at` on Brain tables | Low | Status field used instead. Consistent. |
| `plain_password` column in `tbluser` | High | Legacy column. Migration plan exists in `PASSWORD-SECURITY-AND-MIGRATION.md`. |
| `hpbrain_event_store.tenant_id` not indexed | Medium | Add index if event volume grows. |
| `hpbrain_notifications.user_id` not indexed | Low | Add composite index `(tenant_id, user_id)` if notification volume grows. |
| Audit log growth | Medium | Add TTL policy or archive job after 1 year. |
| Orphaned Brain records after ERP deletion | Low | ERP `deleted_at` is checked on read; Brain records reference `employee_id` from `tbluser`. |

## Index Recommendations

| Table | Columns | Type |
|---|---|---|
| `hpbrain_event_store` | `tenant_id`, `status`, `created_at` | Composite index |
| `hpbrain_event_store` | `idempotency_key` | Unique index |
| `hpbrain_notifications` | `tenant_id`, `user_id` | Composite index |
| `hpbrain_refresh_tokens` | `expires_at` | Index for cleanup job |
| `hpbrain_audit_logs` | `tenant_id`, `created_at` | Composite index |

## Recommendations

1. Do not add foreign keys to ERP tables without a rollback plan.
2. Add the recommended indexes in a future migration after monitoring query plans.
3. Schedule the `plain_password` removal per `PASSWORD-SECURITY-AND-MIGRATION.md`.
4. Add a scheduled command to prune expired `hpbrain_refresh_tokens`.
5. Add a scheduled command to archive or purge `hpbrain_audit_logs` older than 1 year.
