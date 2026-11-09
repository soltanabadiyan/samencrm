#!/usr/bin/env python3
"""SQL to fill city/country (standard address fields) + "Country Source" on contacts that are already imported.
   python3 apply_location.py dataset.json import_state.json > location.sql
Only empty values are filled, so anything typed in the CRM is kept."""
import json, sys

def q(s): return "'" + str(s).replace("\\", "\\\\").replace("'", "\\'") + "'"

ds, state = (json.load(open(p, encoding="utf-8")) for p in sys.argv[1:3])
out, n = ["SET NAMES utf8;", "START TRANSACTION;"], 0
for c in ds["contacts"]:
    wsid = state["contacts"].get(str(c["_id"]))
    if not wsid:
        continue
    cid = int(wsid.split("x")[1])
    for col in ("mailingcity", "mailingcountry", "othercity", "othercountry"):
        v = c.get(col, "")
        if v:
            out.append(f"UPDATE vtiger_contactaddress SET {col}={q(v)} WHERE contactaddressid={cid} AND ({col} IS NULL OR {col}='');")
            n += 1
    src = c["fields"].get("country_source", "")
    if src:
        out.append(f"UPDATE vtiger_contactscf SET cf_ss_country_source={q(src)} WHERE contactid={cid} AND (cf_ss_country_source IS NULL OR cf_ss_country_source='');")
        n += 1
out.append("COMMIT;")
print("\n".join(out))
print(f"-- {n} updates", file=sys.stderr)
