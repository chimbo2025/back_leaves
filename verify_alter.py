with open(r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql", encoding="utf-8") as f:
    lines = f.read().split("\n")
# Show a few ALTER blocks to verify removal
for i, line in enumerate(lines):
    if "ALTER TABLE" in line:
        snippet = "\n".join(lines[i:min(i+8, len(lines))])
        if "approval_actions" in snippet or "audit_logs" in snippet or "cache" in snippet or "leave_request_dates" in snippet or "position_default_roles" in snippet:
            print("===", snippet[:200], "===")
            break
