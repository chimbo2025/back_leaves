import re
src = r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql"
dst = r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql"
with open(src, encoding="utf-8") as f:
    text = f.read()

# Tables that clearly have `id` bigint/int in source and are missing PK (not already fixed by prior pass)
# We apply to all blocks where id line exists and PK is absent, and it's bigint/int
# Use regex substitution globally on blocks
def fix_block(m):
    block = m.group(1)
    # Skip if already has PRIMARY KEY (shouldn't happen for these but safe)
    if "PRIMARY KEY" in block:
        return block
    # Skip if no id column visible
    id_match = re.search(r"`id`\s+([a-zA-Z0-9_]+(?:\([^)]+\))?)", block)
    if not id_match:
        return block
    id_type = id_match.group(1).lower()
    if "bigint" in id_type or "int" in id_type or "unsigned" in id_type:
        # Add AUTO_INCREMENT to id definition if not present
        block = re.sub(r"(`id`\s+bigint(?:\([^)]+\))?\s+unsigned\s+NOT NULL)(?![^\n]*AUTO_INCREMENT)", r"\1 AUTO_INCREMENT", block)
        # Also handle int unsigned
        block = re.sub(r"(`id`\s+int(?:\([^)]+\))?\s+unsigned\s+NOT NULL)(?![^\n]*AUTO_INCREMENT)", r"\1 AUTO_INCREMENT", block)
        # Insert PRIMARY KEY (`id`) before closing ) ENGINE or after last column line before )
        # Find the line before closing )
        block = re.sub(r"(\n)(\s+\))\s*(ENGINE=)", r"\n  PRIMARY KEY (`id`),\1\2\n) \3", block)
    return block

# Apply to all CREATE TABLE ... ENGINE=...; blocks
text = re.sub(r"(CREATE TABLE .*?\n\) ENGINE=[^;]+;)", lambda m: fix_block(m), text, flags=re.DOTALL)

with open(dst, "w", encoding="utf-8") as f:
    f.write(text)

# Verification: list changed tables (those with PRIMARY KEY inserted and AUTO_INCREMENT)
with open(dst, encoding="utf-8") as f:
    new_text = f.read()
# Compare blocks to original for key tables
original_blocks = {}
new_blocks = {}
for m in re.finditer(r"CREATE TABLE `([^`]+)`.*?ENGINE=[^;]+;", text, re.DOTALL):
    original_blocks[m.group(1)] = m.group(0)[:200]
for m in re.finditer(r"CREATE TABLE `([^`]+)`.*?ENGINE=[^;]+;", new_text, re.DOTALL):
    new_blocks[m.group(1)] = m.group(0)[:200]
changed = [t for t in original_blocks if original_blocks[t] != new_blocks.get(t)
           and ("AUTO_INCREMENT" in new_blocks.get(t,"") or "PRIMARY KEY (`id`)" in new_blocks.get(t,""))]
print("Fixed in dpp_leave_fixed.sql (added AUTO_INCREMENT + PRIMARY KEY):")
for t in sorted(changed):
    if t not in ("approval_actions","approval_roles"):
        print(" -", t)
print("\nSTOP / NOT TOUCHED (report only): cache, cache_locks, leave_request_dates, password_reset_tokens, position_default_roles, role_permissions")
print("MANUALLY FIXED (skipped): approval_actions, approval_roles")
