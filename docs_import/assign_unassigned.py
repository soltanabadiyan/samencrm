#!/usr/bin/env python3
"""
Step 5 of the student-documents import: the loose files that the folder structure could not tie to a student.

Each loose file was read by eye (an agent looked at the scan / read the text: read_unassigned workflow) and returned
who it belongs to and what it states – output/docs/vision_read.json, one entry per file id.  This script:

  1. groups the files into people: same passport number, the same full name (Arabic 3+ words or Latin 3+ words),
     "same_person_as" links the reader saw (same name, or the same face as a passport photo), and files of one
     download batch that carry no identity when exactly one person is identified in that batch;
  2. matches every person against the CRM (passport, other passports, full Arabic or Latin name – only when exactly
     one contact fits – or a phone that belongs to one contact) and the list workbooks (passport);
  3. writes output/docs/import_unassigned.json for crm_docs_import.php: new contacts for people the CRM does not have,
     documents for everyone, fields only filled where empty on existing contacts.

  python3 assign_unassigned.py output/docs <all_files.json> [--groups '{"PhD":9,…}']
Files of no person (company material, unreadable, portraits with no identified batch) are listed in
unassigned_left.json and not uploaded.
"""
import collections, csv, hashlib, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import common as c
import build_dataset as bd
from make_import import FOLDER, OTHER_FOLDER, MIME, LEVEL_FA, NATION

OUT = sys.argv[1] if __name__ == "__main__" else ""
ALL = sys.argv[2] if __name__ == "__main__" else ""
GROUPS = {"PhD": 9, "MSc": 7, "BSc": 6, "Associate": 5, "Other": 8, "": 10}
if __name__ == "__main__" and "--groups" in sys.argv:
    GROUPS = json.loads(sys.argv[sys.argv.index("--groups") + 1])
MARK = "[مدارک تکمیلی"          # this run's notes block (the first import's block starts with "[مدارک دانشجو")
MARK_LINE = "[مدارک تکمیلی - پرونده‌های بدون پوشه]"

KIND = {   # reader's doc_type -> type key of make_import / build_students
    "passport": "passport", "national_id": "national_id", "photo": "photo", "cv": "cv", "application_form": "application_form",
    "bachelor_degree": "bachelor_degree", "master_degree": "master_degree", "phd_degree": "phd_degree", "diploma": "diploma",
    "transcript": "transcript", "degree_certificate_other": "degree_certificate", "admission": "admission",
    "registration_certificate": "registration_certificate", "visa": "visa", "payment_receipt": "payment_receipt",
    "language_certificate": "language_certificate", "recommendation": "recommendation", "proposal_or_paper": "proposal",
    "other_student_document": "unknown",
}
LABEL = {"passport": "پاسپورت", "national_id": "مدرک هویتی", "photo": "عکس پرسنلی", "cv": "رزومه", "application_form": "فرم درخواست",
         "bachelor_degree": "مدرک کارشناسی", "master_degree": "مدرک کارشناسی ارشد", "phd_degree": "مدرک دکتری", "diploma": "دیپلم",
         "transcript": "ریزنمرات", "degree_certificate": "مدرک تحصیلی", "admission": "پذیرش", "registration_certificate": "گواهی ثبت‌نام",
         "visa": "ویزا", "payment_receipt": "رسید پرداخت", "language_certificate": "مدرک زبان", "recommendation": "توصیه‌نامه",
         "proposal": "پروپوزال", "unknown": "سایر مدارک"}
LEVEL_OF = {"bachelor": "BSc", "master": "MSc", "phd": "PhD", "diploma": "Diploma"}
RANK = {"Diploma": 1, "BSc": 3, "MSc": 4, "PhD": 5}
NEXT = {1: "BSc", 3: "MSc", 4: "PhD", 5: "PhD"}
IDENTITY_FREE = {"photo", "transcript", "unknown", "degree_certificate", "bachelor_degree", "master_degree", "phd_degree", "diploma"}


