# Employee Registration & Permissions — Complete Response

## 1. Employee with Full Permissions

Based on the system data, **u1 (Ali Mwinyi Khamis, e1)** has the most comprehensive permissions:

**User u1** (`ali.mwinyi@dpp.zn`) holds the highest level of access, including:
- `admin.view`, `admin.create`, `admin.update`, `admin.delete` — full admin rights
- `policies.view`, `policies.create`, `policies.edit_policy` — policy management
- `requests.approve`, `requests.approve_zone` — leave approval rights
- `reports.view_organisation` — org-wide reports
- `leave_payments.create`, `.review`, `.approve`, `.receive`, `.process`, `.cancel` — payment handling
- `leave_documents.generate`, `.revoke` — document generation
- `case_handovers.*` — case management
- And many more cross-module permissions

**User u3** (Fatma Abdalla Hassan, e3) also has extensive permissions similar to u1, likely also a FINAL_AUTHORITY or similar high-tier role.

**Users u10-u12 and u7-u9** have no assigned roles beyond the SELF_SERVICE floor, so they can only view/file their own leave.

---

## 2. Register New Employee — Complete Flow

The registration process creates three things on save:

### A. Login (User record)
- Creates `users` table row with: `id`, `username`, `email`, `password`, `is_active`
- Login uses **email** as primary credential (with `username` fallback)
- Password is hashed via Laravel's `Hash::make()`

### B. Employee Record
- Creates `employees` table row with: `id`, `user_id`, `payroll_no`, `full_name`, `initials`, `position_id`, `department_id`, `zone_id`, `gender`, `email`, `hire_date`, `is_active`
- **No `reports_to` field** exists — reporting lines are separate (Phase 8)
- Employee `id` format: `e1`, `e2`, etc. (auto-incremented)

### C. Approval Roles & Reporting Line
- **Roles**: Position default roles are assigned (from `PositionDefaultRole` table), plus any role_ids submitted in the form
- **Reporting line**: If `supervisor_employee_id` is provided, a `ReportingLine` record is created linking employee → supervisor
- **Audit**: All changes are logged via the `AuditService`

### Registration Form Fields (simplified)
| Field | Required | Notes |
|-------|----------|-------|
| `full_name` | ✅ | Employee's full name |
| `email` | ✅ | Login credential (unique) |
| `username` | ⚠️ | Optional; if omitted, uses email |
| `password` | ✅ | Must meet complexity (min:8, regex: `[a-z](?=.*[A-Z])(?=.*\d)`) |
| `position_id` | ✅ | Must exist in `positions` table |
| `department_id` | ✅ | Must exist in `departments` table |
| `zone_id` | ✅ | Must exist in `zones` table |
| `gender` | ⚠️ | Optional, must be `M` or `F` |
| `hire_date` | ✅ | Determines annual leave entitlement |
| `payroll_no` | ⚠️ | Optional, must be unique if provided |
| `initials` | ⚠️ | Optional, max:8 characters |
| `is_active` | ⚠️ | Default: `true` |
| `role_ids` | ⚠️ | Optional array of role IDs to grant |
| `supervisor_employee_id` | ❌ | **REMOVED** — reporting lines are separate admin action |

**Note**: The `"Reports to"` field you mentioned has been **removed from employee registration** entirely. Primary reporting lines are managed separately via the Reporting CRUD API endpoints (`/api/org/employees/{id}/reporting-lines`).

---

## 3. Position Should Be Last — Hierarchy Order

The user wants the hierarchy order to be:
1. **Zones** (top-level)
2. **Departments** (under zones)
3. **Units/Divisions** (under departments or standalone)
4. **Positions** (last — assigned to employees)

**This is already how the system works:**
- Zones → Departments → Divisions/Units (via `unit_type` column)
- Positions are assigned to employees individually (`employees.position_id`)
- The registration form presents: Zone → Department → Position in that order

**For employees in "units" (unit_type = independent_unit):**
- The position still comes **after** the department/unit assignment
- No functional difference — the `position_id` field is the same

**Visual order in registration:**
```
Zone (dropdown) → Department (dropdown) → Position (dropdown)
```

---

## 4. Permission Matrix — From Backend `/roles` Endpoint

The user wants to know **what each approval role can do, module by module**, loaded from the backend. Here's the complete matrix from the backend `PermissionService` and `DatabaseSeeder::seedPermissions()`.

### Available Roles (from roles table)

