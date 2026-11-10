#!/usr/bin/env python3
"""SQL that adds the derived contact details to contacts that are already imported (matched by crmid).
   python3 apply_details.py dataset.json import_state.json > details.sql
Typed data is never overwritten: every column is only filled when empty, except the two computed indicators
(profile completeness / missing information), which are always refreshed."""
import json, sys

def q(s): return "'" + str(s).replace("\\", "\\\\").replace("'", "\\'") + "'"

ADDRESS = ("mailingcity", "mailingcountry", "othercity", "othercountry")      # vtiger_contactaddress
DETAILS = ("title", "otherphone", "mobile")                                     # vtiger_contactdetails
CF_FILL = ("country_source", "whatsapp", "whatsapp_link", "mobile_operator")    # vtiger_contactscf (fill empty)
CF_ALWAYS = ("profile_completeness", "missing_info")                            # vtiger_contactscf (computed)

ds, state = (json.load(open(p, encoding="utf-8")) for p in sys.argv[1:3])
out, n = ["SET NAMES utf8;", "START TRANSACTION;"], 0
empty = lambda col: f"({col} IS NULL OR {col}='')"
for c in ds["contacts"]:
    wsid = state["contacts"].get(str(c["_id"]))
    if not wsid:
        continue
    cid = int(wsid.split("x")[1])
    for col in ADDRESS:
        if c.get(col):
            out.append(f"UPDATE vtiger_contactaddress SET {col}={q(c[col])} WHERE contactaddressid={cid} AND {empty(col)};"); n += 1
    for col in DETAILS:
        if c.get(col):
            out.append(f"UPDATE vtiger_contactdetails SET {col}={q(c[col])} WHERE contactid={cid} AND {empty(col)};"); n += 1
    for key in CF_FILL:
        v = c["fields"].get(key, "")
        if v:
            col = "cf_ss_" + key
            out.append(f"UPDATE vtiger_contactscf SET {col}={q(v)} WHERE contactid={cid} AND {empty(col)};"); n += 1
    sets = ", ".join(f"cf_ss_{k}={q(c['fields'].get(k, ''))}" for k in CF_ALWAYS)
    out.append(f"UPDATE vtiger_contactscf SET {sets} WHERE contactid={cid};"); n += 1
out.append("COMMIT;")
print("\n".join(out))
print(f"-- {n} updates", file=sys.stderr)
