#!/usr/bin/env python3
"""
Step 3 of the student-documents import: students.json -> what the CRM gets.

  python3 make_import.py <out_dir> [--groups '{"PhD":9,"MSc":7,"BSc":6,"Associate":5,"":10}']
  writes import.json  (read by crm_docs_import.php: new fields, per student create / set / set_if_empty, tags, documents)
         stage/files/<md5><ext>  symlinks to the unique files to send to the server
         review.csv   one line per student: how it was matched, what will be created or updated

Rules
- "set_if_empty": identity and contact fields – never replace what is in the CRM.
- "set": the fields this import owns (status, current application, previous education, passport dates, documents).
- People known only from the list workbooks are added when the CRM does not have them; those it already has are left
  as they are (they were imported from the same lists).
"""
import collections, csv, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import common as c
import build_dataset as bd

OUT = sys.argv[1] if __name__ == "__main__" else ""
GROUPS = {"PhD": 9, "MSc": 7, "BSc": 6, "Associate": 5, "Other": 8, "": 10}
if __name__ == "__main__" and "--groups" in sys.argv:
    GROUPS = json.loads(sys.argv[sys.argv.index("--groups") + 1])
MARK = "[مدارک دانشجو"            # start of the notes block (plain letters only: Vtiger HTML-encodes dashes and ZWNJ)
MARK_LINE = "[مدارک دانشجو - درون ریزی اسناد]"
LEVEL_FA = c.LEVEL_FA
NATION = {"عراق": "iraq", "سوریه": "syria", "افغانستان": "Afghanistan"}      # values the CRM picklist already uses

NEW_FIELDS = [
    ("وضعیت و مدارک دانشجو", [
        ("status_summary", "وضعیت فعلی دانشجو", "text"),
        ("current_university", "دانشگاه فعلی", "text"),
        ("intake_term", "ترم ورود (مهر/بهمن)", "picklist", ["مهر", "بهمن"]),
        ("intake_year", "سال ورود", "text"),
        ("stage", "مرحله پرونده", "picklist", ["متقاضی", "درخواست ثبت شده", "پذیرش صادر شده", "پذیرش اولیه", "پذیرش نهایی", "ثبت‌نام شده", "رد شده"]),
        ("level_source", "منبع تعیین مقطع", "text"),
        ("applications", "سوابق درخواست‌ها", "long"),
        ("docs_complete", "وضعیت مدارک", "picklist", ["کامل", "ناقص", "بدون مدرک"]),
        ("docs_count", "تعداد مدارک", "text"),
        ("docs_types", "انواع مدارک موجود", "long"),
        ("docs_missing", "مدارک ناموجود", "text"),
        ("docs_folder", "پوشه مدارک (منبع)", "long"),
        ("data_source", "منبع داده", "text"),
    ]),
    ("هویت و گذرنامه", [
        ("mother_name", "نام مادر", "text"),
        ("birth_place", "محل تولد", "text"),
        ("religion", "دین", "text"),
        ("passport_expiry", "تاریخ انقضای گذرنامه", "date"),
        ("passport_issue", "تاریخ صدور گذرنامه", "date"),
        ("passport_status", "وضعیت گذرنامه", "picklist", ["معتبر", "رو به انقضا (کمتر از ۶ ماه)", "منقضی"]),
        ("other_passports", "گذرنامه‌های دیگر", "text"),
        ("agent_contact", "تماس معقب (شماره/ایمیل مشترک)", "text"),
    ]),
    ("سوابق تحصیلی و شغلی", [
        ("prev_level", "آخرین مدرک تحصیلی", "picklist", ["Diploma", "Associate", "BSc", "MSc", "PhD"]),
        ("prev_field", "رشته مدرک قبلی", "text"),
        ("prev_university", "دانشگاه مدرک قبلی", "text"),
        ("prev_gpa", "معدل مدرک قبلی", "text"),
        ("prev_year", "سال فارغ‌التحصیلی", "text"),
        ("gpa_bsc", "معدل کارشناسی", "text"),
        ("gpa_msc", "معدل کارشناسی ارشد", "text"),
        ("job_status", "وضعیت شغلی", "picklist", ["شاغل", "غیرشاغل", "نامشخص"]),
        ("job", "شغل", "text"),
        ("job_address", "محل کار", "text"),
        ("father_job", "شغل پدر", "text"),
        ("finance", "منبع تأمین هزینه تحصیل", "text"),
        ("siblings", "تعداد خواهر و برادر", "text"),
        ("children", "تعداد فرزندان", "text"),
        ("iran_travel", "سابقه سفر به ایران", "text"),
        ("other_applications", "درخواست هم‌زمان در دانشگاه‌های دیگر", "text"),
    ]),
]
FOLDER = {   # document type -> Documents folder
    "passport": "گذرنامه", "national_id": "گذرنامه", "photo": "عکس پرسنلی", "cv": "رزومه و فرم‌ها", "application_form": "رزومه و فرم‌ها",
    "application_form_sheet": "رزومه و فرم‌ها", "degree_certificate": "مدارک تحصیلی", "transcript": "مدارک تحصیلی",
    "master_degree": "مدارک تحصیلی", "bachelor_degree": "مدارک تحصیلی", "phd_degree": "مدارک تحصیلی", "diploma": "مدارک تحصیلی",
    "language_certificate": "مدارک تحصیلی", "recommendation": "مدارک تحصیلی", "visa": "ویزا", "admission": "پذیرش و ثبت‌نام",
    "final_admission": "پذیرش و ثبت‌نام", "initial_admission": "پذیرش و ثبت‌نام", "registration_certificate": "پذیرش و ثبت‌نام",
    "application_receipt": "پذیرش و ثبت‌نام", "payment_receipt": "مالی", "proposal": "پژوهشی", "paper": "پژوهشی",
}
OTHER_FOLDER = "سایر مدارک دانشجو"
MIME = {".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".jfif": "image/jpeg", ".png": "image/png", ".pdf": "application/pdf",
        ".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document", ".doc": "application/msword",
        ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", ".xls": "application/vnd.ms-excel",
        ".rar": "application/x-rar-compressed", ".zip": "application/zip"}
