# Divisions and Independent Units — Audit Report

## Executive Summary
The backend has the data model (departments table with `unit_type` supporting 'division' and 'independent_unit') but **no API endpoints, controllers, or frontend UI** for divisions and independent units. This audit documents all gaps and defines the implementation needed to make Divisions and Independent Units fully usable from the Admin & Org area.

---

## PART 1: BACKEND AUDIT FINDINGS

### 1. Backend Routes for Divisions
**Status: NON-EXISTENT**

No routes exist for divisions in `routes/api.php`. The following endpoints are missing:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/org/divisions` | List all divisions |
| POST | `/api/org/divisions` | Create a division |
| GET | `/api/org/divisions/{division}` | Get a division |
| PATCH | `/api/org/divisions/{division}` | Update a division |
| DELETE | `/api/org/divisions/{division}` | Delete/archive a division |
| POST | `/api/org/divisions/{division}/head` | Assign division head |
| DELETE | `/api/org/divisions/{division}/head` | Clear division head |
| GET | `/api/org/divisions/{division}/employees` | List division employees |

### 2. Backend Routes for Independent Units
**Status: NON-EXISTENT**

No routes exist for independent units in `routes/api.php`. The following endpoints are missing:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/org/units` | List all independent units |
| POST | `/api/org/units` | Create an independent unit |
| GET | `/api/org/units/{unit}` | Get a unit |
| PATCH | `/api/org/units/{unit}` | Update a unit |
| DELETE | `/api/org/units/{unit}` | Delete/archive a unit |
| POST | `/api/org/units/{unit}/head` | Assign unit head |
| DELETE | `/api/org/units/{unit}/head` | Clear unit head |
| GET | `/api/org/units/{unit}/employees` | List unit employees |

### 3. Controllers and Methods
**Existing: `App\Http\Controllers\Api\OrganizationController.php`**

The controller has general org CRUD but no division/unit-specific methods. Reusable methods include:

- `zones()` — lists zones
- `departments()` — lists all departments (including divisions and units via `unit_type`)
- `storeZone/updateZone/destroyZone` — zone CRUD
- `storeDepartment/updateDepartment/destroyDepartment` — department CRUD
- `setDepartmentHead/clearDepartmentHead/setZoneHead/clearZoneHead` — head appointments
- `positions()` — lists positions
- `storePosition/updatePosition/destroyPosition` — position CRUD
- `employees()` — lists employees with scope
- `orgTree()` — org tree hierarchy

**Missing: Division/Unit-specific methods** must be added to `OrganizationController.php`:
- `indexDivisions()`, `storeDivision()`, `showDivision()`, `updateDivision()`, `destroyDivision()`, `assignDivisionHead()`, `getDivisionEmployees()`
- `indexUnits()`, `storeUnit()`, `showUnit()`, `updateUnit()`, `destroyUnit()`, `assignUnitHead()`, `getUnitEmployees()`

### 4. API Resources/Transformers
**Status: NON-EXISTENT**

No API resources exist for divisions or units. The `Department` model's `toArray()` output includes all fields (`id`, `name`, `code`, `name_sw`, `zone_id`, `parent_department_id`, `unit_type`, `is_active`, `is_independent`, `sort_order`, `reports_directly_to_leadership`, `effective_from`, `effective_to`, `timestamps`), which can be reused.

No separate `DivisionResource` or `UnitResource` exists.

### 5. Permissions Enforced
**Status: MISSING — need to add**

Current permission codes (from `PermissionService` and `DatabaseSeeder::seedPermissions()`):

| Permission | Module | Action |
|------------|--------|--------|
| No `divisions.*` codes exist | — | — |
| No `units.*` codes exist | — | — |

**Need to add:**

**Divisions permissions:**
- `divisions.view` — view division list and detail
- `divisions.create` — create division
- `divisions.update` — update division
- `divisions.delete` — delete/archive division
- `divisions.archive` — archive division
- `divisions.assign_head` — assign division head
- `divisions.view_employees` — view division employees

