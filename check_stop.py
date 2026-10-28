import re
with open(r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql", encoding="utf-8") as f:
    text = f.read()
for tbl in ["audit_logs","cases","workflow_assignments","leave_requests","approval_signatures","migrations","cache","cache_locks","leave_request_dates","password_reset_tokens","position_default_roles","role_permissions","leave_balances","leave_documents","leave_reasons","notifications","personal_access_tokens"]:
    m = re.search(r"CREATE TABLE `"+tbl+r"`.*?ENGINE=[^;]+;", text, re.DOTALL)
    if m:
        block = m.group(0)
        has_pk = "PRIMARY KEY" in block
        id_line = ""
        for line in block.split("\n"):
            if "`id`" in line:
                id_line = line.strip()
                break
        # also check if AUTO_INCREMENT present in block near id
        auto = "AUTO_INCREMENT" in block
        print(f"{tbl}: PK={has_pk} AUTO={auto} | {id_line[:100]}")
