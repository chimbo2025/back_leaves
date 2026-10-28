# Phase 6 — Digital Approvals & Official Leave Documents (Implementation Report)

**Date:** 2026-10-11  
**Status:** COMPLETE — all 213 tests passing (2734 assertions)  
**Scope:** Digital approval signatures, official leave letters (approval/rejection/cancellation), QR verification, secure document storage and download.  
**Out of scope (Phase 7+):** Reports, exports, batch generation, advanced workflows.

---

## 1. Database Changes

### New table: `approval_signatures`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `leave_request_id` | bigint | FK → leave_requests |
| `approval_action_id` | bigint | nullable, FK → approval_actions |
| `actor_id` | string | employee_id who signed |
| `approval_action` | enum | `approved`, `rejected`, `returned` |
| `signature_type` | enum | `digital` (default), `wet_ink_reference` |
| `signature_value` | string | nullable, opaque HSM token reference (NOT raw key) |
| `signature_hash` | string(64) | SHA-256 of canonical payload |
| `signed_at` | timestamp | from ApprovalAction.acted_at |
| `ip_address` | string(45) | nullable |
| `user_agent` | text | nullable |
| `metadata_json` | json | policy_version, return_date, actor_name, role, decision, comments |
| `timestamps` | | created_at / updated_at |

**Indexes:** `(leave_request_id, actor_id)`, `approval_action_id`, `signature_hash`

### New table: `official_leave_letters`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `leave_request_id` | bigint | FK → leave_requests |
| `document_type` | enum | `approval_letter`, `rejection_letter`, `cancellation_letter` |
| `document_version` | unsigned int | starts at 1, increments on regeneration |
| `template_version` | unsigned int | default 1, configurable |
| `policy_snapshot` | json | full policy at generation time |
| `approval_snapshot` | json | approval chain with decisions/comments |
| `content_hash` | string(64) | SHA-256 of rendered PDF bytes |
| `storage_path` | string | relative path on `local` (private) disk |
| `storage_disk` | string(20) | default `local` |
| `generated_by` | string | employee_id |
| `generated_at` | timestamp | |
| `revoked_at` | timestamp | nullable |
| `revoked_by` | string | nullable, employee_id |
| `revocation_reason` | text | nullable |
| `qr_token` | string(64) | unique, random, for public verification |
| `timestamps` | | created_at / updated_at |

**Constraints:** `unique(leave_request_id, document_type, document_version)`, index on `qr_token`, index on `revoked_at`

### Permissions (migration `2026_10_11_120200`)
- Module: `leave_documents` (13th module)
- 6 codes: `generate`, `view_own`, `download_own`, `download_hr`, `verify_public`, `revoke`
- Role grants:
  - `SELF_SERVICE` → `view_own`, `download_own`, `verify_public`
  - `SUPERVISOR/DEPT_HEAD/ZONAL_HEAD` → `view_own`, `download_own`
  - `HR` → all except `verify_public` (generation + HR download + revoke)
  - `FINAL_AUTHORITY` + `BREAK_GLASS` → all 6
  - `ACCOUNTING` → none

---

## 2. Services

| Service | Responsibility |
|---------|----------------|
| `LeaveApprovalSignatureService` | Records a digital signature (SHA-256 hash of canonical payload) for each approval decision. Called from `LeaveRequestService::decide()`. Never stores raw private keys — only opaque HSM token reference and hash. |
| `OfficialLeaveLetterService` | Generates official PDF letters (approval/rejection/cancellation) after required status is reached. Idempotent: repeated calls return existing letter unless content changed, then creates new version. Stores policy & approval snapshots, content hash, QR token. Registers token with verification service. |
| `LeaveDocumentRenderer` | Renders Blade templates to PDF using Dompdf (falls back to HTML in test env). Templates in `resources/views/official-letters/`. |
| `DocumentVerificationService` | Generates random QR tokens, registers them (cached 24h), verifies tokens, returns public-safe data only. Revokes tokens on letter revocation. |
| `SecureDocumentService` | Enforces private storage downloads, temporary signed URLs (5 min TTL), download audit events, revoked document rejection, scope enforcement (department/zone). |

---

## 3. Letter Generation Flow