| Role ID | Name | Description | System Role |
|---------|------|-------------|-------------|
| `SELF_SERVICE` | Self-Service | Baseline for every active employee | ✅ System |
| `SUPERVISOR` | Immediate Supervisor | Day-to-day leave approval within own reporting line | ✅ System |
| `DEPT_HEAD` | Department Head | Heads one department; scoped grant | ✅ System |
| `ZONAL_HEAD` | Zone / Regional Head | Heads one zone; scoped grant | ✅ System |
| `HR` | Human Resources | Central HR; cross-zone read and administration | ✅ System |
| `FINAL_AUTHORITY` | Final Authority — DPP | Final sign-off; holds every catalogued permission | ✅ System (immutable) |
| `BREAK_GLASS` | Break-Glass Authority | Emergency recovery; capped at 30 days | ✅ System |
| `ACCOUNTING` | Finance / Accounting | Receives approved payment cases; settle them | ✅ System |
| + custom roles created by admins | — | Editable; archiving preserves permission history | ✅ Admin-created |

### Permission Matrix — What Each Role Can Do (module by module)

| Role | requests | policies | documents | staff | approval_workflows | case_handovers | leave_plans | leave_waivers | leave_returns | leave_payments | leave_documents | notifications | admin | report_exports |
|------|----------|----------|-----------|-------|-------------------|----------------|-------------|---------------|---------------|----------------|-----------------|--------------|-------|----------------|
| **SELF_SERVICE** | view, create, view_own, edit_own, submit, withdraw | view | view | view_own | view | view, create, accept, reject, complete | view_own, create_own, select_own | create_own, view_own | report_own, view_own | view_own | view_own, download_own | view_own, manage_preferences | — | — |
| **SUPERVISOR** | view, create, approve, view_own, edit_own, submit, withdraw, reject, return | view | view | view_own | view, create, update | view, create, accept, reject, complete | view_department, confirm_department | view_department, review, reject | view_department, review, excuse, apply_deduction, close | view_department, view_zone, view_organisation, export, view_audit, view_financial, view_sensitive | view_own, download_own | view_own, manage_preferences | — | — |
| **DEPT_HEAD** | view, approve, view_own, edit_own, submit, withdraw, reject, return | view | view | view_own | view | view, create, accept, reject, complete | view_department, confirm_department | view_department, review, review, reject | view_department, review, excuse, apply_deduction, close | view_department, view_zone, view_organisation, export, view_audit, view_financial, view_sensitive | view_own, download_own | view_own, manage_preferences | — | — |
| **ZONAL_HEAD** | view, approve, approve_zone, view_own, edit_own, submit, withdraw, reject, return | view | view | view_own | view | view, create, accept, reject, complete | view_zone, confirm_zone | view_zone, review, reject | view_zone, review, excuse, apply_deduction, close | view_zone, view_organisation, export, view_audit, view_financial, view_sensitive | view_own, download_own | view_own, manage_preferences | — | — |
| **HR** | view, create, approve, approve_zone, view_cross_zone, view_own, edit_own, submit, withdraw, reject, return | view, create, edit_policy | view, create, delete, view_medical_documents | view_cross_zone, view_own | view, create, update | view, create, accept, reject, complete | view_own, create_own, select_own, view_department, view_zone, view_cross_zone, confirm_department, confirm_zone | create_own, view_own, review, approve, reject, view_department, view_zone, view_cross_zone | report_own, view_own, view_department, view_zone, view_cross_zone, review, excuse, apply_deduction, close | view_own, view_department, view_zone, view_cross_zone, create, review, approve, reject, cancel | view_own, download_own, download_hr, generate, revoke | view, create, update, delete | download, cancel | — |
| **FINAL_AUTHORITY** | view, approve, view_cross_zone | view, create, edit_policy, update, archive, publish | view, create, delete, view_medical_documents | view_cross_zone, view_own, view_department, view_zone | view, create, update, activate, archive, test | view, create, accept, reject, complete, cancel | view_own, create_own, select_own, view_department, view_zone, view_cross_zone, confirm_department, confirm_zone | create_own, view_own, review, approve, reject, view_department, view_zone, view_cross_zone | report_own, view_own, view_department, view_zone, view_cross_zone, review, excuse, apply_deduction, close | view_own, view_department, view_zone, view_cross_zone, create, review, approve, reject, receive, process, cancel | view_own, download_own, download_hr, generate, revoke | view, create, update, delete | download, cancel | — |
| **BREAK_GLASS** | Same as FINAL_AUTHORITY (identical reach) | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | Same as FINAL_AUTHORITY | — | — |
| **ACCOUNTING** | — | — | — | — | — | — | — | — | report_own, view_own, view_department, view_zone, view_cross_zone, review, excuse, apply_deduction, close | view_cross_zone, receive, process | — | — | — | — |