NOT_JOB = re.compile(r"^(_|-|no|none|free|آزاد|ندارد|student|دانشجو|housewife|خانه ?دار|unemployed|بیکار)?$", re.I)


def clip(v, n=250):
    v = c.label(v)
    return v if len(v) <= n else v[:n - 1] + "…"


def split_name(s):
    if s.get("surname") and s.get("first_name"):
        return c.label(s["first_name"]), c.label(s["surname"])
    parts = s["name"].split()
    if len(parts) <= 1:
        return "", s["name"] or ("دارنده گذرنامه " + s["passports"][0]["number"] if s["passports"] else "بدون نام")
    return parts[0], " ".join(parts[1:])


def status_summary(s):
    lvl = LEVEL_FA.get(s.get("level", ""), "")
    intake = " ".join(x for x in (s.get("intake_term", ""), s.get("intake_year", "")) if x)
    who = "دانشجوی" if (s["documents"] or s.get("stage") == "ثبت‌نام شده") else "متقاضی"
    txt = " ".join(x for x in (who, lvl) if x)
    if intake:
        txt += " ورودی " + intake
    if s.get("university"):
        txt += " – " + s["university"]
    return txt if (lvl or intake or s.get("university")) else ""


def applications_text(s, crm):
    out = []
    for a in s.get("applications", []):
        bits = [a["university"], " ".join(x for x in (a.get("term", ""), a.get("year", "")) if x), LEVEL_FA.get(a.get("level", ""), ""), a.get("major", "")]
        out.append(" | ".join(b for b in bits if b))
    fa = s.get("form_apply", {})
    if fa.get("university_latin") or fa.get("major_latin"):
        out.append("فرم/رسید درخواست: " + " | ".join(b for b in (fa.get("university_latin", ""), fa.get("major_latin", ""), fa.get("date", ""), fa.get("status", "")) if b))
    if crm.get("contactid") and (crm.get("university") or crm.get("level")):
        old = " | ".join(b for b in (crm.get("university", ""), LEVEL_FA.get(crm.get("level", ""), crm.get("level", "")), crm.get("major", "")) if b)
        if old and old not in out:
            out.append("پیش از این در CRM: " + old)
    return "\n".join(out)


