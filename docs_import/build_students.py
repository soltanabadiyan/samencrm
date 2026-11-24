#!/usr/bin/env python3
"""
Step 2 of the student-documents import: one record per student.

  python3 build_students.py <out_dir> [crm_contacts.tsv]
  reads  files.json, lists.json (extract.py), ocr/<md5>.json (ocr_worker.py / ocr_flat.py)
  writes students.json  – identity, passports, contact details, previous education, applications, documents, CRM match
         report.txt     – counts, merges, conflicts, what could not be assigned

Identity (no duplicates): passport number first (a person may hold an old and a renewed passport), then a file that is
byte-identical in two folders, then the same full name (3+ words; 2 words only inside one university), then phone.
Phones / e-mails that appear for 3+ different people are an agent's, not the student's: kept apart, never a key.
Nothing is guessed, except the level applied for when a folder does not state it: one above the highest degree among
the student's documents (marked "from documents").
"""
import collections, csv, datetime, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import common as c
import interpret as I
import build_dataset as bd

OUT = sys.argv[1]
CRM = sys.argv[2] if len(sys.argv) > 2 else ""
TODAY = datetime.date.today()
BATCH_GAP = 120            # seconds between two downloads of one batch

KIND_FA = {   # document type -> Persian label (Documents folder + tag)
    "passport": "پاسپورت", "photo": "عکس پرسنلی", "cv": "رزومه", "degree_certificate": "مدرک تحصیلی",
    "transcript": "ریزنمرات", "master_degree": "مدرک کارشناسی ارشد", "bachelor_degree": "مدرک کارشناسی", "phd_degree": "مدرک دکتری",
    "diploma": "دیپلم", "visa": "ویزا", "admission": "پذیرش", "final_admission": "پذیرش نهایی", "initial_admission": "پذیرش اولیه",
    "registration_certificate": "گواهی ثبت‌نام", "application_receipt": "رسید درخواست پذیرش", "application_form": "فرم درخواست",
    "application_form_sheet": "فرم درخواست", "recommendation": "توصیه‌نامه", "payment_receipt": "رسید پرداخت",
    "language_certificate": "مدرک زبان", "national_id": "مدرک هویتی", "proposal": "پروپوزال", "paper": "مقاله",
    "archive": "بسته مدارک (فشرده)", "arabic_document": "مدرک (عربی/فارسی)", "image": "تصویر", "unknown": "سایر مدارک",
    "list_workbook": "فهرست", "document": "سایر مدارک",
}
DEGREE_RANK = {"diploma": 1, "Diploma": 1, "Associate": 2, "bachelor": 3, "BSc": 3, "master": 4, "MSc": 4, "phd": 5, "PhD": 5}
NEXT_LEVEL = {1: "BSc", 2: "BSc", 3: "MSc", 4: "PhD", 5: "PhD"}


def pkey(p):
    return re.sub(r"[^A-Z0-9]", "", (p or "").upper())


def phone_key(p):
    d = re.sub(r"\D", "", p or "")
    return d[-10:] if len(d) >= 10 else ""


def iso_date(v):
    """'22/02/1990', '1987-04-07', '26/5/1988' -> ISO or ''"""
    v = (v or "").strip()
    m = re.fullmatch(r"(\d{4})-(\d{1,2})-(\d{1,2}).*", v)
    if m:
        y, mo, d = map(int, m.groups())
    else:
        m = re.fullmatch(r"(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})", v)
        if not m:
            return ""
        d, mo, y = map(int, m.groups())
    if not (1930 <= y <= 2015 and 1 <= mo <= 12 and 1 <= d <= 31):
        return ""
    return f"{y:04d}-{mo:02d}-{d:02d}"


def load():
    files = json.load(open(os.path.join(OUT, "files.json"), encoding="utf-8"))
    lists = json.load(open(os.path.join(OUT, "lists.json"), encoding="utf-8"))
    for f in files:
        p = os.path.join(OUT, "ocr", f["md5"] + ".json")
        f["ocr"] = json.load(open(p, encoding="utf-8")) if os.path.exists(p) else None
    return files, lists