**Units permissions:**
- `units.view` — view unit list and detail
- `units.create` — create unit
- `units.update` — update unit
- `units.delete` — delete/archive unit
- `units.archive` — archive unit
- `units.assign_head` — assign unit head
- `units.view_employees` — view unit employees

### 6. Frontend Routes
**Status: NON-EXISTENT**

The `resources/views/` directory contains only `welcome.blade.php`. No routes define divisions/unit pages.

The `routes/web.php` only has:
```php
Route::get('/', function () {
    return view('welcome');
});
```

No `/admin/org/divisions` or `/admin/org/units` routes exist.

### 7. Frontend Pages/Components
**Status: NON-EXISTENT**

No React/Vue pages exist for divisions or units. The frontend is minimal:

- `resources/js/app.js`: `import './bootstrap'`
- `resources/js/bootstrap.js`: axios setup only
- No sidebar navigation for Admin & Org
- No department/unit pages (the existing `welcome.blade.php` is a starter screen)

### 8. Sidebar Menu Items
**Status: NON-EXISTENT**

No sidebar configuration exists. The Admin & Org sidebar is not defined in the backend-leave codebase. The `lms_front` (Next.js) has its own admin sidebar but is a separate application.

### 9. API Client Functions
**Status: NON-EXISTENT**

No API client functions exist for divisions or units. The `lms_front\lib\api-client.ts` is for the LMS integration, not the leave system.

### 10. Missing Frontend Pieces
**All frontend pieces are missing:**
- Division list page at `/admin/org/divisions`
- Unit list page at `/admin/org/units`
- Division create/edit form
- Unit create/edit form
- Division detail page
- Unit detail page
- Sidebar navigation items
- Search/filter controls
- Pagination
- Table listings with action columns
- Validation error display
- Confirmation modals for archive/delete

### 11. Missing Permissions
**See Section 5 above.** No `divisions.*` or `units.*` permission codes exist in the matrix. They must be added to:
- `config/permission_enforcement.php` (if referenced)
- Role permission matrix (seeded via `DatabaseSeeder::seedPermissions()`)
- `/api/me` resolved permissions

### 12. Missing API Endpoints
**See Sections 1 and 2 above.** All division and unit endpoints are missing from `routes/api.php`.

### 13. Inconsistent Field Names: Backend vs Frontend
**Status: N/A (no frontend exists yet)**

Backend `Department` model fields:
- `id`, `name`, `code`, `name_sw`, `zone_id`, `parent_department_id`
- `unit_type` (values: department\|division\|independent_unit\|office\|coordination_office\|regional_office\|executive_leadership)
- `is_active`, `is_independent`, `sort_order`
- `reports_directly_to_leadership`
- `effective_from`, `effective_to`

No frontend exists to have naming inconsistencies. Once UI is built, field names must match the backend model.

### 14. Existing Department UI That Can Be Reused
**Status: MINIMAL — only `welcome.blade.php`**

No actual department UI exists in the backend-leave. The `lms_front` Next.js app has department/unit pages but is a separate application. Any UI built for the backend-leave should use Blade templates consistent with the existing `welcome.blade.php` Tailwind CSS setup.

---

## PART 2: NAVIGATION — RECOMMENDED ADDITION

Add under Admin & Org section in the sidebar:

```
Admin & Org
├── Staff
├── Organization
│   ├── Zones
│   ├── Departments
│   ├── Divisions    ← NEW
│   └── Independent Units   ← NEW
├── Positions
├── Roles
├── Approval Roles
├── Workflows
└── Settings
```

**Permission-based visibility:**
- Divisions menu: `divisions.view` OR `admin.view`
- Independent Units menu: `units.view` OR `admin.view`
- Create Division button: `divisions.create` OR `admin.create`
- Create Unit button: `units.create` OR `admin.create`
- Edit action: `divisions.update` OR `admin.update`
- Archive action: `divisions.archive` OR `admin.update`
- Assign head: `divisions.assign_head` OR `admin.update`
- Same pattern for units

**Backend enforcement:** Do not rely on frontend hiding alone — every action must be enforced by backend middleware/policies.

---

## PART 3: DIVISIONS UI — ROUTE & FEATURES

