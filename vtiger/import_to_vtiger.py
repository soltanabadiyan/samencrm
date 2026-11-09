#!/usr/bin/env python3
"""
Import dataset.json into Vtiger through the standard REST webservice (stdlib only).

  python3 import_to_vtiger.py --url http://127.0.0.1:9081 --user admin --accesskey XXXX \
      --dataset dataset.json --groups groups.json --state import_state.json

Resumable: contact/task/event ids already created are kept in --state and skipped on re-run.
Failures are appended to import_errors.jsonl (never abort the run).
"""
import argparse
import datetime
import hashlib
import json
import os
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ss_schema import all_keys, cf_name  # noqa: E402


class VT:
    def __init__(self, base, user, accesskey):
        self.ws = base.rstrip("/") + "/webservice.php"
        self.user, self.key = user, accesskey
        self.session = None
        self.user_id = None
        self.lock = threading.Lock()
        self.login()

    def _req(self, params, post=False):
        data = urllib.parse.urlencode(params).encode()
        last = None
        for attempt in range(4):
            try:
                if post:
                    rq = urllib.request.Request(self.ws, data=data)
                else:
                    rq = urllib.request.Request(self.ws + "?" + data.decode())
                with urllib.request.urlopen(rq, timeout=60) as r:
                    return json.loads(r.read().decode("utf-8"))
            except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
                last = e
                time.sleep(1.5 * (attempt + 1))
        raise RuntimeError(f"HTTP failure: {last}")

    def login(self):
        ch = self._req({"operation": "getchallenge", "username": self.user})
        if not ch.get("success"):
            raise RuntimeError(f"getchallenge failed: {ch}")
        token = ch["result"]["token"]
        res = self._req({"operation": "login", "username": self.user,
                         "accessKey": hashlib.md5((token + self.key).encode()).hexdigest()}, post=True)
        if not res.get("success"):
            raise RuntimeError(f"login failed: {res}")
        self.session = res["result"]["sessionName"]
        self.user_id = res["result"]["userId"]

    def create(self, module, element):
        for attempt in range(2):
            res = self._req({"operation": "create", "sessionName": self.session, "elementType": module,
                             "element": json.dumps(element, ensure_ascii=False)}, post=True)
            if res.get("success"):
                return res["result"]["id"]
            err = res.get("error", {})
            if err.get("code") in ("INVALID_SESSIONID", "AUTHENTICATION_REQUIRED") and attempt == 0:
                with self.lock:
                    self.login()
                continue
            raise RuntimeError(json.dumps(err, ensure_ascii=False))

    def query(self, q):
        res = self._req({"operation": "query", "sessionName": self.session, "query": q})
        if not res.get("success"):
            raise RuntimeError(json.dumps(res.get("error"), ensure_ascii=False))
        return res["result"]