def pkey(p):
    p = re.sub(r"[^A-Z0-9]", "", (p or "").upper().translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789")))
    return p if re.fullmatch(r"[A-Z]{0,2}\d{6,9}", p) else ""


def pdig(p):
    return bd.passport_key(p)[0] if p else ""


def lat_key(s):
    w = re.sub(r"[^A-Z ]", " ", (s or "").upper().replace("-", " ")).split()
    w = [x[2:] if x.startswith("AL") and len(x) > 4 else x for x in w if x not in ("AL", "EL")]
    return " ".join(w)


def ar_key(s):
    k = c.name_key(s)
    return k if len(k.split()) >= 3 else ""


def phone_key(p):
    d = re.sub(r"\D", "", (p or "").translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789")))
    return d[-10:] if len(d) >= 10 else ""


class UF:
    def __init__(self):
        self.p = {}

    def f(self, x):
        self.p.setdefault(x, x)
        while self.p[x] != x:
            self.p[x] = self.p[self.p[x]]; x = self.p[x]
        return x

    def u(self, a, b):
        a, b = self.f(a), self.f(b)
        if a != b:
            self.p[max(a, b)] = min(a, b)


def main():
    files = {f["id"]: f for f in json.load(open(ALL, encoding="utf-8"))}
    reads = {r["id"]: r for r in json.load(open(os.path.join(OUT, "vision_read.json"), encoding="utf-8"))}
    lists = json.load(open(os.path.join(OUT, "lists.json"), encoding="utf-8"))
    crm = list(csv.DictReader(open(os.path.join(OUT, "crm_contacts_now.tsv"), encoding="utf-8"), delimiter="\t", quoting=csv.QUOTE_NONE))
    for r in crm:
        for k, v in r.items():
            if v == "NULL":
                r[k] = ""
    rep = []

    # ---------------------------------------------------------------- 1. people
    uf = UF()
    by_pass, by_ar, by_lat = collections.defaultdict(list), collections.defaultdict(list), collections.defaultdict(list)
    def strong(fid):     # identity read with confidence, from a document about the person (not an agency bank slip)
        r = reads[fid]
        return r.get("confidence") in ("high", "medium") and r["doc_type"] not in ("payment_receipt", "not_student_document", "unreadable")

    for fid, r in reads.items():
        if fid not in files:
            continue
        uf.f(fid)
        if not strong(fid):
            continue
        p = pdig(pkey(r.get("passport")))
        if len(p) >= 7:
            by_pass[p].append(fid)
        if ar_key(r.get("name_ar")):
            by_ar[ar_key(r["name_ar"])].append(fid)
        if len(lat_key(r.get("name_latin")).split()) >= 3:
            by_lat[lat_key(r["name_latin"])].append(fid)
        if r.get("same_person_as") and r["same_person_as"] in reads and r["same_person_as"] != fid:
            uf.u(fid, r["same_person_as"])
    for idx in (by_pass, by_ar, by_lat):
        for fl in idx.values():
            for x in fl[1:]:
                uf.u(fl[0], x)
    # weak identities (bank slips, low confidence) only join a person found by a strong one
    for fid, r in reads.items():
        if fid not in files or strong(fid) or r["doc_type"] in ("not_student_document", "unreadable"):
            continue
        p = pdig(pkey(r.get("passport")))
        for k, idx in ((p if len(p) >= 7 else "", by_pass), (ar_key(r.get("name_ar")), by_ar), (lat_key(r.get("name_latin")), by_lat)):
            if k and idx.get(k):
                uf.u(idx[k][0], fid); break

    def identity(fid):
        r = reads[fid]
        return bool(pdig(pkey(r.get("passport"))) or ar_key(r.get("name_ar")) or len(lat_key(r.get("name_latin")).split()) >= 2)

    # files of one batch without identity: to the single person identified in that batch
    batches = collections.defaultdict(list)
    for fid in reads:
        if fid in files:
            batches[files[fid]["batch"]].append(fid)
    by_batch = 0
    for b, fl in batches.items():
        roots = {uf.f(x) for x in fl if identity(x) and strong(x)}
        if len(roots) != 1:
            continue
        root = next(iter(roots))
        for x in fl:
            r = reads[x]
            if not identity(x) and r["doc_type"] not in ("not_student_document", "unreadable") and uf.f(x) != root:
                uf.u(root, x); files[x]["by_batch"] = True; by_batch += 1

    groups = collections.defaultdict(list)
    for fid in reads:
        if fid in files:
            groups[uf.f(fid)].append(fid)
    people, left = [], []
    for g in groups.values():
        if not any(identity(x) for x in g):
            left += g
            continue
        pr = person(g, reads, files)
        pr["weak"] = not any(strong(x) for x in g)
        people.append(pr)

    # ---------------------------------------------------------------- 2. match
    cp, cpo, car, clat, cph = collections.defaultdict(set), collections.defaultdict(set), collections.defaultdict(set), collections.defaultdict(set), collections.defaultdict(set)
    C = {r["contactid"]: r for r in crm}
    for r in crm:
        d = pdig(pkey(r["cf_ss_passport"]))
        if len(d) >= 7:
            cp[d].add(r["contactid"])
        for o in re.split(r"[،,\s]+", r["cf_ss_other_passports"]):
            d = pdig(pkey(o))
            if len(d) >= 7:
                cpo[d].add(r["contactid"])
        for n in (r["cf_ss_full_name_native"], (r["firstname"] + " " + r["lastname"]).strip()):
            if ar_key(n):
                car[ar_key(n)].add(r["contactid"])
        if len(lat_key(r["cf_ss_full_name_latin"]).split()) >= 3:
            clat[lat_key(r["cf_ss_full_name_latin"])].add(r["contactid"])
        for k in ("mobile", "phone", "otherphone", "homephone", "cf_ss_whatsapp"):
            if phone_key(r[k]):
                cph[phone_key(r[k])].add(r["contactid"])
    lp = collections.defaultdict(list)
    for r in lists:
        d = pdig(pkey(r.get("passport")))
        if len(d) >= 7:
            lp[d].append(r)

    for p in people:
        hit, how = None, "new"
        for d in p["passports_d"]:
            if len(cp.get(d, ())) == 1:
                hit, how = next(iter(cp[d])), "passport"; break
            if len(cpo.get(d, ())) == 1:
                hit, how = next(iter(cpo[d])), "other passport"; break
        if not hit:
            for k in p["ar_keys"]:
                cand = car.get(k, set())
                if len(cand) == 1:
                    x = next(iter(cand))
                    if not (p["passports_d"] and pdig(pkey(C[x]["cf_ss_passport"])) and pdig(pkey(C[x]["cf_ss_passport"])) not in p["passports_d"]):
                        hit, how = x, "name"; break
        if not hit:
            for k in p["lat_keys"]:
                cand = clat.get(k, set())
                if len(cand) == 1:
                    x = next(iter(cand))
                    if not (p["passports_d"] and pdig(pkey(C[x]["cf_ss_passport"])) and pdig(pkey(C[x]["cf_ss_passport"])) not in p["passports_d"]):
                        hit, how = x, "latin name"; break
        if not hit:
            for ph in p["phones"]:
                cand = cph.get(phone_key(ph), set())
                if len(cand) == 1:
                    x = next(iter(cand))
                    if not (p["passports_d"] and pdig(pkey(C[x]["cf_ss_passport"]))):
                        hit, how = x, "phone"; break
        p["crm"] = {"how": how}
        if hit:
            r = C[hit]
            p["crm"].update({"contactid": hit, "contact_no": r["contact_no"], "name": (r["firstname"] + " " + r["lastname"]).strip(),
                             "passport": r["cf_ss_passport"], "docs_count": r["cf_ss_docs_count"], "level": r["cf_ss_level"]})
        rows = [x for d in p["passports_d"] for x in lp.get(d, [])]
        p["list"] = rows
    # verdicts of the review (verify_unassigned.json: two independent reviewers per case, a third on disagreement)
    vpath = os.path.join(OUT, "verify_unassigned.json")
    if os.path.exists(vpath):
        V = json.load(open(vpath, encoding="utf-8"))
        changed = collections.Counter()
        for v in V:
            fids = set(v["file_ids"])
            if v["kind"] == "batch":
                for p in people:
                    if not v["same_person"] and fids & set(p["files"]):
                        for x in fids & set(p["files"]):
                            i = p["files"].index(x); p["files"].pop(i); p["kinds"].pop(i); left.append(x)
                        changed["batch file taken back"] += 1
                continue
            p = next((p for p in people if fids & set(p["files"])), None)
            if not p:
                continue
            if v["kind"] == "match" and not v["same_person"]:
                p["crm"] = {"how": "new (review rejected the match)"}; changed["match rejected"] += 1
            elif v["kind"] == "new" and v["same_person"] and v.get("contactid") in C:
                r = C[v["contactid"]]
                p["crm"] = {"how": "review: same as existing contact", "contactid": v["contactid"], "contact_no": r["contact_no"],
                            "name": (r["firstname"] + " " + r["lastname"]).strip(), "passport": r["cf_ss_passport"],
                            "docs_count": r["cf_ss_docs_count"], "level": r["cf_ss_level"]}
                changed["new -> existing"] += 1
            p.setdefault("review", []).append(f"{v['kind']}: {'yes' if v['same_person'] else 'no'} ({v['confidence']}) {v['reason']}")
        rep.append("review applied: " + str(dict(changed)))
    # manual corrections after the review (a cluster of two people split, files with no owner dropped)
    opath = os.path.join(OUT, "unassigned_overrides.json")
    if os.path.exists(opath):
        O = json.load(open(opath, encoding="utf-8"))
        moved = set(O.get("assign", {})) | set(O.get("drop", []))
        for p in people:
            keep = [i for i, x in enumerate(p["files"]) if x not in moved]
            p["files"] = [p["files"][i] for i in keep]; p["kinds"] = [p["kinds"][i] for i in keep]
        people = [p for p in people if p["files"]]
        left += [x for x in O.get("drop", []) if x in reads]
        for cid, fl in sorted({cid: [f for f, c2 in O["assign"].items() if c2 == cid] for cid in set(O.get("assign", {}).values())}.items()):
            q = person(list(fl), reads, files)
            r = C[cid]
            q["weak"] = False
            q["crm"] = {"how": "manual (review split)", "contactid": cid, "contact_no": r["contact_no"], "name": (r["firstname"] + " " + r["lastname"]).strip(),
                        "passport": r["cf_ss_passport"], "docs_count": r["cf_ss_docs_count"], "level": r["cf_ss_level"]}
            q["list"] = []
            people.append(q)
        rep.append("manual corrections: " + O.get("why", ""))
    # a new contact must not repeat another contact's passport number: then the number only goes to the notes
    global TAKEN
    TAKEN = set(cp)
    used = collections.Counter(p["crm"].get("contactid") for p in people if p["crm"].get("contactid"))
    for p in people:
        cid = p["crm"].get("contactid")
        if cid and used[cid] > 1:
            rep.append(f"contact {cid} matched by {used[cid]} people: {p['name']} ({p['crm']['how']})")

    # ---------------------------------------------------------------- 3. payload
    stage = os.path.join(OUT, "stage", "files")
    os.makedirs(stage, exist_ok=True)
    out = []
    weak_left = [p for p in people if p["weak"] and not p["crm"].get("contactid")]
    for p in weak_left:
        left += p["files"]
    people = [p for p in people if not (p["weak"] and not p["crm"].get("contactid"))]
    by_contact, sids = {}, collections.Counter()
    for p in people:
        rec = payload(p, files, stage)
        sids[rec["sid"]] += 1
        if sids[rec["sid"]] > 1:
            rec["sid"] += f"-{sids[rec['sid']]}"
        cid = rec["contactid"]
        if cid and cid in by_contact:          # several clusters of one existing contact: one record, one notes block
            r0 = by_contact[cid]
            seen = {d["md5"] for d in r0["documents"]}
            r0["documents"] += [d for d in rec["documents"] if d["md5"] not in seen]
            r0["tags"] = list(dict.fromkeys(r0["tags"] + rec["tags"]))
            for k, v in rec["set_if_empty"].items():
                r0["set_if_empty"].setdefault(k, v)
            r0["append_description"] += "\n" + "\n".join(rec["append_description"].split("\n")[1:])
            r0["set"]["cf_ss_docs_count"] = str(int(p["crm"].get("docs_count") or 0) + len(r0["documents"]))
            continue
        if cid:
            by_contact[cid] = rec
        out.append(rec)
    picks = collections.defaultdict(set)
    for r in out:
        for k in ("cf_ss_university", "cf_ss_major", "cf_ss_level", "cf_ss_nationality", "cf_ss_gender", "cf_ss_prev_level", "cf_ss_job_status"):
            v = r["set_if_empty"].get(k) or r["set"].get(k)
            if v:
                picks[k].add(v)
    json.dump({"blocks": [], "picklists": {k: sorted(v) for k, v in picks.items()}, "folders": sorted(set(FOLDER.values()) | {OTHER_FOLDER}), "students": out},
              open(os.path.join(OUT, "import_unassigned.json"), "w", encoding="utf-8"), ensure_ascii=False)
    json.dump(people, open(os.path.join(OUT, "unassigned_people.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    json.dump([{"id": x, "rel": files[x]["rel"], "doc_type": reads[x]["doc_type"], "note": reads[x].get("note", "")} for x in left],
              open(os.path.join(OUT, "unassigned_left.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    L = [f"files read {len(reads)}; people {len(people)}; files given to a person {sum(len(p['files']) for p in people)} "
         f"(by batch {by_batch}); left without a person {len(left)}",
         "left: " + str(collections.Counter(reads[x]["doc_type"] for x in left).most_common()),
         f"people known only from a bank slip / low-confidence reading and not in the CRM (not created): {len(weak_left)}",
         "match: " + str(collections.Counter(p["crm"]["how"] for p in people)),
         "new contacts: " + str(sum(1 for p in people if not p["crm"].get("contactid"))) +
         "; existing contacts receiving documents: " + str(len({p['crm']['contactid'] for p in people if p['crm'].get('contactid')})),
         "documents: " + str(sum(len(x["documents"]) for x in out))] + rep
    open(os.path.join(OUT, "report_unassigned.txt"), "w", encoding="utf-8").write("\n".join(L) + "\n")
    print("\n".join(L[:8]))


TAKEN = set()
UNI_LATIN = [   # university as printed on letters (English / Arabic) -> the CRM's value
    (r"medical sciences.*qom|qom.*medical|قم لعلوم الطب|علوم پزشکی قم", "دانشگاه علوم پزشکی قم"),
    (r"medical sciences.*urmia|urmia.*medical", "دانشگاه علوم پزشکی ارومیه"),
    (r"urmia university of technology|صنعتی ارومیه", "دانشگاه صنعتی ارومیه"),
    (r"urmia|ارومیه|اروميه", "دانشگاه ارومیه"), (r"qom|قم", "دانشگاه قم"), (r"arak|اراک", "دانشگاه اراک"),
    (r"iran university of science|علم و صنعت", "دانشگاه علم و صنعت"), (r"tabriz|تبریز", "دانشگاه تبریز"),
    (r"madani|مدنی", "دانشگاه شهید مدنی تبریز"), (r"kharazmi|خوارزمی", "دانشگاه خوارزمی"), (r"tarbiat modares|تربیت مدرس", "دانشگاه تربیت مدرس"),
    (r"university of tehran|tehran university|دانشگاه تهران", "دانشگاه تهران"), (r"mustafa|المصطفى|المصطفی", "دانشگاه المصطفی"),
    (r"آزاد اصفهان", "دانشگاه آزاد اصفهان"), (r"mazandaran|مازندران", "دانشگاه مازندران"), (r"shahrekord|شهرکرد", "دانشگاه شهرکرد"),
]


def norm_uni(v):
    low = (v or "").lower()
    for rx, name in UNI_LATIN:
        if re.search(rx, low):
            return name
    return c.label(v)


def norm_major(v):
    v = c.label(v)
    v = re.sub(r"\(?\b\d{5}\b\)?\s*-?\s*", "", v).strip(" -")
    v = v.replace("خصوصي", "خصوصی").replace("عمومي", "عمومی")
    fa = {"public law": "حقوق عمومی", "public rights": "حقوق عمومی", "private law": "حقوق خصوصی", "information technology": "فناوری اطلاعات",
          "arabic language and literature": "زبان و ادبیات عربی", "chemical engineering (separation processes)": "مهندسی شیمی - فرایندهای جداسازی",
          "industrial management - systems management": "مدیریت صنعتی - مدیریت سیستم‌ها"}
    m = re.search(r"\(([^()]*[\u0600-\u06ff][^()]*)\)", v)
    if m and not re.search(r"[\u0600-\u06ff]", v[:m.start()]):
        return c.label(m.group(1))                                   # "Private Law (حقوق خصوصی)" -> the Persian part
    if re.search(r"[\u0600-\u06ff]", v) and "(" in v:
        v = re.sub(r"\s*\([^()]*\)", "", v).strip()                  # "بیوشیمی بالینی (Clinical Biochemistry)"
    low = re.sub(r"^law\s*-\s*", "", v.lower()).strip()
    return fa.get(low, v).replace("حقوق - ", "حقوق ")


def new_person_flag(crm):
    return not crm.get("contactid")


def first(vals):
    for v in vals:
        if v:
            return v
    return ""


def person(g, reads, files):
    g[:] = [x for x in g if reads[x]["doc_type"] not in ("not_student_document", "unreadable")] or g
    rs = [dict(reads[x], _fid=x) for x in g]
    id_rank = {"passport": 0, "national_id": 1, "application_form": 2, "cv": 3}
    rs.sort(key=lambda r: (id_rank.get(r["doc_type"], 9), {"high": 0, "medium": 1, "low": 2}.get(r.get("confidence"), 3)))
    ar = collections.Counter(c.label(r["name_ar"]) for r in rs if r.get("name_ar"))
    lat = collections.Counter(re.sub(r"\s+", " ", r["name_latin"]).strip().upper() for r in rs if r.get("name_latin"))
    name_ar = max(ar, key=lambda n: (ar[n], len(n.split()))) if ar else ""
    name_lat = max(lat, key=lambda n: (lat[n], len(n.split()))) if lat else ""
    pas = []
    for r in rs:
        p = pkey(r.get("passport"))
        if p and pdig(p) not in [pdig(x) for x in pas]:
            pas.append(p)
    pp = next((r for r in rs if r["doc_type"] == "passport"), {})
    deg = [(RANK[LEVEL_OF[r["degree_level"]]], r) for r in rs if r.get("degree_level") in LEVEL_OF]
    top = max(deg, key=lambda x: x[0]) if deg else (0, {})
    tgt = next((r for r in rs if r.get("target_level") or r.get("target_university")), {})
    lvl, src = "", ""
    if tgt.get("target_level"):
        lvl, src = LEVEL_OF[tgt["target_level"]], "مدرک درخواست/پذیرش"
    elif top[0]:
        lvl, src = NEXT[top[0]], "از روی مدارک (یک مقطع بالاتر از آخرین مدرک)"
    p = {
        "files": g, "name_ar": name_ar, "name_latin": name_lat, "name": name_ar or name_lat.title(),
        "name_variants": [n for n in list(ar) + [x.title() for x in lat] if n and n not in (name_ar, name_lat.title())][:4],
        "passports": pas, "passports_d": [pdig(x) for x in pas],
        "ar_keys": [k for k in {ar_key(n) for n in ar} if k], "lat_keys": [k for k in {lat_key(n) for n in lat} if len(k.split()) >= 3],
        "phones": [x for x in dict.fromkeys(r.get("phone", "") for r in rs) if phone_key(x)],
        "emails": [x for x in dict.fromkeys((r.get("email") or "").lower() for r in rs) if "@" in x],
        "birth_date": first([pp.get("birth_date")] + [r.get("birth_date") for r in rs]),
        "birth_place": first([pp.get("birth_place")] + [r.get("birth_place") for r in rs]),
        "gender": first([pp.get("gender")] + [r.get("gender") for r in rs]),
        "nationality": first([pp.get("nationality")] + [r.get("nationality") for r in rs]),
        "father_name": first(r.get("father_name") for r in rs), "mother_name": first([pp.get("mother_name")] + [r.get("mother_name") for r in rs]),
        "address": first(r.get("address") for r in rs), "job": first(r.get("job") for r in rs),
        "prev_level": LEVEL_OF.get(top[1].get("degree_level", ""), ""), "prev_field": top[1].get("degree_field", ""),
        "prev_university": top[1].get("degree_university", ""), "prev_year": top[1].get("degree_year", ""), "prev_gpa": top[1].get("gpa", ""),
        "target_university": tgt.get("target_university", ""), "target_major": tgt.get("target_major", ""), "target_term": tgt.get("target_term", ""),
        "level": lvl, "level_source": src,
        "notes": [f"{LABEL.get(KIND.get(r['doc_type'], 'unknown'), r['doc_type'])}: {r['note']}" for r in rs if r.get("note")][:12],
        "kinds": [KIND.get(reads[x]["doc_type"], "unknown") for x in g],
        "by_batch": [x for x in g if files[x].get("by_batch")],
    }
    return p


def payload(p, files, stage):
    crm = p["crm"]
    lvl = p["level"]
    rows = p["list"]
    r0 = max(rows, key=len) if rows else {}
    if not lvl and r0.get("level"):
        x = bd.norm_level(r0["level"])
        if x in RANK:
            lvl = x; p["level_source"] = "فهرست اکسل"
    major = norm_major(p["target_major"] or r0.get("major", ""))
    uni = norm_uni(p["target_university"]) if p["target_university"] else (bd.norm_university(r0["university"]) if r0.get("university") else "")
    sid = "U" + (p["passports_d"][0] if p["passports_d"] else hashlib.md5((c.name_key(p["name"]) + "|" + sorted(p["files"])[0]).encode()).hexdigest()[:12])
    kinds = collections.Counter(LABEL.get(k, "سایر مدارک") for k in p["kinds"])
    has = set(p["kinds"])
    missing = [n for n, ok in (("پاسپورت", "passport" in has or bool(p["passports"])), ("عکس پرسنلی", "photo" in has),
                               ("مدرک تحصیلی", bool(has & {"bachelor_degree", "master_degree", "phd_degree", "diploma", "transcript", "degree_certificate"})),
                               ("رزومه", bool(has & {"cv", "application_form"}))) if not ok]
    sie = {
        "cf_ss_passport": p["passports"][0] if p["passports"] else "", "cf_ss_full_name_native": p["name_ar"],
        "cf_ss_full_name_latin": p["name_latin"], "birthday": p["birth_date"] if re.fullmatch(r"(19[3-9]\d|200\d|201[0-5])-\d\d-\d\d", p["birth_date"] or "") else "",
        "cf_ss_gender": p["gender"], "cf_ss_nationality": NATION.get(c.label(p["nationality"]), "") or ("iraq" if re.search(r"iraq|عراق", p["nationality"] or "", re.I) else ""),
        "cf_ss_father_name": p["father_name"], "cf_ss_mother_name": p["mother_name"], "cf_ss_birth_place": p["birth_place"],
        "mobile": c.phone_e164(p["phones"][0]) if p["phones"] else "", "email": p["emails"][0] if p["emails"] else "",
        "mailingstreet": p["address"][:250], "cf_ss_job": p["job"][:250],
        "cf_ss_prev_level": p["prev_level"] if p["prev_level"] in ("Diploma", "BSc", "MSc", "PhD") else "",
        "cf_ss_prev_field": p["prev_field"][:250], "cf_ss_prev_university": p["prev_university"][:250], "cf_ss_prev_year": p["prev_year"],
        "cf_ss_prev_gpa": p["prev_gpa"], "cf_ss_level": lvl, "cf_ss_major": major[:250], "cf_ss_university": uni,
        "cf_ss_tracking_code": r0.get("tracking_code", ""), "cf_ss_broker": r0.get("broker", ""), "cf_ss_student_no": r0.get("student_no", ""),
        "cf_ss_level_source": p["level_source"], "cf_ss_other_passports": "، ".join(p["passports"][1:4]),
        "cf_ss_job_status": "شاغل" if p["job"] and not re.match(r"(?i)^(no|none|-|_|unemployed|student)$", p["job"].strip()) else "",
    }
    if new_person_flag(crm) and p["passports_d"] and p["passports_d"][0] in TAKEN:
        sie["cf_ss_passport"] = ""
        p["notes"].insert(0, "شماره گذرنامه این مدارک با گذرنامه مخاطب دیگری یکی است؛ بازبینی شود: " + "، ".join(p["passports"]))
        p["review_tag"] = "بازبینی: گذرنامه مشترک با مخاطب دیگر"
    sie = {k: v for k, v in sie.items() if v}
    n_old = int(crm.get("docs_count") or 0) if crm.get("contactid") else 0
    st = {"cf_ss_docs_count": str(n_old + len(p["files"]))}
    new_person = not crm.get("contactid")
    if new_person:
        st.update({"cf_ss_data_source": "اسناد دانشجو (پرونده‌های بدون پوشه)", "cf_ss_stage": "متقاضی",
                   "cf_ss_docs_complete": "کامل" if not missing else "ناقص", "cf_ss_docs_missing": "، ".join(missing),
                   "cf_ss_docs_types": "، ".join(f"{k} ({n})" if n > 1 else k for k, n in kinds.most_common())})
        summ = " ".join(x for x in ("متقاضی", LEVEL_FA.get(lvl, "")) if x)
        if uni:
            summ += " – " + uni
        if lvl or uni:
            st["cf_ss_status_summary"] = summ
    tags = ["منبع: پرونده‌های بدون پوشه"] + ([p["review_tag"]] if p.get("review_tag") else [])
    if new_person:
        if lvl:
            tags.append("مقطع: " + LEVEL_FA.get(lvl, lvl))
        if major:
            tags.append("رشته: " + major)
        if uni:
            tags.append("دانشگاه: " + uni.replace("دانشگاه ", ""))
        tags.append("مرحله: متقاضی")
        tags.append("مدارک: " + ("کامل" if not missing else "ناقص"))
        tags += ["ندارد: " + m for m in missing]
        if p["prev_level"] in ("BSc", "MSc", "PhD"):
            tags.append("مدرک قبلی: " + LEVEL_FA.get(p["prev_level"], ""))
        if sie.get("cf_ss_nationality") == "iraq":
            tags.append("ملیت: عراق")
    name = p["name"] or ("دارنده گذرنامه " + p["passports"][0] if p["passports"] else "بدون نام")
    docs = []
    nk = collections.Counter()
    for fid in sorted(p["files"], key=lambda x: (LABEL.get(reads_kind(p, x), ""), files[x]["rel"])):
        f = files[fid]
        if not os.path.exists(f["path"]):
            continue
        kind = p["kinds"][p["files"].index(fid)]
        label = LABEL.get(kind, "سایر مدارک")
        ext = ".jpg" if f["ext"] == ".jfif" else f["ext"]
        fname = f["md5"] + ext
        link = os.path.join(stage, fname)
        if not os.path.lexists(link):
            os.symlink(os.path.abspath(f["path"]), link)
        nk[label] += 1
        i = nk[label]
        desc = "منبع: " + f["rel"] + "\nتخصیص: خواندن محتوای مدرک (پرونده بدون پوشه)"
        if f.get("by_batch"):
            desc += "؛ این فایل نام صاحبش را ندارد و با مدارک او در یک نوبت دریافت شده است"
        docs.append({"md5": f["md5"], "file": fname, "name": re.sub(r"[\\/:*?\"<>|]+", " ", f"{label} - {name} {i}").strip()[:120] + ext,
                     "mime": MIME.get(f["ext"], "application/octet-stream"), "title": (f"{label} – {name}" + (f" ({i})" if i > 1 else ""))[:200],
                     "folder": FOLDER.get(kind, OTHER_FOLDER), "tags": [label, "مدارک دانشجو", "پرونده بدون پوشه"], "description": desc})
    first_n, last_n = (name.split()[0], " ".join(name.split()[1:])) if len(name.split()) > 1 else ("", name)
    notes = [MARK_LINE] + [f"گذرنامه: {x}" for x in p["passports"]] + [f"نام‌های دیگر: {'، '.join(p['name_variants'])}"] * bool(p["name_variants"]) + p["notes"]
    if p["target_term"]:
        notes.append("ترم درخواستی: " + p["target_term"])
    if rows:
        notes.append("فهرست‌های منبع: " + " ؛ ".join(sorted({r['_src']['rel'].split('/')[-1] for r in rows})[:6]))
    rec = {"sid": sid, "contactid": crm.get("contactid"), "owner": GROUPS.get(lvl, GROUPS[""]), "set": st, "set_if_empty": sie, "tags": tags,
           "tag_prefixes": [], "documents": docs, "append_description": "\n".join(notes), "description_marker": MARK}
    if new_person:
        rec["create"] = dict(sie, firstname=first_n, lastname=last_n, **st)
    return rec


def reads_kind(p, fid):
    return p["kinds"][p["files"].index(fid)]


if __name__ == "__main__":
    main()
