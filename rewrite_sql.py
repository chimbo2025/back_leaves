import re, os

src = r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql"
dst = r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql"

with open(src, encoding="utf-8") as f:
    text = f.read()

# Step 1: Extract PK from bottom ALTER TABLE ... ADD PRIMARY KEY (...)
# Pattern matches ALTER TABLE `table` followed by lines until semicolon
pk_map = {}
alter_blocks = {}
# Find all ALTER TABLE blocks
for m in re.finditer(r"ALTER TABLE `([^`]+)`\n(.*?);", text, re.DOTALL):
    table = m.group(1)
    block = m.group(2)
    alter_blocks[table] = block
    pk_m = re.search(r"ADD PRIMARY KEY \(([^)]+)\)", block)
    if pk_m:
        pk_map[table] = pk_m.group(1).strip()

# Step 2: For each table with PK, modify CREATE TABLE block to add PRIMARY KEY inline
# We need to find each CREATE TABLE `table` ... ) ENGINE=...;
# Insert PRIMARY KEY before the closing ) that precedes ENGINE=
changed_tables = []

for table in sorted(pk_map):
    pk_expr = pk_map[table]
    pattern = r"(CREATE TABLE `" + re.escape(table) + r"`.*?)(\n\))\s*(ENGINE=[^;]+;)"
    new_text, count = re.subn(pattern, r"\1  PRIMARY KEY (" + pk_expr + r"),\2\n) \3", text, count=1, flags=re.DOTALL)
    if count == 1:
        text = new_text
        changed_tables.append(table)

# Step 3: Remove ADD PRIMARY KEY (...) lines from ALTER blocks (keep other lines)
# For each table with PK, find its ALTER block in new text and remove the line
for table in sorted(pk_map):
    # We need to remove the line containing ADD PRIMARY KEY for this table inside its ALTER block
    # We can use regex with multiline to match within the block
    # Pattern: ALTER TABLE `table`\n ... ADD PRIMARY KEY (...),\n ...
    block_start = "ALTER TABLE `" + table + "`"
    # Find start index
    idx = text.find(block_start)
    if idx == -1:
        continue
    # Find end of block (next semicolon after ALTER start; but careful with multiple blocks)
    # Since we just need to remove the ADD PRIMARY KEY line, we can do a targeted replace within the block region
    # Extract region from idx to next ";\n" that ends the block (before next ALTER or end)
    end_idx = text.find(";\n", idx)
    # Actually block ends at first semicolon after the key lines; let's find the first "\n;\n" after idx
    # Better: find the semi after the last ADD KEY, which is at end of block
    # Just replace the specific line occurrence in the region
    region = text[idx:end_idx+1]
    # Remove line ADD PRIMARY KEY (...),
    cleaned_region = re.sub(r"\n?\s*ADD PRIMARY KEY \([^)]+\),?", "", region)
    text = text[:idx] + cleaned_region + text[end_idx+1:]

with open(dst, "w", encoding="utf-8") as f:
    f.write(text)

print("Output:", dst)
print("Tables changed (inline PK added):", ", ".join(changed_tables))
print("Count:", len(changed_tables))