1. **Prerequisite:** Leave request must be `approved` (for approval letter), `rejected`/`returned_for_correction` (rejection), or any status (cancellation).
2. **Generation:** HR calls `POST /leave-requests/{id}/letters/generate` (or rejection/cancellation).
3. **Idempotency:** Service checks existing active letter of same type. If content unchanged (policy_snapshot + approval_snapshot identical), returns existing. Otherwise increments `document_version` and creates new.
4. **Rendering:** Blade template → Dompdf → PDF bytes → SHA-256 `content_hash`.
5. **Storage:** PDF saved to `local` (private) disk at `official-letters/{request_id}/{type}_v{version}_{hash}.pdf`.
5. **Database:** Record created with snapshots, hash, QR token.
6. **QR Token:** Registered in cache (`document_verification:{token}` → letter_id, 24h TTL).
7. **Audit:** `official_letter.generated` event with content_hash and QR token.

---

## 4. Verification Flow (Public, No Auth)

1. **QR Code:** Frontend generates QR from `qr_token` on letter.
2. **Scan:** Anyone scans QR → hits `GET /api/verify/letter/{qrToken}` (no auth).
3. **Lookup:** Service checks cache → letter_id → loads letter with relations.
4. **Revocation Check:** If `revoked_at` set → returns `valid: false, error: "revoked"`.
5. **Response:** Returns public-safe data only:
   - Document reference, type, version, template version
   - Employee name, ID (no medical leave type name, no salary)
   - Leave dates, days
   - Issue date, issuing authority name
   - Status (`valid`/`revoked`), content hash
   - Verification timestamp
   - **Never exposed:** leave type name (medical), reason, salary, internal comments, approval metadata, uploaded documents.

---

## 5. Download Flow (Authenticated, Scoped)

1. **Endpoint:** `GET /leave-requests/{id}/letters/{letter}/download`
2. **Authorization:** `SecureDocumentService::authorizeDownload()`
   - Subject (employee) → `leave_documents.download_own`
   - HR/authority → `leave_documents.download_hr` + scope (zone/department via `AccessScopeService`)
3. **Revocation Check:** Rejects if `revoked_at` set.
4. **Storage Check:** File must exist on `local` (private) disk.
5. **Audit:** `official_letter.download` event with content_hash, IP.
6. **Response:** `Storage::disk()->download()` with safe filename.

**Temporary URL:** `GET /.../temporary-url` returns signed URL (5 min TTL) for direct browser download.

---

## 6. API Endpoints

| Method | URI | Permission | Notes |
|--------|-----|------------|-------|
| GET | `/leave-requests/{id}/letters` | policy `viewAny` | List all versions |
| POST | `/leave-requests/{id}/letters/generate` | `leave_documents.generate` | Approval letter |
| POST | `/leave-requests/{id}/letters/generate-rejection` | `leave_documents.generate` | Rejection letter |
| POST | `/leave-requests/{id}/letters/generate-cancellation` | `leave_documents.generate` | Cancellation letter |
| GET | `/leave-requests/{id}/letters/{letter}` | policy `view` | Metadata |
| GET | `/leave-requests/{id}/letters/{letter}/download` | policy `downloadOwn`/`downloadHr` | Private storage download |
| GET | `/leave-requests/{id}/letters/{letter}/temporary-url` | `leave_documents.download_hr` | Signed URL (5 min) |
| POST | `/leave-requests/{id}/letters/{letter}/revoke` | `leave_documents.revoke` | Revoke + invalidate QR |
| GET | `/verify/letter/{qrToken}` | **none (public)** | QR verification |
| GET | `/leave-requests/{id}/letters/verify-signatures` | policy `view` | Signature audit |

---

## 7. Templates

Located in `resources/views/official-letters/`:
- `approval.blade.php` — full approval letter with org letterhead, employee info, leave details, approval chain table, QR code, issuing authority signature block.
- `rejection.blade.php` — rejection letter with red header, decision notice, review history table, QR code.
- `cancellation.blade.php` — cancellation letter with grey header, original request details, cancellation notice, QR code.

All templates include:
- Organisation letterhead (logo, name, subtitle)
- Official document reference: `DPP-LET-{YEAR}-{REQUEST_REF}-{TYPE}-v{VERSION}`
- Content hash (truncated) for integrity
- QR code placeholder + verification URL
- Content hash for integrity verification
- Watermark (OFFICIAL/REJECTED/CANCELLED)

---

## 8. Configuration (`config/leave.php`)

```php
'documents' => [
    'template_version' => 1,
],
```

---

## 9. Test Coverage (22 new tests in `Phase6DocumentTest.php`)

