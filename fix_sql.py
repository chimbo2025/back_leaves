import re, os

src = r"C:\Users\HAMINASS GROUP\Downloads\dpp_leave.sql"
dst = r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql"

with open(src, encoding="utf-8") as f:
    text = f.read()

# Split by CREATE TABLE ... ENGINE=...; blocks, preserving order
# We'll process with regex that captures the full block
pattern = re.compile(r"(CREATE TABLE .*?\n\) ENGINE=[^;]+;)", re.DOTALL)

blocks = list(pattern.finditer(text))
changed = []
fixed_text = text

# Build a mapping of table name -> original block -> new block
# We apply changes to fixed_text from end to start to preserve indices

def extract_table_name(block_text):
    m = re.search(r"CREATE TABLE\s+`([^`]+)`", block_text)
    return m.group(1) if m else None

def has_pk(block_text):
    # If PRIMARY KEY appears before closing ) ENGINE=
    # Exclude cases where PRIMARY KEY is inside a comment? ignore.
    # Check for PRIMARY KEY ( or PRIMARY KEY `
    return "PRIMARY KEY" in block_text

def get_id_col_type(block_text):
    m = re.search(r"`id`\s+([a-zA-Z0-9_]+(?:\([^)]+\))?)\s+NOT NULL", block_text)
    if m:
        return m.group(1).lower()
    # Try varchar/char
    m = re.search(r"`id`\s+(varchar|char)(?:\([0-9]+\))?\s+NOT NULL", block_text, re.I)
    if m:
        return m.group(1).lower() + (re.search(r"\((\d+)\)", block_text).group(1) if re.search(r"\((\d+)\)", block_text) else "")
    return None

# First pass: collect names and determine needed changes
changes = {}
for m in reversed(blocks):
    block = m.group(1)
    name = extract_table_name(block)
    if name is None:
        continue
    # Skip already manually fixed tables (user said do not touch)
    if name in ("approval_actions", "approval_roles"):
        # Verify they have PK; if yes skip; if not report
        if not has_pk(block):
            changed.append((name, "MANUALLY FIXED – MISSING PK (should already be fixed)"))
        else:
            # already fixed, don't change
            pass
        continue
    if has_pk(block):
        # Already has PK
        continue
    # Missing PK - determine fix
    id_type_raw = get_id_col_type(block)
    if id_type_raw:
        if "bigint" in id_type_raw or "int" in id_type_raw:
            # id bigint -> AUTO_INCREMENT + PRIMARY KEY (id)
            changes[name] = ("id bigint -> AUTO_INCREMENT + PRIMARY KEY (`id`)", "add AUTO_INCREMENT, PRIMARY KEY")
        elif "varchar" in id_type_raw or "char" in id_type_raw:
            changes[name] = ("id varchar/char(36) -> PRIMARY KEY (`id`)", "add PRIMARY KEY")
        else:
            changes[name] = (f"id type {id_type_raw} unclear -> stop/report", "unknown")
    else:
        # Check for composite key evidence in block (e.g., KEY with multiple cols) or migration
        # Simple: if block contains `id` but no PK and id col not clearly typed -> report
        if "`id`" in block:
            # Try to locate id definition more loosely
            match = re.search(r"`id`\s+([a-zA-Z0-9_]+(?:\([^)]+\))?)", block)
            if match:
                changes[name] = (f"id type {match.group(1)} -> need manual check", "unknown")
            else:
                changes[name] = ("`id` present but type not parsed -> stop/report", "unknown")
        else:
            changes[name] = ("no clear `id` column -> stop/report", "unknown")

# Apply modifications
# For simplicity, replace within full text using regex per table with unique block match (first occurrence after name)
# Since names are unique, we can do per-table replacement by finding block starting with CREATE TABLE `name`
def replace_block_for_table(text, table_name, new_block):
    # Find block start with that table name
    start = text.find(f"CREATE TABLE `{table_name}`")
    if start == -1:
        return text
    # Find end of block (next \n) ENGINE=...; followed by newline)
    # The block ends at the first ';' after ENGINE= that ends the statement; usually followed by \n
    end = text.find(";", start)
    # We need to include possible comments after ;? The block format is usually ... ENGINE=...;\n
    # Find the semicolon that closes the CREATE TABLE statement (before any /*!...*/ after?)
    # Search for pattern ENGINE=InnoDB...; possibly with newline after
    m = re.search(r"(ENGINE=[^;]+;)(\n|\r\n?)", text[start:])
    if m:
        end = start + m.end() - (len(m.group(2)) if m.group(2) else 0)
    # Replace from start to end with new_block + same ending
    return text[:start] + new_block + text[end:]

# For each change that is bigint or varchar, apply
for name in changes:
    info, detail = changes[name]
    if "unknown" in detail or "stop/report" in detail:
        # Do not modify; just leave as-is and report
        continue
    # Find block
    start = text.find(f"CREATE TABLE `{name}`")
    if start == -1:
        continue
    # Extract full block text
    m = re.search(r"(CREATE TABLE `" + re.escape(name) + r"`.*?ENGINE=[^;]+;)", text[start:], re.DOTALL)
    if not m:
        continue
    block = m.group(1)
    # Modify block
    # If bigint/int: add AUTO_INCREMENT to `id` definition; add PRIMARY KEY (`id`) before ENGINE line or before closing `)`
    # Modify id definition line
    new_block = re.sub(r"(`id`\s+bigint\([^)]+\)\s+unsigned\s+NOT NULL)(?!\s+AUTO_INCREMENT)", r"\1 AUTO_INCREMENT", block)
    # Also handle cases where it's int instead of bigint
    new_block = re.sub(r"(`id`\s+int\([^)]+\)\s+unsigned\s+NOT NULL)(?!\s+AUTO_INCREMENT)", r"\1 AUTO_INCREMENT", new_block)
    # If varchar/char: just add PRIMARY KEY; do NOT add AUTO_INCREMENT
    if "varchar" in info or "char" in info:
        # Insert PRIMARY KEY (`id`) before closing ) ENGINE
        new_block = re.sub(r"(\n\))\s+(ENGINE=)", r"\n  PRIMARY KEY (`id`),\1\n) \2", new_block)
    else:
        # bigint/int: insert PRIMARY KEY too
        new_block = re.sub(r"(\n\))\s+(ENGINE=)", r"\n  PRIMARY KEY (`id`),\1\n) \2", new_block)
    # Apply to text (only first occurrence of this block; names unique)
    text = replace_block_for_table(text, name, new_block)

with open(dst, "w", encoding="utf-8") as f:
    f.write(text)

# Report
print("OUTPUT FILE:", dst)
print("\nCHANGED TABLES (reason):")
for name in sorted(changes):
    info, detail = changes[name]
    if "unknown" not in detail and "stop/report" not in detail:
        status = "FIXED" if detail != "unknown" else "SKIPPED"
        print(f"- {name}: {info} [{status}]")
print("\nSTOP/REPORT (do not modify):")
for name in sorted(changes):
    info, detail = changes[name]
    if "unknown" in detail or "stop/report" in detail:
        print(f"- {name}: {info} -> STOP")
print("\nMANUALLY FIXED (skipped): approval_actions, approval_roles")
