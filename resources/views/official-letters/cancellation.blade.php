<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Leave Cancellation Letter</title>
    <style>
        body { font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.5; color: #1a1a1a; margin: 0; padding: 40px 60px; }
        .header { text-align: center; border-bottom: 3px solid #5a5a5a; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { max-height: 80px; margin-bottom: 10px; }
        .org-name { font-size: 18pt; font-weight: bold; color: #3a3a3a; margin: 0; }
        .org-subtitle { font-size: 11pt; color: #4a4a4a; margin: 5px 0 0; }
        .doc-title { font-size: 16pt; font-weight: bold; text-align: center; color: #3a3a3a; margin: 30px 0; text-transform: uppercase; letter-spacing: 1px; }
        .doc-ref { text-align: center; font-size: 10pt; color: #666; margin-bottom: 30px; }
        .section { margin-bottom: 25px; }
        .section-title { font-size: 11pt; font-weight: bold; color: #3a3a3a; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 0.5px; }
        .field-row { display: flex; margin-bottom: 8px; }
        .field-label { width: 200px; font-weight: 600; color: #333; flex-shrink: 0; }
        .field-value { flex: 1; color: #1a1a1a; }
        .cancellation-notice { background: #f5f5f5; border: 1px solid #ddd; border-left: 4px solid #5a5a5a; padding: 20px; margin: 20px 0; font-size: 11pt; }
        .cancellation-notice strong { color: #3a3a3a; }
        .footer { margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 9pt; color: #666; text-align: center; }
        .qr-section { text-align: center; margin-top: 30px; }
        .qr-code { width: 100px; height: 100px; background: #f0f0f0; display: inline-block; margin-bottom: 10px; }
        .qr-label { font-size: 8pt; color: #888; }
        .verification-info { font-size: 9pt; color: #666; margin-top: 15px; }
        .watermark { position: fixed; bottom: 50px; right: 50px; opacity: 0.05; font-size: 120pt; color: #5a5a5a; transform: rotate(-45deg); z-index: -1; }
    </style>
</head>
<body>
    <div class="watermark">CANCELLED</div>

    <div class="header">
        @if($logoPath ?? false)
            <img src="{{ $logoPath }}" alt="Organisation Logo" class="logo">
        @endif
        <h1 class="org-name">DIRECTORATE OF PUBLIC PROSECUTIONS</h1>
        <p class="org-subtitle">Zanzibar, Tanzania</p>
    </div>

    <h2 class="doc-title">Official Leave Cancellation Letter</h2>

    <div class="doc-ref">
        <strong>Document Reference:</strong> {{ $documentReference }}<br>
        <strong>Document Version:</strong> v{{ $documentVersion }} | <strong>Template Version:</strong> v{{ $templateVersion }}<br>
        <strong>Content Hash:</strong> {{ substr($contentHash, 0, 16) }}...
    </div>

    <div class="section">
        <h3 class="section-title">Employee Information</h3>
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
    </div>

    <div class="section">
        <h3 class="section-title">Original Leave Request Details</h3>
        <div class="field-row">
            <span class="field-label">Leave Type:</span>
            <span class="field-value">{{ $request->leaveType->code ?? 'N/A' }} — {{ $request->leaveType->name ?? '' }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Leave Reference:</span>
            <span class="field-value">{{ $request->ref }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Original Period:</span>
            <span class="field-value">{{ $request->start_date?->format('F j, Y') }} to {{ $request->end_date?->format('F j, Y') }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Total Days:</span>
            <span class="field-value">{{ $request->days }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Original Status:</span>
            <span class="field-value">{{ ucfirst($request->status) }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Policy Version:</span>
            <span class="field-value">{{ $request->policy_version }}</span>
        </div>
    </div>

    <div class="cancellation-notice">
        <strong>NOTICE: LEAVE REQUEST CANCELLED</strong><br><br>
        The above leave request has been <strong>cancelled</strong> by the authorised authority. This cancellation supersedes any prior approval or pending status. The employee is expected to resume normal duties as per their regular schedule.
    </div>

    <div class="section">
        <h3 class="section-title">Cancellation Details</h3>
        <div class="field-row">
            <span class="field-label">Cancellation Date:</span>
            <span class="field-value">{{ now()->format('F j, Y') }}</span>
        </div>
        <div class="field-row">
            <span class="field-label">Cancelled By:</span>
            <span class="field-value">{{ $generator->full_name ?? 'System' }}</span>
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
        This is an official document issued by the Directorate of Public Prosecutions, Zanzibar.<br>
        Unauthorised reproduction or alteration is prohibited.<br>
        Generated on {{ now()->format('F j, Y \a\t H:i:s') }} | Document Version v{{ $documentVersion }}
    </div>
</body>
</html>