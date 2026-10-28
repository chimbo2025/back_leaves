<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $documentType }} Official Leave Letter</title>
    <style>
        body { font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.5; color: #1a1a1a; margin: 0; padding: 40px 60px; }
        .header { text-align: center; border-bottom: 3px solid {{ $brandColor ?? '#1a3c5e' }}; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { max-height: 80px; margin-bottom: 10px; }
        .org-name { font-size: 18pt; font-weight: bold; color: {{ $brandColor ?? '#1a3c5e' }}; margin: 0; }
        .org-subtitle { font-size: 11pt; color: #4a4a4a; margin: 5px 0 0; }
        .doc-title { font-size: 16pt; font-weight: bold; text-align: center; color: {{ $brandColor ?? '#1a3c5e' }}; margin: 30px 0; text-transform: uppercase; letter-spacing: 1px; }
        .doc-ref { text-align: center; font-size: 10pt; color: #666; margin-bottom: 30px; }
        .section { margin-bottom: 25px; }
        .section-title { font-size: 11pt; font-weight: bold; color: {{ $brandColor ?? '#1a3c5e' }}; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 0.5px; }
        .field-row { display: flex; margin-bottom: 8px; }
        .field-label { width: 200px; font-weight: 600; color: #333; flex-shrink: 0; }
        .field-value { flex: 1; color: #1a1a1a; }
        .approval-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 10pt; }
        .approval-table th, .approval-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .approval-table th { background-color: {{ $brandColor ?? '#1a3c5e' }}; color: white; font-weight: 600; }
        .approval-table tr:nth-child(even) { background-color: #f9f9f9; }
        .footer { margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 9pt; color: #666; text-align: center; }
        .qr-section { text-align: center; margin-top: 30px; }
        .qr-code { width: 100px; height: 100px; background: #f0f0f0; display: inline-block; margin-bottom: 10px; }
        .qr-label { font-size: 8pt; color: #888; }
        .signature-block { margin-top: 40px; display: flex; justify-content: space-between; }
        .signature-line { width: 250px; text-align: center; }
        .signature-line .line { border-top: 1px solid #333; margin-bottom: 5px; height: 40px; }
        .signature-line .name { font-weight: bold; font-size: 10pt; }
        .signature-line .title { font-size: 9pt; color: #666; }
        .watermark { position: fixed; bottom: 50px; right: 50px; opacity: 0.05; font-size: 120pt; color: {{ $brandColor ?? '#1a3c5e' }}; transform: rotate(-45deg); z-index: -1; }
    </style>
</head>
<body>
    <div class="watermark">{{ $orgName }}</div>

    <div class="header">
        @if($logoPath ?? false)
            <img src="{{ $logoPath }}" alt="Organisation Logo" class="logo">
        @endif
        <h1 class="org-name">{{ $orgName }}</h1>
        @if(isset($orgSubtitle) && $orgSubtitle)
            <p class="org-subtitle">{{ $orgSubtitle }}</p>
        @endif
    </div>

    <h2 class="doc-title">{{ $documentTypeLabel }}</h2>

    <div class="doc-ref">
        <strong>Document Reference:</strong> {{ $documentReference }}<br>
        <strong>Document Version:</strong> v{{ $documentVersion }} | <strong>Template Version:</strong> v{{ $templateVersion }}<br>
        <strong>Content Hash:</strong> {{ substr($contentHash, 0, 16) }}...
    </div>

    <div class="section">
        <h3 class="section-title">Requester Information</h3>
        <div class="field-row">
            <span class="field-label">Full Name:</span>
            <span class="field-value">{{ $request->employee->full_name ?? 'N/A' }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Employee ID:</span>
            <span class="field-value">{{ $request->employee_id }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Department:</span>
            <span class="field-value">{{ $request->employee->department?->name ?? 'N/A' }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Zone:</span>
            <span class="field-value">{{ $request->employee->zone?->name ?? 'N/A' }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Position:</span>
            <span class="field-value">{{ $request->employee->position?->title ?? 'N/A' }}</span>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">Leave Details</h3>
        <div class="field-row">
            <span class="field-label">Leave Type:</span>
            <span class="field-value">{{ $leaveTypeLabel }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Leave Reference:</span>
            <span class="field-value">{{ $request->ref }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Start Date:</span>
            <span class="field-value">{{ $request->start_date?->format('F j, Y') }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">End Date:</span>
            <span class="field-value">{{ $request->end_date?->format('F j, Y') }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Total Days:</span>
            <span class="field-value">{{ $request->days }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Policy Version:</span>
            <span class="field-value">{{ $request->policy_version }}</span>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">Approval Chain</h3>
        <table class="approval-table">
            <thead>
                <tr>
                    <th>Step</th>
                    <th>Approver</th>
                    <th>Position / Role</th>
                    <th>Decision</th>
                    <th>Date &amp; Time</th>
                    <th>Comments</th>
                </tr>
            </thead>
            <tbody>
                @foreach($approval_snapshot as $index => $step)
                    @php
                        $assignedRole = null;
                        $assigneeName = $step['assignee'] ?? '—';
                        $actions = $step['actions'] ?? [];
                        $actionDecision = '';
                        $actionComment = '';
                        $actionDate = '';
                        $activeAction = null;
                    @endphp
                    @foreach($actions as $action)
                        @if(empty($activeAction) || ($action['acted_at'] ?? null) > ($activeAction['acted_at'] ?? null))
                            @php
                                $activeAction = $action;
                            @endphp
                        @endif
                    @endforeach
                    @if($activeAction)
                        @php
                            $actionDecision = strtoupper(trim($activeAction['decision'] ?? ''));
                            $actionComment = $activeAction['comments'] ?? '—';
                            $actionDate = $activeAction['acted_at']
                                ? \Carbon\Carbon::parse($activeAction['acted_at'])->format('M j, Y H:i')
                                : '—';
                        @endphp
                    @else
                        $actionDecision = 'Pending';
                        $actionComment = '';
                        $actionDate = '—';
                    @endif

                    @php
                        // Build display label for assignee position/role
                        $positionOrRole = 'N/A';
                        if (!empty($step['assignee'])) {
                            $positionOrRole = '';
                            if ($step['assignee']->position) {
                                $positionOrRole = $step['assignee']->position->title ?? '';
                            }
                            // If no position, try to find role via assignment
                            // The snapshot stores role_id, resolve through pending actions
                            $roleCode = '';
                            // Try to match by checking the assignments relation if loaded
                            $roleCode = $step['role'] ?? '';
                            if ($roleCode) {
                                try {
                                    $roleCode = \App\Models\ApprovalRole::find($roleCode)?->code ?? $roleCode;
                                } catch (\Exception $e) {
                                    $roleCode = $roleCode;
                                }
                            }
                            if ($positionOrRole && $roleCode) {
                                $positionOrRole .= ' / ' . $roleCode;
                            } elseif ($roleCode) {
                                $positionOrRole = $roleCode;
                            }
                        }
                    @endphp

                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $assigneeName }}</td>
                        <td>{{ $positionOrRole }}</td>
                        <td><span style="text-transform:capitalize;">{{ $actionDecision }}</span></td>
                        <td>{{ $actionDate }}</td>
                        <td>{{ $actionComment }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h3 class="section-title">Issuing Authority</h3>
        <div class="field-row">
            <span class="field-label">Issued By:</span>
            <span class="field-value">{{ $generator->full_name ?? 'N/A' }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Issue Date:</span>
            <span class="field-value">{{ now()->format('F j, Y') }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Document Version:</span>
            <span class="field-value">v{{ $documentVersion }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Template Version:</span>
            <span class="field-value">v{{ $templateVersion }}</span>
        </div>
    </div>

    @if($finalAuthority)
        <div class="section">
            <h3 class="section-title">Final Approval Signature</h3>
            <div class="field-row">
                <span class="field-label">By:</span>
                <span class="field-value">{{ $finalAuthority->full_name ?? 'N/A' }}</span>
            </div>
            <div class="field-row">
                <span class="field-label">Date:</span>
                <span class="field-value">{{ $finalAuthority->signed_at?->format('M j, Y') ?? '—' }}</span>
            </div>
            <div class="field-row">
                <span class="field-label">Decision:</span>
                <span class="field-value">{{ $finalAuthority->decision }}</span>
            </div>
        </div>
    @endif

    <div class="qr-section">
        <div class="qr-code" title="QR Code: {{ $qrToken }}">
            [QR: {{ substr($qrToken, 0, 16) }}...]
        </div>
        <div class="qr-label">Scan to verify authenticity</div>
    </div>

    <div class="verification-info">
        Verify this document at: <strong>{{ url('/verify/letter/' . $qrToken) }}</strong><br>
        Document Reference: {{ $documentReference }} | Content Hash: {{ substr($contentHash, 0, 16) }}...
    </div>

    <div class="footer">
        This is an official document issued by {{ $orgName }}.<br>
        Unauthorised reproduction or alteration is prohibited.<br>
        Generated on {{ now()->format('F j, Y \a\t H:i:s') }} | Document Version v{{ $documentVersion }}
    </div>
</body>
</html>
