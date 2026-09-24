# Audit and Logging Policy

## Principles

1. **No secrets in logs**: Passwords, password hashes, raw tokens, API keys, and database credentials must never appear in log output.
2. **Structured logging**: All log entries use a consistent structure with timestamp, level, message, and context.
3. **Tenant-aware**: Every log entry includes the tenant ID when available.
4. **Correlation IDs**: Every request carries a correlation ID for tracing.
5. **Fail-safe**: Logging failures must not break the request path.

## What Must Be Logged

| Event | Level | Required Fields |
|---|---|---|
| Login success | info | `tenant_id`, `user_id`, `role`, `ip_hash`, `user_agent_summary` |
| Login failure | warning | `tenant_id` (if resolvable), `reason`, `ip_hash`, `user_agent_summary` |
| Logout | info | `tenant_id`, `user_id`, `jti` |
| Refresh success | info | `tenant_id`, `user_id`, `jti` |
| Refresh failure | warning | `reason`, `jti` |
| Cross-tenant attempt | warning | `token_tenant_id`, `route_tenant_id`, `user_id`, `ip_hash` |
| Permission denied | warning | `tenant_id`, `user_id`, `permission`, `route` |
| Password change | info | `tenant_id`, `user_id` |
| User deactivation | info | `tenant_id`, `user_id`, `actor_id` |
| Role change | info | `tenant_id`, `user_id`, `old_role`, `new_role`, `actor_id` |
| Permission change | info | `tenant_id`, `user_id`, `permission`, `action`, `actor_id` |
| Create/update/archive/delete | info | `tenant_id`, `user_id`, `entity_type`, `entity_id`, `action` |
| Decision approval | info | `tenant_id`, `user_id`, `entity_type`, `entity_id`, `action` |
| AI intelligence generation | info | `tenant_id`, `user_id`, `provider`, `model` |
| Export | info | `tenant_id`, `user_id`, `format`, `entity_type` |
| Sensitive report access | info | `tenant_id`, `user_id`, `report_type` |

## What Must Never Be Logged

- Passwords (raw or hashed)
- Raw access tokens
- Raw refresh tokens
- Complete user records
- Database credentials
- `.env` values
- Stack traces in production
- SQL queries in production

## Log Format

```json
{
  "timestamp": "2026-07-31T19:00:00+05:30",
  "level": "info",
  "message": "Login success",
  "tenant_id": "1",
  "user_id": "123",
  "role": "tenant_admin",
  "ip_hash": "sha256:a1b2c3...",
  "user_agent_summary": "Chrome 115 / Windows",
  "correlation_id": "uuid"
}
```

## Sensitive Value Masking

- IP addresses: store SHA-256 hash, not raw IP.
- User agents: store summary (browser + OS), not full string.
- Tokens: store `jti` only, not raw token.

## Audit Storage

Audit events are stored in `hpbrain_audit_logs`. The table is append-only. No update or delete operations are performed on audit rows.

## Retention

- Audit logs: retain for 1 year, then archive to cold storage.
- Refresh tokens: retain until revoked or expired, then prune via scheduled job.

## Review

Security team must review audit logs weekly for:
- Repeated cross-tenant attempts
- Unusual login patterns
- Permission escalation attempts
- Failed refresh bursts
