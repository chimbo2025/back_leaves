with open(r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql", encoding="utf-8") as f:
    text = f.read()
start = text.find("CREATE TABLE `audit_logs`")
# find next semicolon after ENGINE
end = text.find(";", text.find("ENGINE=InnoDB", start))
print("start", start, "end", end)
print(text[start:end+10])