| Scenario | Test |
|----------|------|
| Generate approval letter after approval | ✓ |
| Generate rejection letter after rejection | ✓ |
| Generate cancellation letter | ✓ |
| Idempotent generation (no duplicate) | ✓ |
| New version on content change | ✓ |
| Only approved requests can generate approval letter | ✓ |
| Only rejected/returned requests can generate rejection letter | ✓ |
| Unauthorized users cannot download | ✓ |
| Private storage enforced (not public disk) | ✓ |
| QR verification works with valid token | ✓ |
| Invalid/expired tokens rejected | ✓ |
| Revoked documents fail verification | ✓ |
| QR verification exposes no sensitive data | ✓ |
| Duplicate generation idempotent | ✓ |
| Content hash stable across renders | ✓ |
| Approval signature audit data stored | ✓ |
| No passwords/private keys stored | ✓ |
| Download events audited | ✓ |
| Historical documents unchanged after regeneration | ✓ |
| Scope enforcement on HR download | ✓ |
| Revoked letters cannot be downloaded | ✓ |
| Temporary URL works and expires | ✓ |

---

## 10. Files Created / Modified

### Created
- `database/migrations/2026_10_11_120000_create_approval_signatures_table.php`
- `database/migrations/2026_10_11_120100_create_official_leave_letters_table.php`
- `database/migrations/2026_10_11_120200_add_leave_documents_permissions.php`
- `app/Models/ApprovalSignature.php`
- `app/Models/OfficialLeaveLetter.php`
- `app/Services/LeaveApprovalSignatureService.php`
- `app/Services/OfficialLeaveLetterService.php`
- `app/Services/LeaveDocumentRenderer.php`
- `app/Services/DocumentVerificationService.php`
- `app/Services/SecureDocumentService.php`
- `app/Policies/OfficialLeaveLetterPolicy.php`
- `app/Http/Controllers/Api/OfficialLetterController.php`
- `resources/views/official-letters/approval.blade.php`
- `resources/views/official-letters/rejection.blade.php`
- `resources/views/official-letters/cancellation.blade.php`
- `components/verify/DocumentVerificationGate.tsx` (frontend gate)
- `tests/Feature/Api/Phase6DocumentTest.php`

### Modified
- `routes/api.php` (import + routes)
- `config/leave.php` (`documents.template_version`)
- `config/permission_enforcement.php` (6 enforcement references + `frontend_only`)
- `app/Services/PermissionService.php` (`SELF_SERVICE` + `verify_public`)
- `app/Services/AccessScopeService.php` (`LEAVE_DOCUMENTS` constant)
- `database/seeders/DatabaseSeeder.php` (grants, clean, authority profile)
- `tests/Feature/Api/EffectiveGrantsTest.php` (count 67→68)
- `tests/Feature/Api/LifecycleRegressionTest.php` (modules 12→13)
- `tests/Feature/Api/RegistrationPermsTest.php` (count 62→68, module list)
- `tests/Feature/Api/PermissionCatalogIntegrityTest.php` (frontend_only + rationale)

---

## 11. Verification

```bash
php artisan test
# → 213 tests, 2734 assertions, 0 failures
```

All Phase 1–5 tests remain green; Phase 6 adds comprehensive coverage.

---

## 12. Unresolved Legal / Document-Policy Decisions

1. **Template Legal Review:** Blade templates contain statutory language ("Directorate of Public Prosecutions, Zanzibar", "Unauthorised reproduction or alteration is prohibited"). These must be reviewed by legal counsel before production deployment.

2. **Signature Legal Validity:** The digital signature stores a SHA-256 hash of a canonical payload and an opaque HSM token reference. Whether this satisfies local e-signature law (e.g., Tanzania's Electronic Transactions Act) requires legal confirmation. A wet-ink reference fallback exists for hybrid workflows.

3. **QR Token Lifetime:** Cache TTL is 24 hours. For long-lived physical letters, the token must be refreshed via a scheduled job or the verification endpoint must fall back to database lookup when cache expires.

4. **Document Retention:** No automated retention/deletion policy exists for generated PDFs in private storage. A retention schedule (e.g., 7 years per Tanzanian public service regulations) should be implemented as a scheduled job.

5. **Letterhead Assets:** Templates reference `$logoPath` variable (not yet implemented). Production deployment must provide the approved logo asset and configure the path.

6. **Template Versioning:** `template_version` is tracked but no migration path exists for template changes. A formal template review/approval workflow should be added if templates change frequently.

7. **Internationalisation:** Templates are English-only. Swahili versions may be required for official use in Zanzibar.

8. **Accessibility:** PDF output should be tagged/accessible (WCAG 2.1 AA) for screen readers. Dompdf's accessibility support should be verified.