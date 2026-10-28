import os, re
base = r"E:\Dev\Zee\backend-leave\database\migrations"
files = [f for f in os.listdir(base) if f.endswith('.php')]
for fname in sorted(files):
    with open(os.path.join(base, fname), encoding="utf-8", errors="ignore") as f:
        content = f.read()
    # find table names inside Schema::create('...')
    m = re.search(r"Schema::create\('([^']+)'", content)
    if m:
        tbl = m.group(1)
        has_id = "$table->id()" in content or "$table->bigIncrements('id')" in content or "$table->increments('id')" in content
        composite = "->primary(" in content
        if tbl in ["audit_logs","cases","workflow_assignments","leave_requests","approval_signatures","migrations","cache","cache_locks","leave_request_dates","password_reset_tokens","position_default_roles","role_permissions","leave_balances","leave_documents","leave_reasons","notifications","personal_access_tokens","approval_roles","users","employees","departments","positions","roles","permissions","modules","workflow_definitions","workflow_steps","zones","delegations","leave_policies","leave_plans","leave_plan_options","leave_types","leave_waivers","leave_rules","leave_documents","leave_document_requirements","leave_payment_cases","official_leave_letters","notification_preferences","notification_deliveries","employee_approval_roles","employee_roles","report_exports","reporting_lines","case_handovers","approval_actions","approval_signatures","balance_transactions","audit_logs"]:
            print(f"{tbl}: id-like={has_id} composite={composite} file={fname}")
