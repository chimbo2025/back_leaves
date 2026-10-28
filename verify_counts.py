with open(r"E:\Dev\Zee\backend-leave\dpp_leave_fixed.sql", encoding="utf-8") as f:
    text = f.read()

dup = text.count("PRIMARY KEY (`id`)  PRIMARY KEY")
standalone_close = text.count("\n) ENGINE=InnoDB")
# Actually check for stray lines that are only ")" followed by nothing or ENGINE
stray = sum(1 for line in text.split("\n") if line.strip() == ")" and "ENGINE" not in line)
add_pk = text.count("ADD PRIMARY KEY")
print("Dup PRIMARY KEY (`id`)  PRIMARY KEY:", dup)
print("Standalone close ) lines (should be 0):", stray)
print("ADD PRIMARY KEY occurrences:", add_pk)