def interpret_file(f):
    """final document type + what the page itself says"""
    kind = f.get("kind") or ""
    info = {}
    o = f["ocr"]
    if o and (o.get("lines") or o.get("boxes") is not None) and not kind:
        k, det = I.classify(o, f.get("name_kind", ""))
        kind = k
        if k == "passport":
            info["mrz"] = det
            info.update(I.visual_passport(o["lines"]))
        elif k == "cv":
            info["cv"] = I.cv_fields(o)
        elif k in ("degree_certificate", "transcript"):
            info["cert"] = dict(I.certificate_fields(o), **det)
    if not kind:
        kind = f.get("name_kind") or ("document" if f["ext"] in (".pdf", ".docx", ".doc") else "unknown")
    # a file name that states the type refines a generic reading ("ماستر.jpg" -> master's certificate)
    nk = f.get("name_kind", "")
    if nk in ("master_degree", "bachelor_degree", "phd_degree", "diploma") and kind in ("degree_certificate", "arabic_document", "unknown", "image", "transcript"):
        info.setdefault("cert", {})["level"] = {"master_degree": "master", "bachelor_degree": "bachelor", "phd_degree": "phd", "diploma": "diploma"}[nk]
        if kind != "transcript":
            kind = nk
    elif nk and kind in ("arabic_document", "unknown", "image", "document"):
        kind = nk
    if kind == "degree_certificate" and info.get("cert", {}).get("level") in ("master", "bachelor", "phd"):
        kind = info["cert"]["level"] + "_degree"
    f["kind"] = kind
    f["info"] = info


class UF:
    def __init__(self):
        self.p = {}

    def find(self, x):
        self.p.setdefault(x, x)
        while self.p[x] != x:
            self.p[x] = self.p[self.p[x]]
            x = self.p[x]
        return x

    def union(self, a, b):
        ra, rb = self.find(a), self.find(b)
        if ra != rb:
            self.p[max(ra, rb)] = min(ra, rb)


