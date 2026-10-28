with open(r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql", encoding="utf-8") as f:
    text = f.read()
start = text.find("CREATE TABLE `audit_logs`")
end = text.find("ENGINE=InnoDB", start)
if end != -1:
    end += 50
print(text[start:end])
