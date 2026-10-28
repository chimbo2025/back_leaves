with open(r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql", encoding="utf-8") as f:
    text = f.read()
# find audit_logs block by split
start = text.find("CREATE TABLE `audit_logs`")
end = text.find("CREATE TABLE `approval_roles`", start)
if end == -1:
    end = len(text)
segment = text[start:end]
for line in segment.split("\n"):
    if "id" in line or "PRIMARY" in line or "ENGINE" in line:
        print(line)