def main():
    files, lists = load()
    rep = []
    for f in files:
        interpret_file(f)

    # ---------------------------------------------------------------- units: a student folder, or a loose file that names its owner
    units = collections.defaultdict(list)
    unassigned = []
    for f in files:
        if f["kind"] == "list_workbook":
            continue
        ctx = f["ctx"]
        if "person" in ctx:
            units["D:" + ctx["person_dir"]].append(f)
        else:
            pp = file_passports(f)
            if pp:
                units["P:" + pp[0]].append(f)
            elif f.get("name_in_file"):
                units["N:" + c.name_key(f["name_in_file"]) + "|" + os.path.dirname(f["rel"])].append(f)
            else:
                unassigned.append(f)

    # loose scans that came in one batch with an identified document (downloaded seconds apart into the same folder,
    # exactly one person identified in the batch) belong to that person
    by_dir = collections.defaultdict(list)
    owner = {}
    for uid, fl in units.items():
        if uid[0] in "PN":
            for f in fl:
                owner[id(f)] = uid
                by_dir[os.path.dirname(f["rel"])].append(f)
    for f in unassigned:
        by_dir[os.path.dirname(f["rel"])].append(f)
    batched = 0
    still = []
    for d, fl in by_dir.items():
        fl.sort(key=lambda f: f.get("mtime", 0))
        batch = []
        for i, f in enumerate(fl + [None]):
            if f is not None and (not batch or f.get("mtime", 0) - batch[-1].get("mtime", 0) <= BATCH_GAP):
                batch.append(f); continue
            ids = {owner[id(x)] for x in batch if id(x) in owner}
            for x in batch:
                if id(x) in owner:
                    continue
                if len(ids) == 1 and x["ext"] in c.IMG + (".pdf",) and x["kind"] not in ("archive",):
                    x["by_batch"] = True
                    units[next(iter(ids))].append(x); batched += 1
                else:
                    still.append(x)
            batch = [f] if f is not None else []
    unassigned = still

    # ---------------------------------------------------------------- identity keys of a unit
    U = {}
    for uid, fl in units.items():
        pas = collections.Counter()
        for f in fl:
            for p in file_passports(f):
                pas[p] += 2 if f["info"].get("mrz", {}).get("mrz_valid") else 1
        name = ""
        if uid.startswith("D:"):
            name = fl[0]["ctx"]["person"]
        elif uid.startswith("N:"):
            name = fl[0]["name_in_file"]
        U[uid] = {"files": fl, "passports": pas, "name": name}

    # ---------------------------------------------------------------- merge units into persons
    uf = UF()
    by_pass, by_md5, by_name = collections.defaultdict(list), collections.defaultdict(set), collections.defaultdict(list)
    for uid, u in U.items():
        uf.find(uid)
        for p in u["passports"]:
            by_pass[p].append(uid)
        for f in u["files"]:
            if f["ext"] not in (".xlsx", ".xls"):
                by_md5[f["md5"]].add(uid)
        if u["name"]:
            by_name[c.name_key(u["name"])].append(uid)
    n_pass = n_md5 = n_name = 0
    for p, us in by_pass.items():
        for x in us[1:]:
            if uf.find(us[0]) != uf.find(x):
                uf.union(us[0], x); n_pass += 1
    for h, us in by_md5.items():
        us = sorted(us)
        if 2 <= len(us) <= 3:                              # a file copied into more than 3 folders is a template, not a link
            for x in us[1:]:
                if uf.find(us[0]) != uf.find(x):
                    uf.union(us[0], x); n_md5 += 1
    for k, us in by_name.items():
        words = len(k.split())
        if len(us) < 2 or words < 2:
            continue
        for x in us[1:]:
            a, b = uf.find(us[0]), uf.find(x)
            if a == b:
                continue
            pa = set(pp for uid in U if uf.find(uid) == a for pp in U[uid]["passports"])
            pb = set(pp for uid in U if uf.find(uid) == b for pp in U[uid]["passports"])
            if pa and pb and not (pa & pb):                # both have passports and they differ: different people (or a renewal – left apart, reported)
                rep.append(f"same name, different passports – kept apart: {k}  {sorted(pa)} / {sorted(pb)}")
                continue
            if words == 2:
                ua = {f["ctx"].get("university") for f in U[us[0]]["files"]}; ub = {f["ctx"].get("university") for f in U[x]["files"]}
                if not (ua & ub):
                    continue
            uf.union(us[0], x); n_name += 1
    groups = collections.defaultdict(list)
    for uid in U:
        groups[uf.find(uid)].append(uid)

    # ---------------------------------------------------------------- agent contacts (shared by 3+ people)
    cnt_phone, cnt_mail = collections.Counter(), collections.Counter()
    for g in groups.values():
        ph, em = set(), set()
        for uid in g:
            for f in U[uid]["files"]:
                a, b = file_contacts(f)
                ph |= a; em |= b
        cnt_phone.update(ph); cnt_mail.update(em)
    agent_phone = {k for k, n in cnt_phone.items() if n >= 3}
    agent_mail = {k for k, n in cnt_mail.items() if n >= 3}

    # ---------------------------------------------------------------- list rows by passport / name
    rows_by_pass = collections.defaultdict(list)
    for r in lists:
        if r.get("passport"):
            rows_by_pass[pkey(r["passport"])].append(r)

    # a list person found by full name (3+ words) when exactly one passport carries that name in the lists
    name_rows = collections.defaultdict(list)
    for r in lists:
        if r.get("passport") and (r.get("full_name") or r.get("surname")):
            k = c.name_key(" ".join(x for x in (r.get("full_name", ""), r.get("surname", "")) if x))
            if len(k.split()) >= 3:
                name_rows[k].append(r)
    rows_by_name = {k: v for k, v in name_rows.items() if len({bd.passport_key(pkey(r["passport"]))[0] for r in v}) == 1}

    students = []
    for root, g in groups.items():
        s = build_person([U[u] for u in g], g, agent_phone, agent_mail, rows_by_pass, rows_by_name)
        students.append(s)
    # people who are only in the list workbooks (no document folder): one record per passport, completed below if the
    # CRM does not have them yet
    have = {bd.passport_key(p["number"])[0] for st in students for p in st["passports"]}
    seen_list = set()
    list_people = []
    for p, rows in rows_by_pass.items():
        d = bd.passport_key(p)[0]
        if len(d) < 6 or not re.fullmatch(r"[A-Z]{0,2}\d{6,9}", p) or d in have or d in seen_list:
            continue
        seen_list.add(d)
        allrows = [r for q, rr in rows_by_pass.items() if bd.passport_key(q)[0] == d for r in rr]
        list_people.append(build_list_person(p, allrows))
    students += list_people
    students.sort(key=lambda s: s["name"])
    import hashlib
    used_sid = set()
    for st in students:                           # an id that survives re-runs: the passport, else the name + first folder
        if st["passports"]:
            sid = "P" + bd.passport_key(st["passports"][0]["number"])[0]
        else:
            sid = "N" + hashlib.md5((c.name_key(st["name"]) + "|" + (sorted(st["units"])[0] if st["units"] else "")).encode()).hexdigest()[:12]
        while sid in used_sid:
            sid += "x"
        used_sid.add(sid); st["sid"] = sid

    # ---------------------------------------------------------------- CRM match
    if CRM:
        match_crm(students, CRM, agent_phone, rep)

    json.dump(students, open(os.path.join(OUT, "students.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    json.dump([{"rel": f["rel"], "md5": f["md5"], "kind": f["kind"]} for f in unassigned],
              open(os.path.join(OUT, "unassigned.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)

    # ---------------------------------------------------------------- report
    have_ocr = sum(1 for f in files if f["ocr"] is not None)
    need_ocr = sum(1 for f in files if f["ext"] in c.IMG)
    L = []
    L.append(f"files {len(files)} (unique content {len({f['md5'] for f in files})}); scans read {have_ocr} (images in tree {need_ocr})")
    L.append(f"units {len(U)} -> students {len(students)}   merged by passport {n_pass}, by identical file {n_md5}, by name {n_name}")
    L.append(f"loose scans given to the person identified in the same download batch: {batched}")
    L.append(f"loose files not assignable to a person: {len(unassigned)}  " + str(collections.Counter(f['kind'] for f in unassigned).most_common(8)))
    L.append(f"people only in the list workbooks: {sum(1 for s in students if s.get('list_only'))}")
    L.append(f"with passport {sum(1 for s in students if s['passports'])}, birth date {sum(1 for s in students if s.get('birth_date'))}, "
             f"mobile {sum(1 for s in students if s.get('mobile'))}, email {sum(1 for s in students if s.get('email'))}, "
             f"university {sum(1 for s in students if s.get('university'))}, level {sum(1 for s in students if s.get('level'))}, "
             f"major {sum(1 for s in students if s.get('major'))}, previous degree {sum(1 for s in students if s.get('prev_level'))}")
    L.append(f"agent phones {len(agent_phone)} {sorted(agent_phone)[:6]}  agent e-mails {len(agent_mail)} {sorted(agent_mail)[:4]}")
    L.append("document types: " + str(collections.Counter(d['kind'] for s in students for d in s['documents']).most_common(30)))
    L.append("level source: " + str(collections.Counter(s.get('level_source', '-') for s in students)))
    L.append("stage: " + str(collections.Counter(s.get('stage', '-') for s in students)))
    if CRM:
        L.append("CRM match: " + str(collections.Counter(s['crm']['how'] for s in students)))
    L += [""] + rep
    open(os.path.join(OUT, "report.txt"), "w", encoding="utf-8").write("\n".join(L) + "\n")
    print("\n".join(L[:12]))


def file_passports(f):
    out = []
    m = f.get("info", {}).get("mrz", {})
    if m.get("passport") and m.get("mrz_valid") and re.fullmatch(r"[A-Z]{1,2}\d{6,9}", m["passport"]):
        out.append(m["passport"])                    # a number whose check digit fails was misread: never a key
    p = pkey(f.get("fields", {}).get("passport", ""))
    if re.fullmatch(r"[A-Z]{1,2}\d{6,9}", p) and p not in out:
        out.append(p)
    if f.get("kind") in ("registration_certificate", "final_admission", "initial_admission", "application_receipt", "cv", "visa", "document", "admission"):
        for p in f.get("fields", {}).get("passports_in_text", [])[:2]:
            if p not in out:
                out.append(p)
    return out


def file_contacts(f):
    ph, em = set(), set()
    fl = f.get("fields", {})
    for v in [fl.get("mobile", "")] + fl.get("phones_in_text", []) + [f.get("info", {}).get("cv", {}).get("phone", "")]:
        k = phone_key(v)
        if k:
            ph.add(k)
    for v in [fl.get("email", "")] + fl.get("emails_in_text", []) + [f.get("info", {}).get("cv", {}).get("email", "")]:
        if v and "@" in v:
            em.add(v.lower().strip())
    return ph, em


def term_rank(app):
    y = int(app.get("year") or 0)
    return y * 2 + (1 if app.get("term") == "مهر" else 0)      # بهمن 1404 (winter) comes before مهر 1405; within a year بهمن is the later month


BOGUS_NAME = re.compile(r"republic|iraq|passport|scan|document|whatsapp|screenshot|^\W*$", re.I)


def build_person(units, uids, agent_phone, agent_mail, rows_by_pass, rows_by_name):
    s = {"units": uids, "documents": [], "applications": [], "notes": []}
    files = []
    seen = set()
    for u in units:
        for f in u["files"]:
            if f["md5"] in seen:
                continue
            seen.add(f["md5"]); files.append(f)
    names = sorted({u["name"] for u in units if u["name"] and not BOGUS_NAME.search(u["name"])}, key=lambda n: (-len(n.split()), -len(n)))
    s["name"] = names[0] if names else ""
    s["name_variants"] = names[1:]
    s["flags"] = sorted({fl for u in units for f in u["files"] for fl in f["ctx"].get("flags", [])})

    # passports
    pas = {}
    for f in files:
        m = f["info"].get("mrz", {})
        for p in file_passports(f):
            e = pas.setdefault(p, {"number": p, "sources": 0, "valid": False})
            e["sources"] += 1
            if m.get("passport") == p:
                e["valid"] = e["valid"] or bool(m.get("mrz_valid"))
                for k in ("expiry", "birth_date", "sex", "nationality", "surname_latin", "given_latin"):
                    if m.get(k) and not e.get(k):
                        e[k] = m[k]
            if f["fields"].get("passport_issue") and pkey(f["fields"].get("passport", "")) == p:
                e["issue"] = f["fields"]["passport_issue"]
    unread = sorted({f["info"]["mrz"]["passport"] for f in files if f["info"].get("mrz", {}).get("passport") and not f["info"]["mrz"].get("mrz_valid")
                     and f["info"]["mrz"]["passport"] not in pas})
    s["passports_unverified"] = unread[:4]          # read from a scan but the check digit does not match: for a person to look at
    plist = sorted(pas.values(), key=lambda e: (e.get("expiry", ""), e["valid"], e["sources"]), reverse=True)
    s["passports"] = plist
    main_p = plist[0] if plist else {}

    def first(*vals):
        for v in vals:
            if v:
                return v
        return ""

    forms = [f["fields"] for f in files if f["kind"] in ("application_form", "application_form_sheet")]
    cvs = [f["info"].get("cv", {}) for f in files if f["info"].get("cv")]

    def form(k):
        for x in forms:
            if x.get(k):
                return x[k]
        return ""

    s["birth_date"] = first(main_p.get("birth_date"), *[p.get("birth_date") for p in plist], iso_date(form("birth_date")))
    s["gender"] = first(main_p.get("sex"), *[p.get("sex") for p in plist], form("gender"))
    s["nationality"] = first(main_p.get("nationality"), *[p.get("nationality") for p in plist], form("nationality"))
    lat = " ".join(x for x in (main_p.get("given_latin", ""), main_p.get("surname_latin", "")) if x)
    if main_p.get("given_latin") and main_p.get("surname_latin") and main_p["given_latin"].endswith(main_p["surname_latin"]):
        lat = main_p["given_latin"]                      # Iraqi passports repeat the last name in the given names
    form_lat = " ".join(x for x in (form("first_name_latin"), form("father_name") if not form("first_name") else "", form("surname_latin")) if x)
    s["name_latin"] = first(lat if len(lat.split()) >= 2 else "", form_lat, *[x.get("name_latin") for x in cvs])
    s["first_name"] = form("first_name"); s["surname"] = form("surname")
    for k in ("father_name", "mother_name", "marital", "birth_city", "address", "religion", "siblings", "father_job", "finance",
              "iran_travel", "iran_contacts", "other_applications", "job", "job_address", "job_description"):
        s[k] = form(k)
    if not s["job"]:
        s["job"] = first(*[x.get("job") for x in cvs])
    if not s["address"]:
        s["address"] = first(*[x.get("address") for x in cvs])
    s["children"] = first(*[x.get("children") for x in cvs])

    # contacts (agent numbers set apart)
    phones, mails, agents = [], [], []
    for f in files:
        ph, em = file_contacts(f)
        raw = [f["fields"].get("mobile", "")] + f["fields"].get("phones_in_text", []) + [f["info"].get("cv", {}).get("phone", "")]
        for v in raw:
            k = phone_key(v)
            if not k:
                continue
            e164 = c.phone_e164(v)
            (agents if k in agent_phone else phones).append(e164 or v)
        for v in em:
            (agents if v in agent_mail else mails).append(v)
    s["mobile"] = next(iter(dict.fromkeys(phones)), "")
    s["other_phones"] = list(dict.fromkeys(phones))[1:4]
    s["email"] = next(iter(dict.fromkeys(mails)), "")
    s["agent_contacts"] = sorted(set(agents))

    # previous education
    prev = []
    for x in forms:
        for e in x.get("education", []):
            lv = (e.get("level") or "").upper().replace(".", "")
            rank = 5 if lv.startswith("PH") else 4 if lv.startswith("M") else 3 if lv.startswith("B") else 1 if lv.startswith("DIP") else 0
            prev.append({"rank": rank, "field": e.get("field", ""), "university": e.get("centre", ""), "gpa": e.get("gpa", ""),
                         "from": e.get("from", ""), "to": e.get("to", ""), "place": e.get("place", ""), "src": "form"})
        if x.get("prev_level"):
            prev.append({"rank": DEGREE_RANK.get(x["prev_level"], 0), "field": x.get("prev_field", ""), "university": x.get("prev_university", ""),
                         "gpa": x.get("gpa_msc") if x["prev_level"] == "MSc" else x.get("gpa_bsc", ""), "src": "form"})
        for lv in ("bsc", "msc"):
            if x.get("gpa_" + lv):
                s["gpa_" + lv] = x["gpa_" + lv]
    doc_rank = 0
    for f in files:
        ce = f["info"].get("cert", {})
        lv = ce.get("level") or {"master_degree": "master", "bachelor_degree": "bachelor", "phd_degree": "phd", "diploma": "diploma"}.get(f["kind"], "")
        if lv:
            r = DEGREE_RANK[lv]
            doc_rank = max(doc_rank, r)
            prev.append({"rank": r, "university": ce.get("university_latin", ""), "gpa": ce.get("average", ""),
                         "to": str(ce["years"][-1]) if ce.get("years") else "", "src": "document"})
    best = {}
    for e in sorted(prev, key=lambda e: (-e["rank"], e["src"] != "form")):
        if e["rank"] and not best:
            best = dict(e)
        elif best and e["rank"] == best["rank"]:
            for k in ("field", "university", "gpa", "to", "from", "place"):
                if not best.get(k) and e.get(k):
                    best[k] = e[k]
    if best:
        s["prev_level"] = {1: "Diploma", 2: "Associate", 3: "BSc", 4: "MSc", 5: "PhD"}[best["rank"]]
        s["prev_field"] = best.get("field", ""); s["prev_university"] = best.get("university", "")
        s["prev_gpa"] = best.get("gpa", ""); s["prev_year"] = best.get("to", "")
    s["education_history"] = [{k: v for k, v in e.items() if v and k != "src"} for e in prev if e["src"] == "form" and e["rank"]]

    # applications (folder context + application forms + receipts)
    apps = {}
    for f in files:
        x = f["ctx"]
        if x.get("university"):
            k = (x["university"], x.get("term", ""), x.get("year", ""))
            a = apps.setdefault(k, {"university": x["university"], "term": x.get("term", ""), "year": x.get("year", ""), "level": "", "major": "", "folders": set()})
            a["level"] = a["level"] or x.get("level", ""); a["major"] = a["major"] or x.get("major", "")
            a["folders"].add(x.get("person_dir", os.path.dirname(f["rel"])))
        fl = f["fields"]
        if fl.get("apply_level") or fl.get("apply_major"):
            s.setdefault("form_apply", {"level": fl.get("apply_level", ""), "major": fl.get("apply_major", ""), "faculty": fl.get("apply_faculty", ""),
                                        "major_latin": fl.get("apply_major_latin", ""), "university_latin": fl.get("apply_university_latin", ""),
                                        "date": fl.get("apply_date", ""), "status": fl.get("application_status", "")})
    al = sorted(apps.values(), key=term_rank, reverse=True)
    for a in al:
        a["folders"] = sorted(a["folders"])
    s["applications"] = al

    # list rows (tracking code, agent, admission status, student number …) by any of the passports
    rows = [r for p in pas for r in rows_by_pass.get(p, [])]
    if not pas:                                   # no passport among the documents: the lists may know the person by name
        for nm in names:
            hit = rows_by_name.get(c.name_key(nm))
            if hit:
                rows = hit
                pn = pkey(hit[0]["passport"])
                s["passports"] = plist = [{"number": pn, "sources": len(hit), "valid": False, "from_list_by_name": True}]
                main_p = plist[0]
                break
    if not s["name"] and rows:                    # loose passport scan: the lists name its owner
        r0 = max(rows, key=len)
        s["name"] = c.label(" ".join(x for x in (r0.get("full_name", ""), r0.get("surname", "")) if x))
    if not s["name"]:
        s["name"] = s.get("name_latin", "")
    lf = {}
    for r in rows:
        for k, v in r.items():
            if k.startswith("_") or not v:
                continue
            lf.setdefault(k, [])
            if v not in lf[k]:
                lf[k].append(v)
    s["list_rows"] = len(rows)
    s["list"] = {k: v[:6] for k, v in lf.items()}
    s["list_sources"] = sorted({r["_src"]["rel"].split(os.sep)[-1] + " [" + r["_src"]["sheet"] + "]" for r in rows})[:12]

    # current application = the latest folder intake; level/major from the folder, the form, the lists, else from the documents
    cur = al[0] if al else {}
    s["university"] = cur.get("university", "")
    s["intake_term"] = cur.get("term", ""); s["intake_year"] = cur.get("year", "")
    fa = s.get("form_apply", {})
    lvl, src = cur.get("level", ""), "folder"
    if not lvl and fa.get("level"):
        lvl, src = fa["level"], "form"
    if not lvl and lf.get("level"):
        cand = [bd.norm_level(v) for v in lf["level"]]
        cand = [x for x in cand if x in ("PhD", "MSc", "BSc")]
        if cand:
            lvl, src = max(cand, key=lambda x: DEGREE_RANK[x]), "list"
    top = max([doc_rank] + [e["rank"] for e in prev] or [0])
    if not lvl and top:
        lvl, src = NEXT_LEVEL[top], "documents"
    s["level"] = lvl; s["level_source"] = src if lvl else ""
    s["major"] = first(cur.get("major"), fa.get("major"), *(lf.get("major", [])[:1]))
    s["major_source"] = "folder" if cur.get("major") else "form" if fa.get("major") else "list" if lf.get("major") else ""
    if not s["university"] and lf.get("university"):
        s["university"] = bd.norm_university(lf["university"][0]); s["university_source"] = "list"

    # documents
    for f in files:
        s["documents"].append({"md5": f["md5"], "rel": f["rel"], "kind": f["kind"], "label": KIND_FA.get(f["kind"], "سایر مدارک"),
                               "ext": f["ext"], "size": f["size"], "university": f["ctx"].get("university", ""),
                               "term": f["ctx"].get("term", ""), "year": f["ctx"].get("year", ""), "path": f.get("path", ""),
                               "archive": f.get("archive", ""), "by_batch": bool(f.get("by_batch"))})
    kinds = {d["kind"] for d in s["documents"]}
    has_degree = bool(kinds & {"degree_certificate", "master_degree", "bachelor_degree", "phd_degree", "transcript", "diploma", "arabic_document"})
    need = [("پاسپورت", "passport" in kinds or bool(pas)), ("عکس پرسنلی", "photo" in kinds), ("مدرک تحصیلی", has_degree), ("رزومه", bool(kinds & {"cv", "application_form", "application_form_sheet"}))]
    s["docs_missing"] = [n for n, ok in need if not ok]
    s["docs_complete"] = not s["docs_missing"]
    stage = "متقاضی"
    if kinds & {"application_receipt"} or fa.get("status"):
        stage = "درخواست ثبت شده"
    st_text = " ".join(lf.get("admission_status", []) + lf.get("system_status", []) + lf.get("status", []))
    if "رد شد" in st_text and "صادر" not in st_text:
        stage = "رد شده"
    if "صادر" in st_text:
        stage = "پذیرش صادر شده"
    if kinds & {"initial_admission", "admission"} or "اولیه" in st_text:
        stage = "پذیرش اولیه"
    if "final_admission" in kinds:
        stage = "پذیرش نهایی"
    if "registration_certificate" in kinds or lf.get("student_no"):
        stage = "ثبت‌نام شده"
    if "visa" in kinds:
        s["has_visa_doc"] = True
    s["stage"] = stage
    exp = main_p.get("expiry", "")
    if exp:
        d = datetime.date.fromisoformat(exp)
        s["passport_status"] = "منقضی" if d < TODAY else "رو به انقضا (کمتر از ۶ ماه)" if (d - TODAY).days < 183 else "معتبر"
    return s


def university_of_row(r):
    if r.get("university"):
        return bd.norm_university(r["university"])
    if r["_ctx"].get("university"):
        return r["_ctx"]["university"]
    stem = c.norm(os.path.splitext(os.path.basename(r["_src"]["rel"]))[0] + " " + r["_src"]["sheet"]).lower()
    for k, v in sorted(c.UNIVERSITIES, key=lambda kv: -len(kv[0])):
        if c.norm(k).lower() in stem:
            return v
    for k, v in (("kharazmi", "دانشگاه خوارزمی"), ("shahrekord", "دانشگاه شهرکرد"), ("urmia", "دانشگاه ارومیه"), ("arak", "دانشگاه اراک")):
        if k in stem:
            return v
    return ""


def build_list_person(passport, rows):
    """A person known only from the list workbooks."""
    rows = sorted(rows, key=lambda r: -len(r))
    lf = {}
    for r in rows:
        for k, v in r.items():
            if not k.startswith("_") and v:
                lf.setdefault(k, [])
                if v not in lf[k]:
                    lf[k].append(v)
    r0 = rows[0]
    full = c.label(" ".join(x for x in (r0.get("full_name", ""), r0.get("surname", "")) if x))
    s = {"units": [], "documents": [], "applications": [], "notes": [], "name": full, "name_variants": [], "flags": [], "list_only": True,
         "passports": [{"number": passport, "sources": len(rows), "valid": False}], "first_name": r0.get("full_name", "") if r0.get("surname") else "",
         "surname": r0.get("surname", ""), "father_name": (lf.get("father_name") or [""])[0], "name_latin": (lf.get("full_name_latin") or [""])[0],
         "gender": bd.norm_gender((lf.get("gender") or [""])[0]), "nationality": (lf.get("nationality") or [""])[0],
         "list_rows": len(rows), "list": {k: v[:6] for k, v in lf.items()},
         "list_sources": sorted({r["_src"]["rel"].split(os.sep)[-1] + " [" + r["_src"]["sheet"] + "]" for r in rows})[:12]}
    phones = [c.phone_e164(v) for v in lf.get("phone", [])]
    phones = [p for p in phones if p.startswith("+") and len(p) >= 12]
    s["mobile"] = phones[0] if phones else ""; s["other_phones"] = phones[1:3]
    s["email"] = next((v.lower() for v in lf.get("email", []) if "@" in v), "")
    lv = [bd.norm_level(v) for v in lf.get("level", [])]
    lv = [x for x in lv if x in DEGREE_RANK]
    s["level"] = max(lv, key=lambda x: DEGREE_RANK[x]) if lv else ""; s["level_source"] = "list" if lv else ""
    s["major"] = (lf.get("major") or [""])[0]; s["major_source"] = "list" if s["major"] else ""
    unis = [u for u in (university_of_row(r) for r in rows) if u]
    s["university"] = unis[0] if unis else ""
    s["intake_term"] = ""; s["intake_year"] = ""
    for r in rows:
        if r["_ctx"].get("year"):
            s["intake_term"] = r["_ctx"].get("term", ""); s["intake_year"] = r["_ctx"]["year"]; break
    stage = "متقاضی"
    st = " ".join(lf.get("admission_status", []) + lf.get("system_status", []) + lf.get("status", []))
    if "رد شد" in st and "صادر" not in st:
        stage = "رد شده"
    if "صادر" in st:
        stage = "پذیرش صادر شده"
    if "اولیه" in st:
        stage = "پذیرش اولیه"
    if "نهایی" in st:
        stage = "پذیرش نهایی"
    if lf.get("student_no"):
        stage = "ثبت‌نام شده"
    s["stage"] = stage
    s["docs_missing"] = []; s["docs_complete"] = False
    return s


def match_crm(students, path, agent_phone, rep):
    rows = list(csv.DictReader(open(path, encoding="utf-8"), delimiter="\t", quoting=csv.QUOTE_NONE))
    for r in rows:
        for k, v in r.items():
            if v == "NULL":
                r[k] = ""
    by_pass, by_phone, by_name = collections.defaultdict(list), collections.defaultdict(list), collections.defaultdict(list)
    for r in rows:
        if r["cf_ss_passport"]:
            by_pass[pkey(r["cf_ss_passport"])].append(r)
            d, _ = bd.passport_key(pkey(r["cf_ss_passport"]))
            by_pass["#" + d].append(r)
        for k in ("mobile", "phone", "otherphone", "homephone", "cf_ss_whatsapp"):
            pk = phone_key(r[k])
            if pk:
                by_phone[pk].append(r)
        for nm in (r["cf_ss_full_name_native"], (r["firstname"] + " " + r["lastname"]).strip()):
            k = c.name_key(nm)
            if len(k.split()) >= 2 and r not in by_name[k]:
                by_name[k].append(r)
    phone_n = collections.Counter({k: len({r["contactid"] for r in v}) for k, v in by_phone.items()})
    used = collections.defaultdict(list)
    for s in students:
        hit, how = None, "new"
        for p in s["passports"]:
            c1 = by_pass.get(p["number"], [])
            if c1:
                hit, how = c1[0], "passport"; break
        if not hit:
            for p in s["passports"]:
                d, _ = bd.passport_key(p["number"])
                c1 = by_pass.get("#" + d, []) if len(d) >= 7 else []
                if len({r["contactid"] for r in c1}) == 1:
                    hit, how = c1[0], "passport digits"; break
        if not hit:
            for ph in [s.get("mobile", "")] + s.get("other_phones", []):
                k = phone_key(ph)
                if k and k not in agent_phone and phone_n.get(k) == 1:
                    cand = by_phone[k][0]
                    if not cand["cf_ss_passport"] or not s["passports"]:      # a contact with another passport is another person
                        hit, how = cand, "phone"; break
        if not hit:
            for nm in [s["name"]] + s.get("name_variants", []):
                k = c.name_key(nm)
                cand = by_name.get(k, [])
                if len(k.split()) >= 3 and len({r["contactid"] for r in cand}) == 1:
                    r0 = cand[0]
                    if r0["cf_ss_passport"] and s["passports"]:
                        continue                                           # both have (different) passports
                    hit, how = r0, "name"; break
        s["crm"] = {"how": how}
        if hit:
            s["crm"].update({"contactid": hit["contactid"], "contact_no": hit["contact_no"], "name": (hit["firstname"] + " " + hit["lastname"]).strip(),
                             "passport": hit["cf_ss_passport"], "level": hit["cf_ss_level"], "university": hit["cf_ss_university"], "major": hit["cf_ss_major"]})
            used[hit["contactid"]].append(s)
    for cid, ss in used.items():
        if len(ss) > 1:
            rep.append(f"CRM contact {cid} matched by {len(ss)} document students: " + " | ".join(f"{x['name']} ({x['crm']['how']})" for x in ss))


if __name__ == "__main__":
    main()