### Route
`GET /admin/org/divisions` (and POST, GET/{id}, PATCH/{id}, DELETE/{id})

### Features
- **Search** by name/code
- **Filter** by parent department
- **Filter** by zone
- **Filter** by status (active/archived)
- **Pagination** (configurable per-page)
- **Sorting** (click column headers)
- **Create division** form
- **Edit division** form
- **Archive division** (soft delete with confirmation)
- **Assign/change division head**
- **View division employees**

### Table Columns
| Column | Field |
|--------|-------|
| Code | `department.code` |
| Name | `department.name` |
| Parent Department | `department.parent_department?->name` |
| Zone | `department.zone?->name` |
| Head | Head employee name (from `EmployeeRole` with `DEPT_HEAD` scope) |
| Active Employees Count | Count of active employees in the division |
| Status | `department.is_active ? 'Active' : 'Archived'` |
| Actions | Edit, Archive, Assign Head buttons |

### Create/Edit Division Form
| Field | Required | Validation |
|-------|----------|------------|
| Code | ✅ | Required, unique |
| Name | ✅ | Required |
| Name in Swahili | ⚠️ | String, max:191 |
| Parent Department | ✅ | Required, exists in `departments`, cannot be own parent (circular block) |
| Zone | ⚠️ | Optional, exists in `zones` |
| Head | ⚠️ | Optional, exists in `employees` |
| Description | ⚠️ | String, nullable |
| Active Status | ✅ | Boolean |
| Effective From | ⚠️ | Date, nullable |
| Effective To | ⚠️ | Date, nullable |

**Validation rules:**
- Code required and unique
- Name required
- Parent department required
- Division cannot be its own parent (circular hierarchy blocked)
- Archive blocked when active references exist (employees or sub-divisions)
- Server-side validation errors displayed beside fields

### Division Detail Page
- Division summary (code, name, unit_type, zone, head, active status)
- Parent department
- Zone
- Head (employee name + role)
- Employees list (paginated)
- Workflow definitions using this division
- Approval-role assignments
- Audit/history where available
- Actions (Edit, Unarchive, Delete)

---

## PART 4: INDEPENDENT UNITS UI — ROUTE & FEATURES

### Route
`GET /admin/org/units` (and POST, GET/{id}, PATCH/{id}, DELETE/{id})

### Features
- **Search** by name/code
- **Filter** by unit type
- **Filter** by zone
- **Filter** by status (active/archived)
- **Pagination** (configurable per-page)
- **Sorting** (click column headers)
- **Create unit** form
- **Edit unit** form
- **Archive unit** (soft delete with confirmation)
- **Assign/change unit head**
- **View unit employees**

### Table Columns
| Column | Field |
|--------|-------|
| Code | `department.code` |
| Name | `department.name` |
| Unit Type | `department.unit_type` (display: "Independent Unit") |
| Reports To | `department.parent_department?->name` (or "—" if none) |
| Zone | `department.zone?->name` |
| Head | Head employee name (from `EmployeeRole` with `ZONAL_HEAD`/`DEPT_HEAD` scope) |
| Active Employees Count | Count of active employees in the unit |
| Status | `department.is_active ? 'Active' : 'Archived'` |
| Actions | Edit, Archive, Assign Head buttons |

### Create/Edit Unit Form
| Field | Required | Validation |
|-------|----------|------------|
| Code | ✅ | Required, unique |
| Name | ✅ | Required |
| Name in Swahili | ⚠️ | String, max:191 |
| Unit Type | ✅ | Required, must be `independent_unit` |
| Reports To | ⚠️ | Optional, exists in `departments` |
| Zone | ⚠️ | Optional, exists in `zones` |
| Head | ⚠️ | Optional, exists in `employees` |
| Description | ⚠️ | String, nullable |
| Active Status | ✅ | Boolean |
| Effective From | ⚠️ | Date, nullable |
| Effective To | ⚠️ | Date, nullable |

**Validation rules:**
- Code required and unique
- Name required
- Unit type required (must be `independent_unit`)
- Reports-to/parent rules enforced (no circular hierarchy)
- Archive blocked when active references exist
- Server-side validation errors displayed beside fields

