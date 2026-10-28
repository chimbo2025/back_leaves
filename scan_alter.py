with open(r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql", encoding="utf-8") as f:
    text = f.read()

# Find bottom ALTER TABLE blocks
lines = text.split("\n")
for i, line in enumerate(lines):
    if "ALTER TABLE" in line:
        # print next 10 lines
        for j in range(i, min(i+15, len(lines))):
            print(f"{j+1}: {lines[j]}")
        print("---")
