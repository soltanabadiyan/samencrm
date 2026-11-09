#!/usr/bin/env python3
"""en_us.json -> strings.json: unique English values in first-seen (module) order, with one sample key/file for context."""
import json, sys
src, out = sys.argv[1], sys.argv[2]
d = json.load(open(src, encoding="utf-8"))
seen = {}
for f in sorted(d, key=lambda f: (f != "Vtiger.php", f.count("/"), f)):   # core Vtiger.php first
    for var, kv in d[f].items():
        if not isinstance(kv, dict):
            continue
        for k, v in kv.items():
            if isinstance(v, str) and v.strip() and v not in seen:
                seen[v] = {"id": len(seen), "en": v, "key": k, "file": f}
json.dump(list(seen.values()), open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=0)
print(len(seen), "unique strings ->", out)
