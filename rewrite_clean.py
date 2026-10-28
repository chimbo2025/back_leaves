import re
src = r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql"
dst = r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql"

with open(src, encoding="utf-8") as f:
    text = f.read()

# Extract PK from ALTER blocks
pk_map = {}
for m in re.finditer(r"ALTER TABLE `([^`]+)`\n(.*?);", text, re.DOTALL):
    table = m.group(1)
    block = m.group(2)
    pk_m = re.search(r"ADD PRIMARY KEY \(([^)]+)\)", block)
    if pk_m:
        pk_map[table] = pk_m.group(1).strip()

# For each table with PK, fix CREATE TABLE inline and strip ADD PRIMARY KEY from ALTER
changed = []
for table in sorted(pk_map):
    pk_expr = pk_map[table]
    # Find CREATE TABLE block
    start = text.find(f"CREATE TABLE `{table}`")
    if start == -1:
        continue
    # Find the line with `) ENGINE=InnoDB` that closes this table
    # Search forward from start for the first `) ENGINE=InnoDB DEFAULT CHARSET=`
    close_idx = text.find(") ENGINE=InnoDB DEFAULT CHARSET=", start)
    if close_idx == -1:
        continue
    # The line before close_idx may be the last column line or a previous line
    # Let's split around close_idx to see previous lines
    before = text[:close_idx]
    after = text[close_idx:]
    # The previous line ends at the last newline before close_idx
    prev_newline = before.rfind("\n", 0, len(before)-1)
    last_col_line = before[prev_newline+1:]
    # Remove any trailing whitespace from last_col_line
    last_col_line = last_col_line.rstrip()
    # Ensure last_col_line ends with a comma
    if not last_col_line.endswith(","):
        last_col_line += ","
    # Replace before section: keep everything before last newline, insert last_col_line + newline + PK line + newline, then closing ) line
    new_before = before[:prev_newline+1] + last_col_line + "\n  PRIMARY KEY (" + pk_expr + "),\n"
    text = new_before + after
    changed.append(table)

# Now remove ADD PRIMARY KEY lines from ALTER blocks
# Use regex to find each ALTER block and strip only ADD PRIMARY KEY line
# Process from end to start to avoid index shifts
matches = list(re.finditer(r"ALTER TABLE `([^`]+)`\n(.*?)\n(?=ALTER TABLE|CREATE TABLE|INSERT INTO|-- Indexes)", text, re.DOTALL))
# Better: split text by lines and process in place
lines = text.split("\n")
new_lines = []
i = 0
while i < len(lines):
    line = lines[i]
    if line.startswith("ALTER TABLE `"):
        # Gather block until line that starts with ";" (end of ALTER) or empty/comment before next ALTER
        block_lines = [line]
        j = i + 1
        while j < len(lines):
            block_lines.append(lines[j])
            if lines[j].strip().startswith(";") or (lines[j].strip() == ";"):
                # end of ALTER statement (some ALTERs end with line containing only ";" after ADD lines?)
                # Actually from original, ALTER ends with a line that contains `;` at end of last ADD line.
                # The last line of ALTER block has `;` at its end (e.g., `ADD KEY ...;`)
                if lines[j].strip().endswith(";"):
                    break
            j += 1
        # Rebuild block without ADD PRIMARY KEY lines
        cleaned = []
        for bl in block_lines:
            if "ADD PRIMARY KEY" in bl:
                continue
            cleaned.append(bl)
        new_lines.extend(cleaned)
        i = j + 1
    else:
        new_lines.append(line)
        i += 1
text = "\n".join(new_lines)

with open(dst, "w", encoding="utf-8") as f:
    f.write(text)

print("Fixed:", dst)
print("Tables:", ", ".join(sorted(changed)))
print("Count:", len(changed))