def contact_element(c, admin_id, groups):
    F = c["fields"]
    owner = groups.get(F.get("level", ""), groups.get("")) or admin_id
    owner = owner if isinstance(owner, str) else f"20x{owner}"
    el = {
        "lastname": c["lastname"][:80],
        "firstname": (c.get("firstname") or "")[:40],
        "mobile": c.get("mobile", ""),
        "phone": c.get("phone", ""),
        "email": c.get("email", ""),
        "department": F.get("major", "")[:30],
        "description": c.get("description", ""),
        "assigned_user_id": owner,
    }
    for k in all_keys():
        v = F.get(k, "")
        if v != "":
            el[cf_name(k)] = v
    return {k: v for k, v in el.items() if v != ""}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", required=True)
    ap.add_argument("--user", default="admin")
    ap.add_argument("--accesskey", required=True)
    ap.add_argument("--dataset", required=True)
    ap.add_argument("--groups", help="groups.json {level: groupid}")
    ap.add_argument("--state", default="import_state.json")
    ap.add_argument("--errors", default="import_errors.jsonl")
    ap.add_argument("--workers", type=int, default=3)
    ap.add_argument("--limit", type=int, default=0, help="only first N contacts (test run)")
    ap.add_argument("--skip-activities", action="store_true")
    a = ap.parse_args()

    ds = json.load(open(a.dataset, encoding="utf-8"))
    groups = {}
    if a.groups and os.path.exists(a.groups):
        groups = {k: int(v) for k, v in json.load(open(a.groups)).items()}
    state = {"contacts": {}, "tasks": {}, "events": {}}
    if os.path.exists(a.state):
        state.update(json.load(open(a.state)))
    vt = VT(a.url, a.user, a.accesskey)
    print(f"logged in as {a.user} ({vt.user_id}); groups={groups}")

    lock = threading.Lock()
    counters = {"ok": 0, "fail": 0}
    err_fh = open(a.errors, "a", encoding="utf-8")

    def save():
        tmp = a.state + ".tmp"
        json.dump(state, open(tmp, "w"))
        os.replace(tmp, a.state)

    def do(kind, key, module, element):
        if str(key) in state[kind]:
            return
        try:
            crmid = vt.create(module, element)
        except Exception as e:  # noqa: BLE001
            with lock:
                counters["fail"] += 1
                err_fh.write(json.dumps({"kind": kind, "key": key, "error": str(e), "element": element}, ensure_ascii=False) + "\n")
                err_fh.flush()
            return
        with lock:
            state[kind][str(key)] = crmid
            counters["ok"] += 1
            if counters["ok"] % 100 == 0:
                save()
                try:  # a closed stdout (SSH dropped) must not count as a failed record
                    print(f"  ... {counters['ok']} created", flush=True)
                except OSError:
                    pass

    contacts = ds["contacts"][: a.limit] if a.limit else ds["contacts"]
    print(f"contacts to import: {len(contacts)}")
    with ThreadPoolExecutor(a.workers) as ex:
        for c in contacts:
            ex.submit(do, "contacts", c["_id"], "Contacts", contact_element(c, vt.user_id, groups))
    save()
    print(f"contacts done: ok={counters['ok']} fail={counters['fail']}")

    if a.skip_activities:
        return
    today = datetime.date.today()
    due = today + datetime.timedelta(days=7)
    wanted = {c["_id"] for c in contacts}
    c0 = dict(counters)
    with ThreadPoolExecutor(a.workers) as ex:
        for i, t in enumerate(ds["tasks"]):
            cid = state["contacts"].get(str(t["contact"]))
            if not cid or t["contact"] not in wanted:
                continue
            owner = vt.user_id
            el = {"subject": t["subject"][:100], "assigned_user_id": owner, "activitytype": "Task",
                  "taskstatus": t["status"], "taskpriority": "Medium", "contact_id": cid,
                  "date_start": today.isoformat(), "due_date": due.isoformat(),
                  "time_start": "09:00:00",
                  "description": f"وارد شده از ستون «{t['field']}» فایل اکسل: {t['value']}"}
            ex.submit(do, "tasks", i, "Calendar", el)
        for i, e in enumerate(ds["events"]):
            cid = state["contacts"].get(str(e["contact"]))
            if not cid or e["contact"] not in wanted:
                continue
            el = {"subject": e["subject"][:100], "assigned_user_id": vt.user_id, "activitytype": e["type"],
                  "eventstatus": e["status"], "contact_id": cid, "visibility": "Public",
                  "date_start": today.isoformat(), "time_start": "09:00:00",
                  "due_date": today.isoformat(), "time_end": "09:30:00",
                  "duration_hours": "0", "duration_minutes": "30",
                  "description": f"رویداد وارد شده ({e['kind']}): {e['value']}. تاریخ = تاریخ ورود اطلاعات (فایل‌های منبع تاریخ ندارند)."}
            ex.submit(do, "events", i, "Events", el)
    save()
    print(f"activities done: ok={counters['ok'] - c0['ok']} fail={counters['fail'] - c0['fail']}")
    print(f"TOTAL created contacts={len(state['contacts'])} tasks={len(state['tasks'])} events={len(state['events'])}; errors in {a.errors}")


if __name__ == "__main__":
    main()
