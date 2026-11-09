#!/usr/bin/env python3
"""dataset.json -> schema.json (read by setup_vtiger.php): fields, picklist values, groups, filters, tags."""
import json, sys, collections, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ss_schema import BLOCKS, cf_name
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data"))
from fa_labels import LEVEL as LEVEL_FA

MAJOR_FILTER_MIN = 10   # one saved filter per major with at least this many students


def main(dataset, out):
    d = json.load(open(dataset, encoding="utf-8"))
    cs = d["contacts"]
    vals = collections.defaultdict(collections.Counter)
    for c in cs:
        for k in ("level", "university", "major", "nationality", "gender", "student_status"):
            v = c["fields"].get(k)
            if v:
                vals[k][v] += 1
    blocks = []
    for label, fields in BLOCKS:
        fl = []
        for key, flabel, kind in fields:
            e = dict(name=cf_name(key), key=key, label=flabel, kind=kind)
            if kind == "picklist":
                e["values"] = sorted(vals[key]) or ["-"]
            fl.append(e)
        blocks.append(dict(label=label, fields=fl))
    groups = [dict(name=f"مقطع - {LEVEL_FA.get(l, l)}", old=f"SS Level - {l}", level=l, desc=f"دانشجویان مقطع {LEVEL_FA.get(l, l)}")
              for l in sorted(vals["level"])]
    groups.append(dict(name="مقطع - نامشخص", old="SS Level - Unknown", level="", desc="مخاطبین بدون مقطع مشخص (فهرست‌های فقط شماره تلفن و ...)"))
    filters = []
    for l in sorted(vals["level"]):
        filters.append(dict(name=f"مقطع: {LEVEL_FA.get(l, l)}", old=f"SS Level: {l}", field=cf_name("level"), comparator="e", value=l))
    for u, n in vals["university"].most_common():
        filters.append(dict(name=f"دانشگاه: {u}"[:50], old=f"SS University: {u}"[:50], field=cf_name("university"), comparator="e", value=u))
    for m, n in vals["major"].most_common():
        if n >= MAJOR_FILTER_MIN:
            filters.append(dict(name=f"رشته: {m}"[:50], old=f"SS Major: {m}"[:50], field=cf_name("major"), comparator="e", value=m))
    for g in d["picklists"]["source_groups"]:
        filters.append(dict(name=f"فهرست: {g}"[:50], field=cf_name("source_groups"), comparator="c", value=g))
    json.dump(dict(blocks=blocks, groups=groups, filters=filters,
                   list_columns=[cf_name(k) for k in ("passport", "level", "university", "major", "student_status")]),
              open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"schema: {sum(len(b['fields']) for b in blocks)} fields, {len(groups)} groups, {len(filters)} filters")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