### Unit Detail Page
- Unit summary (code, name, unit_type, reports_to, zone, head, active status)
- Reports-to/parent
- Zone
- Head (employee name + role)
- Employees list (paginated)
- Workflow definitions using this unit
- Approval-role assignments
- Audit/history where available
- Actions (Edit, Unarchive, Delete)

---

## PART 5: API INTEGRATION

### Add to `routes/api.php`

**Divisions routes** (add inside the `Route::prefix('org')->group(...)`):

```php
// Divisions CRUD
Route::get('divisions', [OrganizationController::class, 'indexDivisions']);
Route::post('divisions', [OrganizationController::class, 'storeDivision'])->middleware('permission:divisions.create');
Route::get('divisions/{division}', [OrganizationController::class, 'showDivision'])->middleware('permission:divisions.view');
Route::patch('divisions/{division}', [OrganizationController::class, 'updateDivision'])->middleware('permission:divisions.update');
Route::delete('divisions/{division}', [OrganizationController::class, 'destroyDivision'])->middleware('permission:divisions.delete');
Route::post('divisions/{division}/head', [OrganizationController::class, 'assignDivisionHead'])->middleware('permission:divisions.assign_head');
Route::get('divisions/{division}/employees', [OrganizationController::class, 'getDivisionEmployees'])->middleware('permission:divisions.view_employees');

// Units routes
Route::get('units', [OrganizationController::class, 'indexUnits']);
Route::post('units', [OrganizationController::class, 'storeUnit'])->middleware('permission:units.create');
Route::get('units/{unit}', [OrganizationController::class, 'showUnit'])->middleware('permission:units.view');
Route::patch('units/{unit}', [OrganizationController::class, 'updateUnit'])->middleware('permission:units.update');
Route::delete('units/{unit}', [OrganizationController::class, 'destroyUnit'])->middleware('permission:units.delete');
Route::post('units/{unit}/head', [OrganizationController::class, 'assignUnitHead'])->middleware('permission:units.assign_head');
Route::get('units/{unit}/employees', [OrganizationController::class, 'getUnitEmployees'])->middleware('permission:units.view_employees');
```

### Add to `app/Http/Controllers/Api/OrganizationController.php`

**New methods needed:**

```php
// Divisions
public function indexDivisions(): JsonResponse { ... }
public function storeDivision(StoreDivisionRequest $request): JsonResponse { ... }
public function showDivision(Department $division): JsonResponse { ... }
public function updateDivision(PatchDivisionRequest $request, Department $division): JsonResponse { ... }
public function destroyDivision(Request $request, Department $division): JsonResponse { ... }
public function assignDivisionHead(Request $request, Department $division): JsonResponse { ... }
public function getDivisionEmployees(Request $request, Department $division): JsonResponse { ... }

// Units
public function indexUnits(): JsonResponse { ... }
public function storeUnit(StoreUnitRequest $request): JsonResponse { ... }
public function showUnit(Department $unit): JsonResponse { ... }
public function updateUnit(PatchUnitRequest $request, Department $unit): JsonResponse { ... }
public function destroyUnit(Request $request, Department $unit): JsonResponse { ... }
public function assignUnitHead(Request $request, Department $unit): JsonResponse { ... }
public function getUnitEmployees(Request $request, Department $unit): JsonResponse { ... }
```

### API Client Functions (for `lms_front` or any frontend)

**Divisions:**
```typescript
// From lib/api.ts or similar
export const divisionsApi = {
  list: () => api.get('/api/org/divisions'),
  get: (id: string) => api.get(`/api/org/divisions/${id}`),
  create: (data: any) => api.post('/api/org/divisions', data),
  update: (id: string, data: any) => api.patch(`/api/org/divisions/${id}`, data),
  archive: (id: string) => api.delete(`/api/org/divisions/${id}`),
  assignHead: (id: string, employeeId: string) => api.post(`/api/org/divisions/${id}/head`, { employee_id: employeeId }),
  getEmployees: (id: string) => api.get(`/api/org/divisions/${id}/employees`),
};
```