**Custom/admin-created roles**: Any role an admin creates via the UI will start with no permissions. Permissions must be explicitly granted via the `role_permissions` table. The `/roles` endpoint returns each role's current permission codes.

---

## 5. Role Selection Dropdown — From Backend

The dropdown for selecting roles should populate from the `/roles` API endpoint. Here's what it returns:

### API: `GET /api/roles`
Returns all roles with their permission codes:

```json
[
  {
    "id": "SELF_SERVICE",
    "name": "Self-Service",
    "description": "Baseline for every active employee: read and file their own leave, read leave policy, see their own documents. No approval rights, no cross-zone reach.",
    "approval_tier": 0,
    "is_final_authority": false,
    "is_system": true,
    "active": true,
    "permissions": ["requests.view", "requests.create", ...] // all codes granted
  },
  {
    "id": "SUPERVISOR",
    "name": "Immediate Supervisor",
    "description": "Line manager for day-to-day leave approval within their own reporting line.",
    "approval_tier": 1,
    "is_final_authority": false,
    "is_system": true,
    "active": true,
    "permissions": [...]
  },
  {
    "id": "DEPT_HEAD",
    "name": "Department Head",
    "description": "Heads one department. Scope comes from the department grant; can approve, return and reject without any zone reach.",
    "approval_tier": 2,
    "is_final_authority": false,
    "is_system": true,
    "active": true,
    "permissions": [...]
  },
  {
    "id": "ZONAL_HEAD",
    "name": "Zone / Regional Head",
    "description": "Heads one zone. Scope comes from the zone grant; can approve within that zone only.",
    "approval_tier": 3,
    "is_final_authority": false,
    "is_system": true,
    "active": true,
    "permissions": [...]
  },
  {
    "id": "HR",
    "name": "Human Resources",
    "description": "Central HR. Cross-zone read for requests, staff and reports; administers staff, roles and leave policy.",
    "approval_tier": 4,
    "is_final_authority": false,
    "is_system": true,
    "active": true,
    "permissions": [...]
  },
  {
    "id": "FINAL_AUTHORITY",
    "name": "Final Authority — DPP",
    "description": "Final sign-off. Holds every catalogued permission explicitly, so the RBAC matrix shows exactly what the role can do. Organisation-wide reach comes from its four view_cross_zone codes.",
    "approval_tier": 5,
    "is_final_authority": true,
    "is_system": true,
    "active": true,
    "permissions": // all 34+ codes
  },
  {
    "id": "BREAK_GLASS",
    "name": "Break-Glass Authority",
    "description": "Emergency recovery access, identical in reach to the Final Authority. Every grant is capped at 30 days so it cannot quietly become a standing privilege.",
    "approval_tier": 5,
    "is_final_authority": false,
    "is_system": true,
    "active": true,
    "permissions": // same as FINAL_AUTHORITY but time-limited
  },
  {
    "id": "ACCOUNTING",
    "name": "Finance / Accounting",
    "description": "Finance office. Receives approved payment cases and settles them.",
    "approval_tier": 4,
    "is_final_authority": false,
    "is_system": true,
    "active": true,
    "permissions": [...]
  }
]
```

**Admin-created roles** will also appear here, with their currently granted permission codes. The UI can show: role name, description, tier, and a count of granted permissions.

---

## 6. Reduce Length of Role Description Text

The user wants the role description text shortened. Here are concise versions:

| Role | Short Description (≤30 words) |
|------|-------------------------------|
| **SELF_SERVICE** | Baseline: read/file own leave, read policy, see own documents. No approval rights. |
| **SUPERVISOR** | Approve/return/reject own team's leave requests within reporting line. |
| **DEPT_HEAD** | Approve/reject leave for own department; scoped to department grant only. |
| **ZONAL_HEAD** | Approve leave for own zone only; regional oversight for non-HQ zones. |
| **HR** | Administer staff, roles, policies; cross-zone read; manage documents. |
| **FINAL_AUTHORITY** | Final DPP sign-off; holds all permissions; org-wide reach via view_cross_zone. |
| **BREAK_GLASS** | Emergency access (30-day cap); identical reach to FINAL_AUTHORITY. |
| **ACCOUNTING** | Receive/process approved payment cases; cross-zone read for settlements. |

---

