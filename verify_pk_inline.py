with open(r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql", encoding="utf-8") as f:
    text = f.read()
for tbl in ["position_default_roles", "leave_request_dates", "role_permissions", "cache", "password_reset_tokens", "approval_actions", "audit_logs"]:
    # find CREATE TABLE block
    start = text.find("CREATE TABLE `" + tbl + "`")
    if start == -1:
        print(tbl, "CREATE MISSING")
        continue
    # get first 300 chars after start
    block_start = text[start:start+600]
    # find PRIMARY KEY line inside
    pk_line = ""
    for line in block_start.split("\n"):
        if "PRIMARY KEY" in line:
            pk_line = line.strip()
            break
    # check ALTER block
    alter_start = text.find("ALTER TABLE `" + tbl + "`")
    alter_has_pk = False
    if alter_start != -1:
        alter_region = text[alter_start:alter_start+400]
        alter_has_pk = "ADD PRIMARY KEY" in alter_region
    print(f"{tbl}: CREATE PK={pk_line} | ALTER ADD PRIMARY KEY={alter_has_pk}")
