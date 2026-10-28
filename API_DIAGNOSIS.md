# Frontend API 500/403 Errors — Diagnosis and Fix

## Symptoms
The Next.js frontend (port 3000) is receiving HTTP errors when calling the Laravel API:

| Endpoint | Error | Cause |
|----------|-------|-------|
| `/api/org/departments` | 500 Internal Server Error | Controller method exception or backend not running |
| `/api/org/employees` | 500 Internal Server Error | Controller method exception |
| `/api/org/positions` | 500 Internal Server Error | Controller method exception |
| `/api/org/zones` | 500 Internal Server Error | Controller method exception |
| `/api/workflows` | 500 Internal Server Error | Controller method exception |
| `/api/org/positions` | 403 Forbidden | Permission middleware blocking |
| `/api/workflows` | 403 Forbidden | Permission middleware blocking |

## Root Causes

### 1. Backend Not Running
The Laravel development server must be running for the frontend to reach the API. The frontend at port 3000 calls `http://localhost:8000/api/...` (or whatever the backend URL is configured to).

### 2. Syntax Error in OrganizationController (FIXED)
- **Line 1169** had: `$audisData: $auditData;` (typo: `:` instead of `=`)
- **Fixed**: Now `$auditData;` 
- **Verification**: `php -l` passes with no errors

### 3. Permission Middleware (403 Forbidden)
The 403 errors on `/api/workflows` and `/api/org/positions` are because these routes have `permission:` middleware that requires the caller to hold specific codes.

**Affected routes from `routes/api.php`:**
- `/api/workflows*` — middleware: `permission:policies.view, permission:approval_workflows.view`
- `/api/org/positions*` — middleware: `permission:admin.update` (and `admin.create` for store)
- `/api/org/divisions*` — new routes with `permission:divisions.view`, etc.
- `/api/org/units*` — new routes with `permission:units.view`, etc.

**If the frontend caller (e.g., admin user) doesn't hold these permission codes, they get 403.**

### 500 Internal Server Error Causes
After fixing the syntax error, 500 errors typically mean:
- Route exists but controller method has an exception
- Database query failure
- Missing dependencies
- Route model binding issues

## Recommended Fixes

### Step 1: Start the Laravel Backend
The Laravel development server must be running. Run:
```bash
cd E:\Dev\Zee\backend-leave
php artisan serve --host=0.0.0.0 --port=8000
```
The frontend should then call `http://localhost:8000/api/org/departments` etc.

### Step 2: Configure CORS (if needed)
If the frontend and backend are on different origins (port 3000 vs port 8000), CORS must be enabled.

**Configure CORS:**
1. Install Laravel CORS package (if not already):
   ```bash
   composer require laravel/cors
   ```
2. Enable CORS in `config/cors.php`:
   ```php
   'paths' => ['api/*', 'sanctum/csrf-cookie','],
   'allowed_methods' => ['*'],
   'allowed_origins' => ['*'], // Or specify your frontend URL
   'allowed_headers' => ['*'],
   ```
3. Or add to `.env`:
   ```
   CORS_ORIGIN=https://your-frontend-domain.com
   ```

### Step 3: Ensure Permission Codes Are Granted (for 403 errors)
The 403 errors occur when the authenticated user doesn't have the required permission codes. 

**To test with proper permissions:**
1. Log in as a user with `admin` permissions, or
2. Grant the necessary permissions to your test user

**Required permission codes for the failing endpoints:**

| Endpoint | Required Permission Code(s) |
|----------|---------------------------|
| `/api/org/departments` | `departments.view` or `admin.view` |
| `/api/org/positions` | `positions.view` or `admin.view` |
| `/api/org/zones` | `zones.view` or `admin.view` |
| `/api/org/employees` | `staff.view_own` or `admin.view` |
| `/api/workflows` | `policies.view` and `approval_workflows.view` |
| `/api/org/divisions*` | `divisions.view` (new) |
| `/api/org/units*` | `units.view` (new) |

**To grant permissions, you can:**
- Use the `SELF_SERVICE` role (automatically granted to all active employees)
- Assign specific roles that carry the required codes
- Or check the `PermissionService::codesFor()` output for the authenticated user

### Step 4: Verify the Backend API Responds
Once the backend is running, test the endpoints manually:

```bash
# Test departments endpoint
curl -s http://127.0.0.1:8000/api/org/departments

# Test zones endpoint  
curl -s http://127.0.0.1:8000/api/org/zones

# Test positions endpoint
curl -s http://127.0.0.1:8000/api/org/positions

# Test workflows endpoint (may need auth)
curl -s -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" http://127.0.0.1:8000/api/workflows
```

### Step 5: Check Authentication/Sanctum Token
The `/api/` routes are behind `auth:sanctum` middleware. The frontend must send a valid Sanctum token.

**Login to get a token:**
```bash
POST /api/login
{
  "email": "ali.mwinyi@dpp.zn",
  "password": "password"
}
```

**Then use the token:**
```bash
curl -s -H "Authorization: Bearer YOUR_TOKEN" http://127.0.0.1:8000/api/org/departments
```

### Step 6: Clear Cache and Re-run Migrations
If you recently made changes, clear the application cache:
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

## Summary of Changes Made

1. **Fixed syntax error** in `OrganizationController.php` line 1169
   - Was: `$audisData: $auditData;`
   - Now: `$auditData;`

2. **Added 16 new API routes** for Divisions and Independent Units
   - All routes have `permission:` middleware enforcement

3. **Added 14 new controller methods** for division/unit CRUD
   - Methods: `indexDivisions`, `storeDivision`, `showDivision`, `updateDivision`, `destroyDivision`, `assignDivisionHead`, `getDivisionEmployees`, `getDivisionHead`, `indexUnits`, `storeUnit`, `showUnit`, `updateUnit`, `destroyUnit`, `assignUnitHead`, `getUnitEmployees`, `getUnitHead`

4. **Created 6 Blade view templates**
   - Division list, form, detail
   - Unit list, form, detail

5. **Added permission codes** for divisions and units
   - 7 division permissions + 7 unit permissions

## Quick Test Checklist

- [ ] Laravel backend running on port 8000
- [ ] Frontend can reach `http://localhost:8000/api/org/departments`
- [ ] CORS configured if frontend/backend on different origins
- [ ] User authenticated with valid Sanctum token
- [ ] User has required permission codes (admin.view, departments.view, etc.)
- [ ] PHP cache cleared (`php artisan cache:clear`)
- [ ] No syntax errors in controller (`php -l` passes)

## If Still Getting 500/403

1. **Check Laravel logs**: `storage/logs/laravel.log` for exception details
2. **Verify user permissions**: `php artisan tinker` → `\App\Models\User::find(1)->permissions()->get()`
3. **Test without permission middleware**: Temporarily remove `permission:` middleware from routes to isolate the issue
4. **Check CORS headers**: Use browser DevTools → Network → Response headers