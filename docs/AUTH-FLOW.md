# HP Enterprise Brain — Authentication Flow

## Overview

The frontend uses a token-based authentication model. There is no session cookie. The backend returns a short-lived `accessToken` and a longer-lived `refreshToken`, both stored in `localStorage`.

---

## 1. Login

The frontend sends a `POST /auth/login` request with:
- `email` — the user's email
- `password` — the user's password

No `tenantId` is sent. The backend resolves the organization from the ERP `tbluser.sub_institute_id` column.

## 2. How Email and Password Are Validated

The flow is handled entirely by the Laravel backend:

1. Frontend sends `POST /api/v1/auth/login` with `email` and `password`.
2. Backend looks up the user in `hp_erp.tbluser` with `status = 1` and `deleted_at IS NULL`.
3. Password is verified against `tbluser.password` (bcrypt/argon), legacy direct-match, or `tbluser.plain_password`.
4. On success, the backend:
   - Resolves `sub_institute_id` as the tenant
   - Resolves `user_profile_id` against `tbluserprofilemaster` for the role
   - Loads organization name/logo from `institute_detail` / `org_details`
   - Issues `accessToken` (15 min) and `refreshToken` (7 days)
5. On failure, the backend returns `invalid_credentials`.

## 3. Where the JWT / Access Token Is Stored

Tokens are stored in the browser's **`localStorage`**:

| Key | Value |
|-----|-------|
| `accessToken` | The active bearer token. Sent in the `Authorization: Bearer ...` header for every API call. |
| `refreshToken` | Used by the API client to silently refresh the access token when it expires. |
| `selectedOrgId` | The authenticated organization's `sub_institute_id`. |
| `signedOut` | Set to `"1"` on explicit sign-out. |

## 4. How App.tsx Decides Whether to Show Login or Dashboard

```
1. initialAuthState() reads localStorage.getItem('accessToken')
2. If a token exists → authenticated = true → render the full dashboard.
3. If no token exists → authenticated = false → render <Login onLogin={...} />.
```

When the user signs in successfully:

1. `Login.tsx` stores the tokens in `localStorage`.
2. `Login.tsx` calls `onLogin()` with the organization from the login response.
3. `App.tsx` sets the selected organization and navigates to the Organization Intelligence Home.

When the user signs out:

1. `logout()` calls `POST /api/v1/auth/logout` to revoke the refresh token.
2. Removes `accessToken`, `refreshToken`, and `selectedOrgId` from `localStorage`.
3. Sets `authenticated = false`.
4. The component re-renders and the login screen appears.

If the backend returns `401 Unauthorized` on any API call, the `onSessionExpired` callback fires and forces `authenticated = false`, returning the user to the login screen.

## 5. Organization Selection

There is no organization picker for normal users. The login response contains the user's organization, and it is selected automatically. Only a platform super-admin may switch organizations by addressing a different tenant in the URL.

## 6. Placeholder Buttons: Google and Microsoft

The login screen includes "Sign in with Google" and "Sign in with Microsoft" buttons, but:
- **There is no backend OAuth route yet.**
- Clicking either button shows an informational notice explaining what is needed.
- **No fake authentication is performed.**

## 7. File Reference

| File | Role |
|------|------|
| `web/src/components/auth/Login.tsx` | Login UI and form logic. |
| `web/src/App.tsx` | Auth gate: decides between Login and Dashboard. |
| `web/src/api/client.ts` | Axios instance that attaches the `Authorization` header and handles token refresh. |
| `web/src/utils/tenant.ts` | Helpers to read/write the current tenant and selected organization from `localStorage`. |
| `web/src/theme.css` | All login screen styles. |