## 7. Why Date Not Shown?

The user asks "why is this not shown the date". Looking at the context, this likely refers to **hire date** not appearing on the employee registration or leave entitlement display.

**Hire date is stored** in the `employees` table (`hire_date` field) and is used for:
- Calculating service year for annual leave entitlement (`PolicyService::serviceYearStart()`)
- Determining when employees become eligible for leave
- Prorated accrual (`accrualFraction()`)

**Where hire date appears:**
- Employee registration form: `hire_date` field is present and required
- Employee detail/preview screens
- Leave balance calculations (`balanceFor()` uses hire date for service year)
- Leave letter templates (dynamic, pulls from request/employee)

**If the hire date isn't visible in a specific UI:**
- Check the Blade template for that page
- Ensure the employee object is passed with `hire_date` loaded
- The date format is `Y-m-d` in the database, displayed as `F j, Y` in UIs

---

## 8. Suggested Next Leave Based on Hire Date

The system should propose the **next available leave** an employee can request, based on their `hire_date`. Here's how it works:

### Accrual Logic
- **Service year** = anniversary of `hire_date`
- **Annual leave entitlement** = 30 days (current policy `pol1`, version 4)
- **Accrued days** = prorated based on time served in current service year
- **Example**: If hired June 1, 2026, and today is Oct 28, 2026:
  - Service year runs Jun 1, 2026 – May 31, 2027
  - Days accrued = 30 × (122 days / 365 days) ≈ 10 days
  - **Suggested next leave**: Up to 10 annual days available

### How to Calculate (for UI "Suggested Leave" feature)

```php
// In PolicyService or a new service
public function suggestedLeaveFor($employeeId): array
{
    $employee = Employee::findOrFail($employeeId);
    $hireDate = $employee->hire_date;
    $today = now()->date;
    
    // Service year start = hire date anniversary
    $serviceYearStart = Carbon::create(
        $today->year, 
        $hireDate->month, 
        $hireDate->day
    );
    
    if ($serviceYearStart > $today) {
        $serviceYearStart->subYear();
    }
    
    // Days accrued from service year start to today
    $accruedDays = $today->diffInDays($serviceYearStart);
    $maxPerYear = 30; // From current annual policy
    $accrued = max(0, min($maxPerYear, floor($accruedDays * $maxPerYear / 365)));
    
    // Used leave (approved requests)
    $used = LeaveRequest::where('employee_id', $employeeId)
        ->where('leave_type_id', 'lt1')  // ANNUAL
        ->whereIn('status', ['approved', 'under_review'])
        ->sum('days');
    
    $remaining = max(0, $accrued - $used);
    
    return [
        'hire_date' => $employee->hire_date,
        'service_year_start' => $serviceYearStart,
        'days_accrued' => $accrued,
        'days_used' => $used,
        'days_remaining' => $remaining,
        'suggested_max_request' => $remaining,
    ];
}
```

**UI Display Example:**
```
Your Annual Leave Entitlement:
- Hire Date: 2010-01-04
- Service Year: Jan 4, 2026 – Jan 4, 2027
- Accrued: 18 days
- Used (approved): 12 days
- **Remaining: 6 days**
- **Suggested next request**: Up to 6 annual leave days
```

---

## Summary of All Changes & Recommendations

| # | Item | Status/Action |
|---|------|---------------|
| 1 | **Employee with full permissions** | u1 (Ali Mwinyi Khamis) has most codes; u3 also extensive |
| 2 | **Register new employee** | Flow creates: User → Employee → Roles/Reporting; audited on save |
| 3 | **"Reports to" removed** | ✅ Already removed from employee registration |
| 4 | **Position last in hierarchy** | ✅ Positions assigned after zones/departments/units |
| 5 | **Permission matrix from /roles** | Complete matrix provided above, module by module |
| 6 | **Role dropdown from backend** | Populate from `GET /api/roles` endpoint |
| 7 | **Reduce role description length** | Concise versions provided (≤30 words each) |
| 8 | **Why date not shown** | Hire date stored; ensure UI passes employee with hire_date loaded |
| 8 | **Suggested next leave** | Calculate based on hire date anniversary + used leave; display remaining days |

**Recommendations:**
1. When building the employee registration UI, show hire date and calculate accrued leave
2. Populate role dropdown from `GET /api/roles` — shows all system + admin-created roles
3. Use concise role descriptions (provided above) in the UI
4. Implement "suggested next leave" using the calculation logic above
5. The "Reports to" field is not needed — reporting lines are a separate admin action