# API Contracts

## Base URL

```
/api/v1
```

## Authentication

All authenticated endpoints require a Bearer access token in the `Authorization` header.

```
Authorization: Bearer <accessToken>
```

The access token is a JWT with claims:
- `sub` — user ID
- `tenantId` — organization ID
- `role` — user role
- `type` — `access`
- `jti` — unique token ID
- `iat` — issued at
- `exp` — expires at

## Standard Responses

### Success

```json
{
  "success": true,
  "message": "Operation completed.",
  "data": {}
}
```

### Error

```json
{
  "success": false,
  "error": "error_code",
  "message": "Human-readable message.",
  "details": {}
}
```

### Error Codes

| Code | HTTP Status | Meaning |
|---|---|---|
| `invalid_credentials` | 401 | Email or password is wrong. |
| `invalid_token` | 401 | Token is missing, expired, or malformed. |
| `wrong_token_type` | 401 | Token is not an access token. |
| `forbidden` | 403 | User lacks required permission. |
| `tenant_mismatch` | 403 | Route tenant does not match token tenant. |
| `validation_failed` | 422 | Request body failed validation. |
| `not_found` | 404 | Resource does not exist. |
| `rate_limit_exceeded` | 429 | Too many requests. |
| `server_error` | 500 | Unexpected server error. |

## Pagination

List endpoints support:

```
?page=1
&perPage=25
&sort=field_name
&direction=asc|desc
&filter[key]=value
&search=query
```

Sort and filter columns are allowlisted per endpoint. Raw frontend column names are never passed to SQL.

## Endpoints

### Authentication

#### POST /auth/login

Login with email and password.

**Request:**
```json
{
  "email": "user@org.com",
  "password": "password"
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "accessToken": "...",
    "refreshToken": "...",
    "user": {
      "id": "1",
      "email": "user@org.com",
      "firstName": "First",
      "lastName": "Last",
      "employeeNo": "EMP001",
      "profileId": "2",
      "role": "tenant_admin"
    },
    "organization": {
      "id": "1",
      "name": "Organization",
      "logo": null
    }
  }
}
```

**Response 401:**
```json
{
  "success": false,
  "error": "invalid_credentials",
  "message": "Incorrect email or password."
}
```

#### POST /auth/refresh

Refresh access token.

**Request:**
```json
{
  "refreshToken": "..."
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "accessToken": "..."
  }
}
```

#### POST /auth/logout

Revoke refresh token.

**Request:**
```json
{
  "refreshToken": "..."
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "ok": true
  }
}
```

#### POST /auth/change-password

Change own password.

**Request:**
```json
{
  "currentPassword": "...",
  "newPassword": "..."
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "ok": true
  }
}
```

### Organizations

#### GET /organizations/{tenantId}

List organizations accessible to the caller.

**Permissions:** `read`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "1",
      "tenantId": "1",
      "name": "Organization",
      "legalName": null,
      "orgCode": "ORG001",
      "industry": null,
      "status": "active",
      "createdBy": "...",
      "createdDate": "...",
      "updatedDate": "..."
    }
  ]
}
```

#### GET /organizations/{tenantId}/{id}

Show a single organization.

**Permissions:** `read`

**Response 200:** Single organization object.

**Response 404:** `organization_not_found`

#### POST /organizations

Create organization.

**Permissions:** `tenant.manage`

**Request:**
```json
{
  "name": "New Org",
  "orgCode": "NEW001",
  "industry": "Technology",
  "legalName": "New Org LLC",
  "logo": "https://..."
}
```

**Response 201:** Created organization object.

#### PATCH /organizations/{tenantId}/{id}

Update organization.

**Permissions:** `update`

**Request:**
```json
{
  "name": "Updated Name",
  "industry": "Healthcare"
}
```

**Response 200:** `{ "ok": true }`

#### POST /organizations/{tenantId}/{id}/archive

Archive organization (soft delete).

**Permissions:** `update`

**Response 200:** `{ "ok": true }`

### Departments

#### GET /departments/{tenantId}

List departments.

**Permissions:** `read`

**Response 200:** Array of department objects.

#### GET /departments/{tenantId}/{id}

Show department.

**Permissions:** `read`

**Response 200:** Department object.

#### POST /departments

Create department.

**Permissions:** `create`

**Request:**
```json
{
  "name": "Engineering",
  "description": "Software engineering",
  "parentId": null,
  "headId": null
}
```

**Response 201:** Created department object.

#### PATCH /departments/{tenantId}/{id}

Update department.

**Permissions:** `update`

**Request:** Partial department object.

**Response 200:** Updated department object.

#### POST /departments/{tenantId}/{id}/archive

Archive department.

**Permissions:** `update`

**Response 200:** `{ "ok": true }`

### People

#### GET /people/{tenantId}

List people.

**Permissions:** `read`

**Response 200:** Array of person objects.

#### GET /people/{tenantId}/search

Search people.

**Permissions:** `read`

**Query:** `?q=search_term`

**Response 200:** Array of person objects.

#### GET /people/{tenantId}/{id}

Show person.

**Permissions:** `read`

**Response 200:** Person object.

#### POST /people

Create person.

**Permissions:** `create`

**Request:** Person object.

**Response 201:** Created person object.

#### PATCH /people/{tenantId}/{id}

Update person.

**Permissions:** `update`

**Request:** Partial person object.

**Response 200:** Updated person object.

#### POST /people/{tenantId}/{id}/archive

Archive person.

**Permissions:** `update`

**Response 200:** `{ "ok": true }`

## Tenant Behavior

Every list, show, create, update, and archive operation is scoped to the authenticated tenant. The tenant ID is taken from the JWT `tenantId` claim. Frontend-supplied tenant IDs in the URL are verified against the token; mismatch returns `403`.

## Rate Limiting

- `/auth/login`: 10 requests per minute
- `/auth/refresh`: 20 requests per minute
