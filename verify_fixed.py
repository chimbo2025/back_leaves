with open(r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql", encoding="utf-8") as f:
    text = f.read()
for tbl in ["audit_logs","cases","workflow_assignments","leave_requests","migrations","approval_signatures","personal_access_tokens","users","employees","departments","leave_balances","notification_deliveries","approval_actions","approval_roles"]:
    m = __import__("re").search(r"CREATE TABLE `"+tbl+r"`.*?ENGINE=[^;]+;", text, __import__("re").DOTALL)
    if m:
        block = m.group(0)
        pk = "PRIMARY KEY" in block
        auto = "AUTO_INCREMENT" in block
        print(f"{tbl}: PK={pk} AUTO={auto} lines={len(block.split(chr(10)))}")
