<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Models\Employee;
use App\Models\LeaveDocument;
use App\Models\LeaveRequest;
use App\Models\WorkflowAssignment;
use App\Services\AccessScopeService;
use App\Services\AuditService;
use App\Services\CaseHandoverService;
use App\Services\LeaveRequestService;
use App\Services\PermissionService;
use App\Services\WorkflowResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function __construct(
        protected LeaveRequestService $service,
        protected WorkflowResolver $resolver,
        protected CaseHandoverService $caseService,
        protected PermissionService $permissions,
        protected AccessScopeService $accessScope,
        protected AuditService $audit,
    ) {}

    /**
     * `scope=all` is a client-side filter hint, never proof of access. The
     * requests.view check still applies, and the result set is then constrained
     * to the caller's zone/department scope unless they hold
     * requests.view_cross_zone. This is what closes the nationwide leak where
     * any requests.view holder could read every request nationwide.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->input('scope') === 'all' && ! $this->permissions->can($user, 'requests', 'view')) {
            return response()->json(['message' => 'Missing permission: requests.view.'], 403);
        }

        $query = LeaveRequest::with(['employee', 'leaveType', 'policy', 'currentOwner', 'assignments.assignee']);

        if ($request->input('scope') === 'all') {
            $this->accessScope->applyLeaveRequestScope($query, $user);
        } else {
            $query->where('employee_id', $user->employee?->id);
        }

        return response()->json(['data' => $query->latest()->paginate((int) $request->input('per_page', 20))]);
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $draft = $this->service->createDraft($request->user()->employee->id, $request->validated());

        return response()->json(['data' => $draft->load(['employee', 'leaveType'])], 201);
    }

    /** Requester edits their own draft before submission (frontend updateRequest). */
    public function update(StoreLeaveRequest $form, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->employee_id !== $form->user()->employee?->id) {
            return response()->json(['message' => 'You can only edit your own requests.'], 403);
        }

        $updated = $this->service->updateDraft($leaveRequest, $form->user()->employee->id, $form->validated());

        return response()->json(['data' => $this->resource($updated)]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        return response()->json($this->resource($leaveRequest));
    }

    public function submit(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->employee_id !== $request->user()->employee?->id) {
            return response()->json(['message' => 'You can only submit your own requests.'], 403);
        }

        $updated = $this->service->submit($leaveRequest, $request->user()->id);

        return response()->json(['data' => $this->resource($updated), 'workflow' => $this->chain($updated)]);
    }

    public function resubmit(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->employee_id !== $request->user()->employee?->id) {
            return response()->json(['message' => 'You can only resubmit your own requests.'], 403);
        }

        $updated = $this->service->resubmit($leaveRequest, $request->user()->id);

        return response()->json(['data' => $this->resource($updated), 'workflow' => $this->chain($updated)]);
    }

    /**
     * Only the current step's assignee may decide (blueprint §2.2 — the
     * system never auto-moves a request). HR may not override ownership.
     */
    public function decide(Request $request, LeaveRequest $leaveRequest, DecideRequest $form): JsonResponse
    {
        $actor = $request->user()->employee;
        $outcome = $this->service->decide(
            $leaveRequest,
            $actor,
            $form->decision,
            $form->comments,
            $form->end_date,
            [
                'signature_hash' => $form->validated('signature_hash'),
                'signature_type' => $form->validated('signature_type'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'message' => $outcome['toast'],
            'blocked_by_handover' => $outcome['blocked_by_handover'],
            'data' => $this->resource($leaveRequest->fresh(['employee', 'leaveType', 'policy', 'assignments', 'currentOwner'])),
        ]);
    }

    public function withdraw(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->employee_id !== $request->user()->employee?->id) {
            return response()->json(['message' => 'You can only withdraw your own requests.'], 403);
        }
        if (! in_array($leaveRequest->status, ['draft', 'submitted', 'under_review'], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft, submitted, or under-review requests can be withdrawn.']);
        }

        return response()->json(['data' => $this->service->withdraw($leaveRequest, $request->user()->id)]);
    }

    /**
     * True deletion — but ONLY for drafts. Anything that ever entered
     * approval is audit history and may only be withdrawn (a status
     * change), never removed. Ownership mirrors submit/withdraw: the
     * applicant acts on their own record; no scope-based delete exists,
     * because a draft has never been routed to anyone.
     *
     * No route permission code by design (same doctrine as decide): the
     * capability is ownership-intrinsic — deleting your own unsubmitted
     * draft needs no role grant, and the controller enforces both guards.
     */
    public function destroy(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->employee_id !== $request->user()->employee?->id) {
            return response()->json(['message' => 'You can only delete your own requests.'], 403);
        }
        if ($leaveRequest->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft requests can be deleted. Submitted requests are kept in the audit trail.']);
        }

        $this->service->destroyDraft($leaveRequest, $request->user()->id);

        return response()->json(['message' => 'Draft deleted.', 'id' => $leaveRequest->id]);
    }

    /** Resolved approval chain preview for the submit confirmation modal. */
    public function workflow(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        // The chain exposes named assignees and step history, so it is scoped
        // exactly like the request itself rather than open to any signed-in user.
        $this->authorize('view', $leaveRequest);

        return response()->json(['data' => $this->chain($leaveRequest)]);
    }

    /**
     * Approval-chain preview for the new-request form. Resolution runs the
     * SAME WorkflowResolver as submission, so the preview cannot drift from
     * the chain that is later persisted — no client-side approximation.
     *
     * Authorization deliberately uses only the scope branch of the view
     * policy: ownership alone proves nothing here, because a plain
     * SELF_SERVICE requester would otherwise read the full chain of
     * approvers (names, roles, delegation hops) before anyone has been asked
     * to act. Scoped officers — the callers who create requests for others —
     * are exactly the ones who may see it.
     */
    public function previewChain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,id'],
            'leave_type_id' => ['required', 'string', 'exists:leave_types,id'],
            // Open-ended requests (return date set at approval) preview with
            // a null duration, exactly like the draft submission will see.
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $scope = $this->accessScope->forUser($request->user(), AccessScopeService::REQUESTS);

        if (! $this->accessScope->coversEmployee($scope, $employee)) {
            return response()->json(['message' => 'You do not have access to this employee.'], 403);
        }

        $draft = new LeaveRequest([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'days' => $data['days'] ?? null,
        ]);

        return response()->json(['data' => $this->resolver->resolveWorkflow($draft)]);
    }

    public function attachDocument(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('update', $leaveRequest);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'kind' => ['nullable', 'string', 'max:60'],
        ]);

        $path = $request->file('file')->store('documents/'.$leaveRequest->id, 'local');
        $doc = LeaveDocument::create([
            'request_id' => $leaveRequest->id,
            'file_ref' => $path,
            'document_type' => $request->input('kind', 'supporting'),
            'name' => $request->file('file')->getClientOriginalName(),
            'size' => (string) $request->file('file')->getSize(),
            'uploaded_by_user_id' => $request->user()->id,
            'uploaded_at' => now(),
        ]);

        return response()->json(['data' => $doc], 201);
    }

    /** Delete a document attached to a request, only for admin/authorised users. */
    public function deleteDocument(Request $request, LeaveRequest $leaveRequest, $document): JsonResponse
    {
        // Scoped to this request's own documents, so a valid document id from
        // another request cannot be deleted through this URL.
        $doc = $leaveRequest->documents()->find($document);

        if (! $doc) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $this->authorize('delete', $doc);

        $fileRef = $doc->file_ref;

        $doc->delete();

        // Remove the stored file too. A metadata row deleted while the blob
        // survives on disk is an orphaned copy of someone's medical record.
        // The disk is named explicitly because attachDocument() hardcodes
        // 'local'; reading it back from config('filesystems.default') silently
        // targeted a different root whenever FILESYSTEM_DISK was overridden,
        // leaving the blob behind.
        if ($fileRef) {
            Storage::disk('local')->delete($fileRef);
        }

        $this->audit->append('leave_request', (string) $leaveRequest->id, 'document.delete', $request->user()->id, [
            'document_id' => (string) $doc->id,
            'document_type' => $doc->document_type,
            'file_ref' => $fileRef,
        ]);

        return response()->json(['data' => ['id' => $doc->id, 'deleted' => true]], 200);
    }

    /**
     * Documents attached to a request that the caller may see.
     *
     * Filtering uses Gate::allows rather than $this->authorize(): authorize()
     * throws on the first denial, which turned one restricted medical document
     * into a 403 for the whole list. The per-document decision stays with
     * LeaveDocumentPolicy.
     */
    public function documents(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        $visible = $leaveRequest->documents()
            ->get()
            ->filter(fn ($doc) => Gate::allows('view', $doc))
            ->values();

        return response()->json(['data' => $visible]);
    }

    /** A single document. Denies with 403 rather than hiding behind a 404. */
    public function showDocument(Request $request, LeaveRequest $leaveRequest, $document): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        $doc = $leaveRequest->documents()->find($document);

        if (! $doc) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $this->authorize('view', $doc);

        return response()->json(['data' => $doc]);
    }

    /**
     * Stream the stored file itself, under exactly the same authorisation as the
     * metadata read.
     *
     * showDocument() returns the row only, which means there was no route that
     * could actually hand somebody their medical certificate back - the UI could
     * list a document and then never open it. Both paths run view on the request
     * and view on the document, so a caller who cannot see the metadata cannot
     * reach the bytes either; the medical-code gate is unchanged.
     */
    public function downloadDocument(Request $request, LeaveRequest $leaveRequest, $document)
    {
        $this->authorize('view', $leaveRequest);

        $doc = $leaveRequest->documents()->find($document);
        if (! $doc) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $this->authorize('view', $doc);

        if (! $doc->file_ref || ! Storage::disk('local')->exists($doc->file_ref)) {
            return response()->json(['message' => 'Document file is missing from storage'], 404);
        }

        $this->audit->append('leave_request', (string) $leaveRequest->id, 'document.download', $request->user()->id, [
            'document_id' => (string) $doc->id,
            'document_type' => $doc->document_type,
        ]);

        return Storage::disk('local')->download($doc->file_ref, $doc->name);
    }

    /** Upcoming/active approvals pending on the authenticated employee. */
    public function myInbox(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        // The inbox is assignment-derived on purpose: workflow steps can land
        // on a head outside their record scope (a parent department inherits
        // child-department steps; an unscoped holder matches any requester).
        // Each row therefore carries an honest out_of_scope flag so the UI can
        // say "assigned to you, but this request is outside your department/
        // zone" instead of silently disagreeing with the scope-filtered list.
        $scopeService = resolve(AccessScopeService::class);
        $scope = $scopeService->forUser($request->user(), AccessScopeService::REQUESTS);

        $rows = WorkflowAssignment::with(['request.employee', 'request.leaveType'])
            ->where('assigned_employee_id', $employee?->id)
            ->where('status', 'pending')
            ->orderBy('due_at')
            ->get()
            ->map(function ($wa) use ($scope, $scopeService, $employee) {
                $requester = $wa->request?->employee;

                return [
                    'assignment_id' => $wa->id,
                    'request_ref' => $wa->request?->ref,
                    'employee' => $requester?->full_name,
                    'leave_type' => $wa->request?->leaveType?->code,
                    'days' => $wa->request?->days,
                    'start_date' => $wa->request?->start_date?->toDateString(),
                    'due_at' => $wa->due_at?->toDateTimeString(),
                    'role' => $wa->assigned_role_id,
                    // Own requests are never "out of scope"; everyone else is
                    // measured against the caller's zone/department grants.
                    'out_of_scope' => $requester !== null
                        && $requester->id !== $employee?->id
                        && ! $scopeService->coversEmployee($scope, $requester),
                ];
            });

        return response()->json(['data' => $rows]);
    }

    protected function chain(LeaveRequest $request): array
    {
        return $this->resolver->resolveWorkflow($request);
    }

    protected function resource(LeaveRequest $request): array
    {
        $assignments = $request->assignments()->get()->loadMissing('assignee');

        return [
            ...$request->only('id', 'ref', 'employee_id', 'leave_type_id', 'policy_id', 'start_date', 'end_date', 'days', 'reason', 'status', 'current_owner_id', 'submitted_at', 'decided_at', 'is_half_day', 'is_emergency_exception', 'exception_reason'),
            'employee' => $request->employee?->only('id', 'full_name'),
            'leave_type' => $request->leaveType?->only('code', 'name'),
            'assignments' => $assignments->map(fn ($a) => [
                'id' => $a->id,
                'role' => $a->assigned_role_id,
                'assignee_id' => $a->assigned_employee_id,
                'assignee_name' => $a->assignee?->full_name,
                'status' => $a->status,
                'due_at' => $a->due_at?->toDateTimeString(),
                'decided_at' => $a->decided_at?->toDateTimeString(),
            ])->values(),
        ];
    }
}