def description(s):
    L = [MARK_LINE]
    if s.get("name_variants"):
        L.append("نام‌های دیگر در پوشه‌ها: " + "، ".join(s["name_variants"]))
    for p in s["passports"]:
        L.append("گذرنامه: " + p["number"] + (" - انقضا " + p["expiry"] if p.get("expiry") else "") + (" - صدور " + p["issue"] if p.get("issue") else "")
                 + (" (خوانده‌شده از تصویر، رقم کنترل درست)" if p.get("valid") else ""))
    if s.get("passports_unverified"):
        L.append("شماره گذرنامه خوانده‌شده از تصویر که رقم کنترل آن جور نیست (بازبینی شود): " + "، ".join(s["passports_unverified"]))
    for e in s.get("education_history", []):
        L.append("سابقه تحصیلی (فرم): " + " | ".join(f"{k}={v}" for k, v in e.items() if k != "rank"))
    for k, fa in (("iran_travel", "سابقه سفر به ایران"), ("iran_contacts", "آشنا در ایران"), ("father_job", "شغل پدر"), ("finance", "تأمین هزینه"),
                  ("other_applications", "درخواست در دانشگاه‌های دیگر"), ("job_description", "شرح شغل"), ("address", "نشانی"), ("children", "فرزندان")):
        if s.get(k):
            L.append(f"{fa}: {s[k]}")
    if s.get("agent_contacts"):
        L.append("تماس مشترک با دیگران (معقب/دفتر): " + "، ".join(s["agent_contacts"][:6]))
    for k, vals in sorted(s.get("list", {}).items()):
        if k in ("full_name", "surname", "passport", "father_name"):
            continue
        L.append(f"فهرست اکسل - {k}: " + " ؛ ".join(vals[:4]))
    if s.get("list_sources"):
        L.append("فهرست‌های منبع: " + " ؛ ".join(s["list_sources"][:8]))
    for a in s.get("applications", []):
        for f in a.get("folders", [])[:3]:
            L.append("پوشه مدارک: " + f)
    return "\n".join(L)