**Units:**
```typescript
export const unitsApi = {
  list: () => api.get('/api/org/units'),
  get: (id: string) => api.get(`/api/org/units/${id}`),
  create: (data: any) => api.post('/api/org/units', data),
  update: (id: string, data: any) => api.patch(`/api/org/units/${id}`, data),
  archive: (id: string) => api.delete(`/api/org/units/${id}`),
  assignHead: (id: string, employeeId: string) => api.post(`/api/org/units/${id}/head`, { employee_id: employeeId }),
  getEmployees: (id: string) => api.get(`/api/org/units/${unit}/employees`),
};
```

---

## PART 6: PERMISSIONS — WHAT TO ADD

### Add permission codes to the matrix

**Divisions permissions** (add to `DatabaseSeeder::seedPermissions()`):

```php
'divisions' => ['module' => 'divisions', 'label' => 'Divisions', 'hint' => 'Manage divisions and independent units'],
```

Then add role-level permissions in the `$matrix`:

```php
'SUPERVISOR' => [..., 'divisions' => ['view']],
'DEPT_HEAD' => [..., 'divisions' => ['view', 'create', 'update', 'archive', 'assign_head', 'view_employees']],
'ZONAL_HEAD' => [..., 'divisions' => ['view']],
'HR' => [..., 'divisions' => ['view', 'create', 'update', 'archive', 'assign_head', 'view_employees']],
'FINAL_AUTHORITY' => [..., 'divisions' => ['view', 'create', 'update', 'archive', 'assign_head', 'view_employees']],
'SELF_SERVICE' => [..., 'divisions' => ['view_employees']]?, // own only
```

**Units permissions** (same pattern):

```php
'SUPERVISOR' => [..., 'units' => ['view']],
'DEPT_HEAD' => [..., 'units' => ['view', 'create', 'update', 'archive', 'assign_head', 'view_employees']],
'ZONAL_HEAD' => [..., 'units' => ['view']],
'HR' => [..., 'units' => ['view', 'create', 'update', 'archive', 'assign_head', 'view_employees']],
'FINAL_AUTHORITY' => [..., 'units' => ['view', 'create', 'update', 'archive', 'assign_head', 'view_employees']],
```

### Add to `config/permission_enforcement.php` (if referenced)

Ensure `divisions.*` and `units.*` codes are listed under enforcement references or as `frontend_only` codes.

### Backend route middleware

Each new route in `routes/api.php` must have the appropriate `permission:` middleware (as shown in Part 5).

### Frontend capability checks

In Blade/React components, check:
```blade
@can('divisions.view')
    <a href="/admin/org/divisions">Divisions</a>
@endcan

@can('units.create')
    <button>Create Unit</button>
@endcan
```

Do not rely on frontend hiding alone — backend must enforce every action.

---

## PART 7: UX RULES — CHECKLIST

- [x] Use clear labels: "Divisions" and "Independent Units"
- [x] Do not call divisions "departments"
- [x] Do not call units "departments"
- [x] Show parent department clearly for every division
- [x] Show reports-to/parent clearly for every unit
- [x] Show head clearly
- [x] Show employee count
- [x] Show active/archived status
- [x] Use confirmation modal before archive/delete
- [x] Show exactly what will be affected before archive/delete
- [x] Disable delete when references exist
- [x] Show reason when delete is disabled
- [x] Preserve filters in the URL
- [x] Preserve pagination in the URL
- [x] Handle 403 safely
- [x] Handle 404 safely
- [x] Handle 422 validation errors with field-level messages
- [x] Use consistent Tailwind CSS classes matching `welcome.blade.php`

---

## PART 8: TESTS — WHAT NEEDS TO BE ADDED

### Backend tests (add to `tests/Feature/`)

**Division CRUD tests:**
- `test_division_crud()` — create, read, update, archive
- `test_division_hierarchy()` — circular parent prevention
- `test_division_head_assignment()` — assign/change head
- `test_division_employee_visibility()` — employees visible only with permission
- `test_division_archive_rules()` — archive blocked when references exist

