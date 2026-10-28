import re
with open(r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql", encoding="utf-8") as f:
    text = f.read()
# Find ALTER TABLE for audit_logs
m = re.search(r"ALTER TABLE `audit_logs`\n(.*?)(?=\n---)", text, re.DOTALL)
print("block found:", m is not None)
if m:
    print("BLOCK:")
    print(repr(m.group(1)))
else:
    # try alternative
    idx = text.find("ALTER TABLE `audit_logs`")
    print("idx", idx)
    seg = text[idx:idx+200]
    print(repr(seg))