def main():
    S = json.load(open(os.path.join(OUT, "students.json"), encoding="utf-8"))
    stage_dir = os.path.join(OUT, "stage", "files")
    os.makedirs(stage_dir, exist_ok=True)
    out, review = [], []
    picks = collections.defaultdict(set)
    skipped_known = 0
    for s in S:
        crm = s.get("crm", {"how": "new"})
        if s.get("list_only") and crm.get("contactid"):
            skipped_known += 1                       # already in the CRM from the same lists
            continue
        lvl = s.get("level", "")
        owner = GROUPS.get(lvl, GROUPS.get("", 10))
        main_p = s["passports"][0] if s["passports"] else {}
        first, last = split_name(s)
        nat = NATION.get(s.get("nationality", ""), s.get("nationality", ""))
        lst = s.get("list", {})

        def l1(k):
            return (lst.get(k) or [""])[0]

        job = s.get("job", "")
        job_status = "شاغل" if job and not NOT_JOB.match(job.strip()) else "نامشخص"
        sie = {
            "mobile": s.get("mobile", ""), "email": s.get("email", ""), "birthday": s.get("birth_date", ""),
            "otherphone": (s.get("other_phones") or [""])[0],
            "cf_ss_passport": main_p.get("number", ""), "cf_ss_father_name": clip(s.get("father_name", "")),
            "cf_ss_full_name_native": clip(s["name"]), "cf_ss_full_name_latin": clip(s.get("name_latin", "")),
            "cf_ss_gender": s.get("gender", ""), "cf_ss_nationality": nat, "cf_ss_marital": clip(s.get("marital", "")),
            "cf_ss_tracking_code": clip(l1("tracking_code")), "cf_ss_broker": clip(l1("broker")), "cf_ss_student_no": clip(l1("student_no")),
            "cf_ss_admission_status": clip(l1("admission_status") or l1("system_status")), "cf_ss_gpa": clip(l1("gpa") or s.get("prev_gpa", "")),
            "cf_ss_specialization": clip(l1("specialization")), "cf_ss_faculty": clip(l1("faculty") or s.get("form_apply", {}).get("faculty", "")),
            "cf_ss_row_code": clip(l1("row_code")), "cf_ss_notes": l1("notes"), "mailingstreet": clip(s.get("address", "")),
            "mailingcity": clip(s.get("birth_city", "")) if not s.get("address") else "",
        }
        intake = " ".join(x for x in (s.get("intake_term", ""), s.get("intake_year", "")) if x)
        kinds = collections.Counter(d["label"] for d in s["documents"])
        st = {
            "cf_ss_status_summary": clip(status_summary(s)), "cf_ss_current_university": s.get("university", ""),
            "cf_ss_intake_term": s.get("intake_term", ""), "cf_ss_intake_year": s.get("intake_year", ""), "cf_ss_stage": s.get("stage", ""),
            "cf_ss_level_source": {"folder": "پوشه", "form": "فرم درخواست", "list": "فهرست اکسل", "documents": "از روی مدارک (یک مقطع بالاتر از آخرین مدرک)"}.get(s.get("level_source", ""), ""),
            "cf_ss_applications": applications_text(s, crm),
            "cf_ss_docs_complete": ("کامل" if s.get("docs_complete") else "ناقص") if s["documents"] else "بدون مدرک",
            "cf_ss_docs_count": str(len(s["documents"])) if s["documents"] else "",
            "cf_ss_docs_types": "، ".join(f"{k} ({n})" if n > 1 else k for k, n in kinds.most_common()),
            "cf_ss_docs_missing": "، ".join(s.get("docs_missing", [])) if s["documents"] else "",
            "cf_ss_docs_folder": "\n".join(f for a in s.get("applications", []) for f in a.get("folders", [])[:3])[:1500],
            "cf_ss_data_source": "اسناد دانشجو" if s["documents"] else "فهرست اکسل (پوشه اسناد)",
            "cf_ss_mother_name": clip(s.get("mother_name", "")), "cf_ss_birth_place": clip(s.get("birth_city", "")), "cf_ss_religion": clip(s.get("religion", "")),
            "cf_ss_passport_expiry": main_p.get("expiry", ""), "cf_ss_passport_issue": main_p.get("issue", ""), "cf_ss_passport_status": s.get("passport_status", ""),
            "cf_ss_other_passports": "، ".join(p["number"] for p in s["passports"][1:4]),
            "cf_ss_agent_contact": clip("، ".join(s.get("agent_contacts", [])[:5])),
            "cf_ss_prev_level": s.get("prev_level", ""), "cf_ss_prev_field": clip(s.get("prev_field", "")), "cf_ss_prev_university": clip(s.get("prev_university", "")),
            "cf_ss_prev_gpa": clip(s.get("prev_gpa", "")), "cf_ss_prev_year": s.get("prev_year", ""), "cf_ss_gpa_bsc": s.get("gpa_bsc", ""), "cf_ss_gpa_msc": s.get("gpa_msc", ""),
            "cf_ss_job_status": job_status if (job or s["documents"]) else "", "cf_ss_job": clip(job), "cf_ss_job_address": clip(s.get("job_address", "")),
            "cf_ss_father_job": clip(s.get("father_job", "")), "cf_ss_finance": clip(s.get("finance", "")), "cf_ss_siblings": clip(s.get("siblings", "")),
            "cf_ss_children": clip(s.get("children", "")), "cf_ss_iran_travel": clip(s.get("iran_travel", "")),
            "cf_ss_other_applications": clip(s.get("other_applications", "")),
        }
        # the current application replaces university / level / major / entry term when a folder states the intake;
        # otherwise those are only filled when empty
        cur = {"cf_ss_university": s.get("university", ""), "cf_ss_level": lvl, "cf_ss_major": clip(s.get("major", "")), "cf_ss_entry_term": intake}
        if s.get("applications") and s.get("intake_year"):
            st.update({k: v for k, v in cur.items() if v})
        else:
            sie.update(cur)
        if crm.get("contactid") and re.match(r"^(Unnamed|Unknown)\b", crm.get("name", "")) and s["name"]:
            st["firstname"] = first; st["lastname"] = last        # a phone-only contact of the old lists gets its name
        st = {k: v for k, v in st.items() if v != ""}
        sie = {k: v for k, v in sie.items() if v not in ("", None)}
        for k in ("cf_ss_university", "cf_ss_major", "cf_ss_nationality", "cf_ss_level", "cf_ss_gender"):
            v = st.get(k) or sie.get(k)
            if v:
                picks[k].add(v)

        tags = ["منبع: اسناد دانشجو" if s["documents"] else "منبع: فهرست اکسل اسناد"]
        if lvl:
            tags.append("مقطع: " + LEVEL_FA.get(lvl, lvl))
        if s.get("major"):
            tags.append("رشته: " + s["major"])
        if s.get("university"):
            tags.append("دانشگاه: " + s["university"].replace("دانشگاه ", ""))
        if intake:
            tags.append("ورودی: " + intake)
        if s.get("stage"):
            tags.append("مرحله: " + s["stage"])
        if s["documents"]:
            tags.append("مدارک: " + ("کامل" if s.get("docs_complete") else "ناقص"))
            for m in s.get("docs_missing", []):
                tags.append("ندارد: " + m)
        if s.get("passport_status"):
            tags.append("گذرنامه: " + s["passport_status"].split(" (")[0])
        if s.get("prev_level"):
            tags.append("مدرک قبلی: " + LEVEL_FA.get(s["prev_level"], s["prev_level"]))
        if job_status == "شاغل":
            tags.append("وضعیت شغلی: شاغل")
        if len({a["university"] for a in s.get("applications", [])}) >= 2:
            tags.append("چند دانشگاه")
        if s.get("nationality"):
            tags.append("ملیت: " + s["nationality"])
        if "incomplete" in s.get("flags", []):
            tags.append("پوشه: ناقصی")
        if "plus" in s.get("flags", []):
            tags.append("پوشه: علامت +")
        prefixes = ["مقطع:", "رشته:", "دانشگاه:", "ورودی:", "مرحله:", "مدارک:", "ندارد:", "گذرنامه:", "مدرک قبلی:", "وضعیت شغلی:"]

        docs = []
        n_kind = collections.Counter()
        for d in sorted(s["documents"], key=lambda d: (d["label"], d["rel"])):
            if not d.get("path") or not os.path.exists(d["path"]):
                continue
            ext = ".jpg" if d["ext"] == ".jfif" else d["ext"]
            fname = d["md5"] + ext
            link = os.path.join(stage_dir, fname)
            if not os.path.lexists(link):
                os.symlink(os.path.abspath(d["path"]), link)
            n_kind[d["label"]] += 1
            i = n_kind[d["label"]]
            title = f"{d['label']} – {s['name'] or main_p.get('number', '')}" + (f" ({i})" if i > 1 else "")
            dtags = [d["label"], "مدارک دانشجو"]
            if d.get("university"):
                dtags.append("دانشگاه: " + d["university"].replace("دانشگاه ", ""))
            di = " ".join(x for x in (d.get("term", ""), d.get("year", "")) if x)
            if di:
                dtags.append("ورودی: " + di)
            desc = "منبع: " + d["rel"]
            if d.get("archive"):
                desc += "\nاز بسته فشرده: " + d["archive"]
            if d.get("by_batch"):
                desc += "\nتخصیص به این شخص بر اساس هم‌زمانی دریافت با مدرک هویتی او (نام پرونده شخص را نشان نمی‌دهد)"
            safe = re.sub(r"[\\/:*?\"<>|]+", " ", f"{d['label']} - {s['name'] or main_p.get('number', '')} {i}").strip()[:120]
            docs.append({"md5": d["md5"], "file": fname, "name": safe + ext, "mime": MIME.get(d["ext"], "application/octet-stream"),
                         "title": title[:200], "folder": FOLDER.get(d["kind"], OTHER_FOLDER), "tags": dtags, "description": desc})

        rec = {"sid": s["sid"], "contactid": crm.get("contactid"), "owner": owner, "set": st, "set_if_empty": sie, "tags": tags, "tag_prefixes": prefixes,
               "documents": docs, "append_description": description(s), "description_marker": MARK}
        if not crm.get("contactid"):
            rec["create"] = dict(sie, firstname=first, lastname=last, **{k: v for k, v in st.items()})      # the notes are appended after creation
        out.append(rec)
        review.append({"sid": s["sid"], "name": s["name"], "passport": main_p.get("number", ""), "match": crm["how"], "crm_contact": crm.get("contact_no", ""),
                       "crm_name": crm.get("name", ""), "university": s.get("university", ""), "level": lvl, "level_source": s.get("level_source", ""),
                       "major": s.get("major", ""), "intake": intake, "stage": s.get("stage", ""), "documents": len(docs),
                       "missing": "، ".join(s.get("docs_missing", [])), "status": st.get("cf_ss_status_summary", ""), "list_only": int(bool(s.get("list_only")))})

    blocks = [{"label": b, "fields": [{"name": "cf_ss_" + f[0], "label": f[1], "kind": f[2], "values": f[3] if len(f) > 3 else []} for f in fs]} for b, fs in NEW_FIELDS]
    folders = sorted(set(FOLDER.values()) | {OTHER_FOLDER})
    data = {"blocks": blocks, "picklists": {k: sorted(v) for k, v in picks.items()}, "folders": folders, "students": out}
    json.dump(data, open(os.path.join(OUT, "import.json"), "w", encoding="utf-8"), ensure_ascii=False)
    with open(os.path.join(OUT, "review.csv"), "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=list(review[0].keys())); w.writeheader(); w.writerows(review)
    nd = sum(len(r["documents"]) for r in out)
    files = {d["file"] for r in out for d in r["documents"]}
    size = sum(os.path.getsize(os.path.join(stage_dir, f)) for f in files)
    print(f"students {len(out)}: new {sum(1 for r in out if not r['contactid'])}, existing {sum(1 for r in out if r['contactid'])}; "
          f"list-only people already in the CRM left alone: {skipped_known}")
    print(f"documents {nd} ({len(files)} unique files, {size / 1e6:.0f} MB); new fields {sum(len(b['fields']) for b in blocks)}; folders {len(folders)}")
    print("match:", collections.Counter(r["match"] for r in review))


if __name__ == "__main__":
    main()
