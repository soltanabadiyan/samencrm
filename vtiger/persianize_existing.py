#!/usr/bin/env python3
"""
One-time migration of an instance imported with English labels (2026-11-10) to the Persian labels of
data/fa_labels.py. Emits SQL on stdout; run it with mariadb inside the db container (run_all.sh does this).

  python3 persianize_existing.py OLD_dataset.json NEW_dataset.json import_state.json > persianize.sql

Renames groups, saved filters (+ their rule values), tags; rewrites per record (matched by crmid, and only
where the stored value is still the old one, so manual edits are kept): contact "Source Lists" field and
description, task/event subject + label + description.
"""
import json, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data"))
import fa_labels as FA

def q(s): return "'" + str(s).replace("\\", "\\\\").replace("'", "\\'") + "'"

old, new, state = (json.load(open(p, encoding="utf-8")) for p in sys.argv[1:4])
out = ["SET NAMES utf8;", "START TRANSACTION;"]
# groups
for lv, fa in FA.LEVEL.items():
    on = f"SS Level - {lv or 'Unknown'}"
    out.append(f"UPDATE vtiger_groups SET groupname={q('مقطع - ' + fa)}, description={q('مشتریان مقطع ' + fa)} WHERE groupname={q(on)};")
# filters
lv_set = {c['fields']['level'] for c in old['contacts'] if c['fields']['level']}
for lv in lv_set:
    out.append(f"UPDATE vtiger_customview SET viewname={q('مقطع: ' + FA.LEVEL.get(lv, lv))} WHERE entitytype='Contacts' AND viewname={q('SS Level: ' + lv)};")
for u in {c['fields']['university'] for c in old['contacts'] if c['fields']['university']}:
    out.append(f"UPDATE vtiger_customview SET viewname={q(('دانشگاه: ' + u)[:50])} WHERE entitytype='Contacts' AND viewname={q(('SS University: ' + u)[:50])};")
for m in {c['fields']['major'] for c in old['contacts'] if c['fields']['major']}:
    out.append(f"UPDATE vtiger_customview SET viewname={q(('رشته: ' + m)[:50])} WHERE entitytype='Contacts' AND viewname={q(('SS Major: ' + m)[:50])};")
for g in old['picklists']['source_groups']:
    gfa = FA.source_groups(g)
    oname, nname = ('SS List: ' + g)[:50], ('فهرست: ' + gfa)[:50]
    out.append(f"UPDATE vtiger_cvadvfilter f JOIN vtiger_customview v ON v.cvid=f.cvid SET f.value={q(gfa)} "
               f"WHERE v.entitytype='Contacts' AND v.viewname={q(oname)} AND f.columnname LIKE '%cf_ss_source_groups%';")
    out.append(f"UPDATE vtiger_customview SET viewname={q(nname)} WHERE entitytype='Contacts' AND viewname={q(oname)};")
# tags
for lv, fa in FA.LEVEL.items():
    out.append(f"UPDATE vtiger_freetags SET tag={q('مقطع: ' + fa)}, raw_tag={q('مقطع: ' + fa)} WHERE tag={q('Level: ' + (lv or 'Unknown'))};")
out.append("UPDATE vtiger_freetags SET tag=CONCAT('رشته: ', SUBSTRING(tag, 8)), raw_tag=CONCAT('رشته: ', SUBSTRING(raw_tag, 8)) WHERE tag LIKE 'Major: %';")
# contacts
oc = {c['_id']: c for c in old['contacts']}
n = 0
for c in new['contacts']:
    wsid = state['contacts'].get(str(c['_id']))
    o = oc.get(c['_id'])
    if not wsid or not o:
        continue
    cid = int(wsid.split('x')[1])
    if o['fields']['source_groups'] != c['fields']['source_groups']:
        out.append(f"UPDATE vtiger_contactscf SET cf_ss_source_groups={q(c['fields']['source_groups'])} WHERE contactid={cid} AND cf_ss_source_groups={q(o['fields']['source_groups'])};"); n += 1
    if o['description'] != c['description']:
        out.append(f"UPDATE vtiger_crmentity SET description={q(c['description'])} WHERE crmid={cid} AND description={q(o['description'])};"); n += 1
# activities
def act(kind, olds, news, desc_fn):
    global n
    for i, (o, nw) in enumerate(zip(olds, news)):
        wsid = state[kind].get(str(i))
        if not wsid:
            continue
        aid = int(wsid.split('x')[1])
        os_, ns = o['subject'][:100], nw['subject'][:100]
        if os_ != ns:
            out.append(f"UPDATE vtiger_activity SET subject={q(ns)} WHERE activityid={aid} AND subject={q(os_)};")
            out.append(f"UPDATE vtiger_crmentity SET label={q(ns)}, description={q(desc_fn(nw))} WHERE crmid={aid} AND label={q(os_)};")
            n += 2
act('tasks', old['tasks'], new['tasks'], lambda t: f"وارد شده از ستون «{t['field']}» فایل اکسل: {t['value']}")
act('events', old['events'], new['events'], lambda e: f"رویداد وارد شده ({e['kind']}): {e['value']}. تاریخ = تاریخ ورود اطلاعات (فایل‌های منبع تاریخ ندارند).")
out.append("COMMIT;")
print("\n".join(out))
print(f"-- {n} record updates", file=sys.stderr)
