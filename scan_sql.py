with open(r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql", encoding="utf-8") as f:
    text = f.read()
print("size:", len(text))
print("CREATE TABLE count:", text.count("CREATE TABLE"))