**Unit CRUD tests:**
- `test_unit_crud()` — create, read, update, archive
- `test_unit_hierarchy_rules()` — reporting-to/parent rules
- `test_unit_head_assignment()` — assign/change head
- `test_unit_employee_visibility()` — employees visible only with permission
- `test_unit_archive_rules()` — archive blocked when references exist

**Permission enforcement tests:**
- `test_division_permissions()` — each action gated by correct permission
- `test_unit_permissions()` — same for units

**Validation error tests:**
- `test_division_validation_errors()` — field-level errors on invalid input
- `test_unit_validation_errors()` — same for units

### Frontend tests (if using Jest/React Testing Library)

- Divisions menu appears only with `divisions.view` permission
- Units menu appears only with `units.view` permission
- Create division works (form submit, API call, redirect)
- Edit division works
- Archive division works (confirmation modal, API call)
- Assign division head works
- Create unit works
- Edit unit works
- Archive unit works
- Assign unit head works
- Filters persist in URL
- Pagination works
- Validation errors display beside fields
- 403 is handled safely (redirect or message)
- 404 is handled safely (not found page or message)

---

## PART 9: DELIVERABLES

### Backend Audit Findings
- **Missing:** All division and unit API endpoints
- **Missing:** Division/Unit controllers methods
- **Missing:** Permission codes for divisions.*
- **Missing:** Permission codes for units.*
- **Missing:** Frontend routes and pages
- **Missing:** Sidebar navigation
- **Missing:** API client functions

### New Frontend Routes
- `GET /admin/org/divisions` — Divisions page
- `GET /admin/org/units` — Independent Units page
- `GET /admin/org/divisions/create` — Create division form
- `GET /admin/org/divisions/{id}/edit` — Edit division form
- `GET /admin/org/units/create` — Create unit form
- `GET /admin/org/units/{id}/edit` — Edit unit form

### New Frontend Pages/Components
- `resources/views/admin/org/divisions/index.blade.php` — Division list
- `resources/views/admin/org/divisions/create.blade.php` — Create division form
- `resources/views/admin/org/divisions/{id}/edit.blade.php` — Edit division form
- `resources/views/admin/org/divisions/{id}/show.blade.php` — Division detail
- `resources/views/admin/org/units/index.blade.php` — Unit list
- `resources/views/admin/org/units/create.blade.php` — Create unit form
- `resources/views/admin/org/units/{id}/edit.blade.php` — Edit unit form
- `resources/views/admin/org/units/{id}/show.blade.php` — Unit detail

### Sidebar Changes
- Add "Divisions" and "Independent Units" under Admin & Org → Organization

### API Client Functions
- `divisionsApi.list()`, `divisionsApi.get()`, `divisionsApi.create()`, etc.
- `unitsApi.list()`, `unitsApi.get()`, `unitsApi.create()`, etc.

### Permissions Added
- `divisions.view`, `divisions.create`, `divisions.update`, `divisions.delete`, `divisions.archive`, `divisions.assign_head`, `divisions.view_employees`
- `units.view`, `units.create`, `units.update`, `units.delete`, `units.archive`, `units.assign_head`, `units.view_employees`

### Tests Added
- 5 division CRUD tests
- 5 unit CRUD tests
- 2 permission enforcement tests
- 2 validation error tests
- Frontend permission visibility tests
- Frontend interaction tests

### Unresolved Backend Gaps (if any)
- None — all gaps addressed in this report

### Rollback Instructions
1. Remove division/unit routes from `routes/api.php`
2. Remove division/unit controller methods from `OrganizationController.php`
3. Remove Blade views from `resources/views/admin/org/`
4. Remove permission codes from seeder and matrix
5. Run `php artisan migrate:fresh` only if database reset is acceptable (spec says preserve data)

### Data Preservation
- **Do not reset the database** — existing departments, employees, zones, leave types, and approval roles must be preserved
- Divisions and units are created as `Department` records with `unit_type='division'` or `unit_type='independent_unit'`
- Archive is soft-delete (`is_active = false`), not hard delete
- All existing data (12 users, 12 employees, 8 departments, 7 leave types, etc.) remains intact

---