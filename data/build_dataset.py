#!/usr/bin/env python3
"""
SamenStudent – Excel -> normalized dataset builder.

Reads every .xlsx in the xls folder (all sheets, tolerant of broken styles via
python-calamine), maps each sheet layout to canonical student fields, drops
byte-identical duplicate sheets, merges rows that describe the same person
(passport number, else name, else phone) and writes:

  output/dataset.json      – merged contacts + derived tasks/events (importer input)
  output/students.csv      – flat, human-checkable view of every contact
  output/raw_archive.json  – every source row exactly as read (nothing is lost)
  output/report.txt        – counts, merges, conflicts, anomalies

Usage:  python3 build_dataset.py <xls_dir> <output_dir>
Deps :  pip install python-calamine
"""
import csv
import glob
import hashlib
import json
import os
import re
import sys
from collections import Counter, OrderedDict, defaultdict

from python_calamine import CalamineWorkbook

# --------------------------------------------------------------------------
# text normalisation
# --------------------------------------------------------------------------
_TR = str.maketrans({
    "ي": "ی", "ى": "ی", "ك": "ک", "ۀ": "ه", "ة": "ه", "ؤ": "و", "أ": "ا", "إ": "ا",
    "‌": " ", "‏": "", "‎": "", " ": " ",
    "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4", "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
    "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
})


def norm(s):
    """Normalise Arabic/Persian letter variants + whitespace (used for matching & labels)."""
    if s is None:
        return ""
    s = str(s).translate(_TR).replace("\u200d", "")   # stray zero-width JOINER (ZWNJ \u200c is kept)
    return re.sub(r"\s+", " ", s).strip()


def cell(v):
    """Cell -> clean string (floats that are whole numbers lose the .0)."""
    if v is None:
        return ""
    if isinstance(v, float):
        if v != v:
            return ""
        if v == int(v):
            return str(int(v))
        return repr(v)
    return norm(v) if isinstance(v, str) else str(v).strip()


# --------------------------------------------------------------------------
# header -> canonical field mapping
# --------------------------------------------------------------------------
# Each canonical field => list of normalised header texts (exact match after norm()).
H = {
    "full_name":      ["نام", "نام کامل", "نام و نام خانوادگی", "نام دانشجو", "الاسم", "الاسم", "نام کامل عربی", "نام دانشجو غیر ایرانی"],
    "first_name":     [],  # decided per-sheet (split layouts)
    "father_name":    ["نام پدر", "اسم پدر", "پدر"],
    "surname":        ["لقب", "نام خانوادگی", "نام خانوادگي"],
    "full_name_latin": ["full name نام کامل لاتین", "full name\nنام کامل لاتین"],
    "gender":         ["جنسیت", "جنس"],
    "passport":       ["شماره پاسپورت", "شماره گذرنامه/ کارت هویت", "ش پ", "ش.پاسپورت", "پاسپورت",
                       "شماره پاسپورت بدون صفر اول", "شماره واس آپ"],
    "phone":          ["شماره واتساپ", "شماره واتس آپ", "شماره واتس اپ", "شماره واتس", "واتساپ",
                       "شماره تلفن", "شماره تماس", "شماره همراه بدون صفر اول", "رقم الواتساب"],
    "email":          ["ایمیل"],
    "student_no":     ["کد دانشجویی", "شماره دانشجو", "شماره دانشجویی", "شماره ىانشجويي"],
    "tracking_code":  ["کد سااورگ", "کد پیگیری سااورگ", "کد سائورگ", "کد رهگیری"],
    "row_code":       ["شماره کد"],
    "university":     ["دانشگاه", "نام دانشگاه", "نام دانشگاه محل تحصیل"],
    "faculty":        ["دانشکده"],
    "major":          ["رشته", "نام رشته تحصیلی", "رشته تحصیلی", "رشته قبولی", "القسم"],
    "specialization": ["گرایش"],
    "level":          ["مقطع", "مقطع تحصیلی"],
    "gpa":            ["معدل"],
    "nationality":    ["ملیت"],
    "visa_city":      ["شهر محل اخذ ویزا"],
    "marital":        ["متاهل(نام دانشگاه)-مجرد"],
    "broker":         ["نام معقب", "معقب", "کارگزار"],
    "entry_term":     ["ترم ورود", "نیمسال ورود"],
    "entry_type":     ["نحوه ورود"],
    "study_start":    ["تاریخ شروع به تحصیل"],
    # workflow
    "docs_to_carrier":     ["تحویل به ناقل"],
    "docs_to_translator":  ["تحویل دارالترجمه"],
    "docs_status":         [],  # 'وضعیت' only in the documents sheet (sheet override)
    "docs_to_university":  ["تحویل دانشگاه", "هزینه تحویل مدارک"],
    "sent_to":             ["ارسال به مهندس یا دانشگاه"],
    "changes":             ["تغییرات"],
    "admission_status":    ["پذیرش اولیه ها", "وضعیت پذیرش", "پذیرش", "صدور پذیرش"],
    "changes_after_admission": ["تغییرات بعد از پذیرش اولیه ها"],
    "invoice_sent":        ["ارسال فاکتور"],
    "receipt_no":          ["شماره رسید", "رسید"],
    "employment_folder":   ["پوشه"],
    # payments
    "pay_tuition":         ["پرداختی شهریه", "میزان پرداخت شهریه به موسسه"],
    "pay_university_tuition": ["پرداخت شهریه به دانشگاه"],
    "pay_persian":         ["پرداختی زبان فارسی", "شهریه زبان فارسی"],
    "persian_course":      ["کورس زبان فارسی"],
    "pay_docs":            ["پرداختی تایید و ترجمه مدارک"],
    "pay_gpa":             ["پرداختی معدل"],
    "pay_paid_univ":       ["پرداختی دانشگاه های پولی"],
    "pay_visa":            ["پرداختی ویزا"],
    "pay_photoshop":       ["پرداختی فتوشاپ"],
    "pay_univ_services":   ["پرداختی خدمات دانشگاهی"],
    "pay_generic":         ["پرداختی", "وضعیت پرداخت", "مبلغ واربزی"],
    "lang_fee":            ["اللغة"],
    "translator_fee":      ["المترجم"],
    "list_label":          ["لیست علی شاکر"],
    "notes":               ["سایر توضیحات", "توضیحات"],
    "faculty_code":        ["کد دانشکده"],
    "major_code":          ["کد رشته"],
    "level_code":          ["کد مقطع"],
    "course_period":       ["دوره"],
    "edu_mode":            ["شیوه آموزشی"],
    "overall_status":      ["وضعیت کلی"],
    "student_status":      ["وضعیت دانشجو"],
    "edu_rule":            ["قانون آموزشی"],
}
# normalise lookup
_H_LOOKUP = {}
for field, names in H.items():
    for n in names:
        _H_LOOKUP[norm(n).lower()] = field

SKIP_HEADERS = {"ردیف", "رىيف", "رديف", "#"}


def map_header(h):
    k = norm(h).lower()
    return _H_LOOKUP.get(k)


# Sheet-specific knowledge (default university, group label, tags, overrides)
# key = normalised sheet name or "file|sheet" for precision
SHEETS = {
    "contacts1.xlsx|Sheet1":        dict(group_from_marker=True, source_group="Registration lists", univ=None),
    "مدارک":                        dict(source_group="Documents handling", status_header="docs_status", univ=None),
    "افغانستان":                    dict(source_group="Afghanistan students", nationality="Afghanistan", split=True),
    "ثبت نام ارومیه":               dict(source_group="Urmia registration", univ="دانشگاه ارومیه"),
    "ارومیه جدید":                  dict(source_group="Urmia new admissions", univ="دانشگاه ارومیه"),
    "اشتغال به تحصیل":              dict(source_group="Employment-study certificates", status_header="employment_status"),
    "کل دانشجویان":                 dict(source_group="All students master list", split=True),
    "دانشگاه قم":                   dict(source_group="Qom University list", univ="دانشگاه قم", split=True),
    "ادیان":                        dict(source_group="University of Religions", univ="دانشگاه ادیان و مذاهب"),
    "contacts3.xlsx|Sheet1":        dict(source_group="Withdrawn students", withdrawn=True),
    "contacts4.xlsx|Sheet1":        dict(source_group="Withdrawn students", withdrawn=True),
    "نام دانشجو غیر ایرانی":        dict(source_group="Non-Iranian students form"),
    "contacts7.xlsx|Sheet1":        dict(source_group="Phone-only list A", phone_only=True),
    "contacts12.xlsx|Sheet1":       dict(source_group="Phone-only list B", phone_only=True),
    "EVA":                          dict(source_group="EVA list", univ=None),
    "contacts9.xlsx|Sheet1":        dict(source_group="Shahrekord/EVA batch", split=True),
    "زبان فارسی":                   dict(source_group="Persian language course", persian_course=True),
    "شهرکرد":                       dict(source_group="Shahrekord University", univ="دانشگاه شهرکرد", split=True),
    "جدید قم":                      dict(source_group="Qom new", univ="دانشگاه قم", split=True),
    "لرستان":                       dict(source_group="Lorestan University", univ="دانشگاه لرستان", split=True),
    "جدید لرستان":                  dict(source_group="Lorestan new", univ="دانشگاه لرستان", split=True),
    "قم":                           dict(source_group="Qom University", univ="دانشگاه قم", split=True),
    "contacts15.xlsx|Sheet1":       dict(source_group="Urmia Tech students", univ="دانشگاه صنعتی ارومیه"),
    "contacts16.xlsx|Sheet1":       dict(source_group="Urmia Tech students", univ="دانشگاه صنعتی ارومیه"),
    "contacts17.xlsx|Sheet1":       dict(source_group="Payments list"),
    "contacts18.xlsx|Sheet1":       dict(source_group="Quran & Hadith PhD", split=True),
    "contacts19.xlsx|Sheet1":       dict(source_group="Quran & Hadith PhD", split=True),
    "contacts20.xlsx|Sheet1":       dict(source_group="Orhan broker transfers"),
    "contacts21.xlsx|Sheet1":       dict(source_group="Orhan broker transfers"),
    "contacts22.xlsx|Sheet1":       dict(source_group="Orhan broker transfers"),
    "contacts23.xlsx|Sheet1":       dict(source_group="Orhan broker transfers"),
}
# sheets where 'نام' is a FIRST name and surname/father come from other columns
# (decided automatically too: if a surname-type header exists alongside 'نام')


def sheet_conf(fname, sheet):
    return SHEETS.get(f"{fname}|{sheet}") or SHEETS.get(norm(sheet)) or {}


# --------------------------------------------------------------------------
# value normalisers
# --------------------------------------------------------------------------
UNIV_ALIASES = {
    "ارومیه": "دانشگاه ارومیه",
    "شهرکرد": "دانشگاه شهرکرد",
    "صنعتی قم": "دانشگاه صنعتی قم",
    "دلنشگاه صنعتی ارومیه": "دانشگاه صنعتی ارومیه",
    "شهید مدنی تبریز": "دانشگاه شهید مدنی تبریز",
    "دانشگاه شهید معدنی": "دانشگاه شهید مدنی تبریز",
    "رازی": "دانشگاه رازی",
}


def norm_university(v):
    v = norm(v).replace("ازاد", "آزاد")
    if not v or re.fullmatch(r"[?؟\s]+", v):
        return ""
    if v.startswith("دانشگاه ارومیه") or v.startswith("ارومیه"):
        return "دانشگاه ارومیه"
    if v in ("دانشگاه ادیان", "دانشگاه ادیان و مذاهب", "ادیان"):
        return "دانشگاه ادیان و مذاهب"
    if v in ("دانشگاه مدرس", "دانشگاه تربیت مدرس"):
        return "دانشگاه تربیت مدرس"
    return UNIV_ALIASES.get(v, v)


def norm_level(v):
    v = norm(v)
    if not v:
        return ""
    if "دکتر" in v or v.lower() in ("phd", "ph.d"):
        return "PhD"
    if "ارشد" in v or v.lower() in ("msc", "ma", "master"):
        return "MSc"
    if "کارشناسی" in v or v.lower() in ("bsc", "ba", "bachelor"):
        return "BSc"
    if "کاردانی" in v:
        return "Associate"
    return "Other"


def norm_gender(v):
    v = norm(v)
    if v in ("آقا", "مرد", "پسر", "male", "m"):
        return "Male"
    if v in ("زن", "خانم", "دختر", "female", "f"):
        return "Female"
    return ""


def norm_major(v):
    v = norm(v)
    v = re.sub(r"\s*[-_–]\s*", " - ", v)
    v = re.sub(r"\s+", " ", v).strip(" -")
    return v


def norm_passport(v):
    v = norm(v).replace(" ", "").replace("-", "").upper()
    return v


def passport_key(p):
    """digits-only key (+ letter). Returns (digits, letter)."""
    if not p:
        return "", ""
    letters = re.sub(r"[^A-Z]", "", p)
    digits = re.sub(r"\D", "", p).lstrip("0")
    return digits, letters[:1]


PASSPORT_RE = re.compile(r"^[A-Za-z]{1,2}\d{5,9}$")


def norm_phone(v):
    """Return (e164-ish, raw_digits). Iraqi 07xxxxxxxxx/7xxxxxxxxx -> +964...; keeps unknowns as +digits."""
    raw = cell(v)
    if not raw:
        return "", ""
    s = norm(raw)
    plus = s.strip().startswith("+")
    d = re.sub(r"\D", "", s)
    if not d:
        return "", raw
    if d.startswith("00"):
        d = d[2:]
        plus = True
    if plus:
        return "+" + d, raw
    if d.startswith("964") and len(d) >= 12:
        return "+" + d, raw
    if d.startswith("964") and len(d) < 12:
        return "+" + d, raw
    if d.startswith("98") and len(d) >= 11:
        return "+" + d, raw
    if len(d) == 11 and d.startswith("07"):
        return "+964" + d[1:], raw
    if len(d) == 10 and d.startswith("7"):
        return "+964" + d, raw
    if len(d) == 10 and d.startswith("9"):
        return "+98" + d, raw          # Iranian mobile without country code
    if len(d) == 11 and d.startswith("09"):
        return "+98" + d[1:], raw
    return "+" + d if len(d) >= 10 else d, raw   # unknown shape: keep digits, flagged in report


# --------------------------------------------------------------------------
# reading
# --------------------------------------------------------------------------
def read_all(xls_dir):
    files = sorted(glob.glob(os.path.join(xls_dir, "*.xlsx")),
                   key=lambda f: [int(x) if x.isdigit() else x for x in re.split(r"(\d+)", os.path.basename(f))])
    sheets = []
    for f in files:
        wb = CalamineWorkbook.from_path(f)
        for s in wb.sheet_names:
            rows = wb.get_sheet_by_name(s).to_python()
            sheets.append(dict(file=os.path.basename(f), sheet=s, rows=rows))
    return sheets


def content_hash(rows):
    h = hashlib.sha1()
    for r in rows:
        h.update(("\x1f".join(cell(c) for c in r) + "\n").encode("utf-8"))
    return h.hexdigest()


def find_header_row(rows):
    """Header = first row with >=2 non-empty text cells that is not a title row."""
    for i, r in enumerate(rows[:6]):
        vals = [cell(c) for c in r]
        nonempty = [v for v in vals if v]
        if len(nonempty) >= 2 and sum(1 for v in nonempty if not re.fullmatch(r"[\d.+-]+", v)) >= 2:
            return i
    return 0


# --------------------------------------------------------------------------
# row -> record
# --------------------------------------------------------------------------
def parse_sheet(sh):
    fname, sname, rows = sh["file"], sh["sheet"], sh["rows"]
    conf = sheet_conf(fname, sname)
    recs, anomalies = [], []
    if not any(any(cell(c) for c in r) for r in rows):
        return recs, anomalies
    hi = find_header_row(rows)
    headers = [norm(c) for c in rows[hi]]
    # trim title row detection (contacts3/4: title row above header)
    ncols = max(len(r) for r in rows)
    headers += [""] * (ncols - len(headers))
    # canonical field per column
    colmap = {}
    for ci, h in enumerate(headers):
        fld = map_header(h) if h else None
        if h in SKIP_HEADERS:
            fld = "_rownum"
        colmap[ci] = fld
    hset = {norm(h).lower() for h in headers}
    # special per-sheet header overrides
    for ci, h in enumerate(headers):
        if h == "وضعیت":
            colmap[ci] = conf.get("status_header", "admission_status")
        if h == "اللغة":
            colmap[ci] = "lang_fee"
        if h == "المترجم":
            colmap[ci] = "translator_fee"
    # 'full_name' vs split layouts: if a surname-type header is present, 'نام' = first name
    has_surname = any(colmap[c] == "surname" for c in colmap)
    for ci, h in enumerate(headers):
        if norm(h) in ("نام", "نام دانشجو") and has_surname:
            colmap[ci] = "first_name"
    # blank-header columns -> heuristics
    for ci, h in enumerate(headers):
        if h:
            continue
        sample = [cell(r[ci]) for r in rows[hi + 1: hi + 40] if ci < len(r) and cell(r[ci])]
        if not sample:
            continue
        # first blank column with names (e.g. 'کل دانشجویان', 'ثبت نام ارومیه') -> name column
        if ci == 0 and sname in ("کل دانشجویان", "ثبت نام ارومیه") and not any(colmap[c] in ("full_name", "first_name") for c in colmap):
            colmap[ci] = "first_name" if sname == "کل دانشجویان" else "full_name"
        elif sname == "افغانستان" and ci == 2:
            colmap[ci] = "father_name"
        elif sname == "کل دانشجویان" and ci == 2:
            colmap[ci] = None
        elif sname in ("جدید قم", "جدید لرستان") and ci == 0:
            colmap[ci] = "_rownum"
        elif sname == "مدارک" and ci == 0:
            colmap[ci] = "_rownum"
        elif sname == "اشتغال به تحصیل" and ci == 0:
            colmap[ci] = "_rownum"
        elif sname == "ارومیه جدید":
            colmap[ci] = None
    # a header literally 'ردیف' etc skip; contacts with 'نام' + 'نام پدر' + 'لقب' handled via has_surname
    # contacts7/12 style: only (ردیف, شماره تلفن)
    phone_only = conf.get("phone_only") or (
        set(f for f in colmap.values() if f and f != "_rownum") <= {"phone"})
    group_label = ""
    last_group = ""
    for ri in range(hi + 1, len(rows)):
        r = rows[ri]
        vals = [cell(c) for c in r] + [""] * (ncols - len(r))
        if not any(vals):
            continue
        nonempty = [(i, v) for i, v in enumerate(vals) if v]
        # group marker row (e.g. 'گروه 11'): a single non-empty cell, no number-like meaning
        if conf.get("group_from_marker") and len(nonempty) == 1 and not re.fullmatch(r"[\d.]+", nonempty[0][1]):
            last_group = nonempty[0][1]
            continue
        raw = OrderedDict()
        for ci, v in enumerate(vals):
            if v == "":
                continue
            key = headers[ci] if headers[ci] else f"ستون_{ci + 1}"
            if key in raw:
                key = f"{key}_{ci + 1}"
            raw[key] = v
        rec = defaultdict(str)
        notes = []
        for ci, v in enumerate(vals):
            fld = colmap.get(ci)
            if not v or not fld or fld == "_rownum":
                continue
            if fld in rec and rec[fld] and rec[fld] != v:
                # same field twice in a row (e.g. 2 phone columns) -> keep both
                rec[fld] = rec[fld] + " | " + v
            else:
                rec[fld] = v
        # contacts17: 'شماره واس آپ' is really the passport; phone cell that looks like passport
        ph = rec.get("phone", "")
        if ph and PASSPORT_RE.match(ph.replace(" ", "")) and not rec.get("passport"):
            rec["passport"] = ph
            rec["phone"] = ""
        # last-chance: some sheets put passport in surname? no. skip.
        rec = dict(rec)
        rec["_src"] = dict(file=fname, sheet=sname, row=ri + 1, group=last_group or conf.get("source_group", ""), raw=raw)
        rec["_conf"] = conf
        recs.append(rec)
    return recs, anomalies


# --------------------------------------------------------------------------
# merge
# --------------------------------------------------------------------------
SCALAR_FIELDS = [
    "full_name", "first_name", "father_name", "surname", "full_name_latin", "gender", "passport", "phone", "email",
    "student_no", "tracking_code", "row_code", "university", "faculty", "major", "specialization", "level", "gpa",
    "nationality", "visa_city", "marital", "broker", "entry_term", "entry_type", "study_start",
    "docs_to_carrier", "docs_to_translator", "docs_status", "docs_to_university", "sent_to", "changes",
    "admission_status", "changes_after_admission", "invoice_sent", "receipt_no", "employment_folder",
    "employment_status", "pay_tuition", "pay_university_tuition", "pay_persian", "persian_course", "pay_docs",
    "pay_gpa", "pay_paid_univ", "pay_visa", "pay_photoshop", "pay_univ_services", "pay_generic", "lang_fee",
    "translator_fee", "list_label", "notes", "faculty_code", "major_code", "level_code", "course_period",
    "edu_mode", "overall_status", "student_status", "edu_rule",
]
# fields whose differing values from different sources are not conflicts worth flagging
MULTI_OK = {"notes", "changes", "changes_after_admission", "docs_status", "employment_status", "pay_generic"}

SRC_PRIORITY = {  # richer sheets first so their values win on merge
    "کل دانشجویان": 0, "ارومیه جدید": 1, "افغانستان": 2, "ثبت نام ارومیه": 3, "دانشگاه قم": 4, "لرستان": 5, "قم": 5,
}


def split_name(rec):
    """Return (first, last, father)."""
    full = norm(rec.get("full_name", ""))
    first = norm(rec.get("first_name", ""))
    sur = norm(rec.get("surname", ""))
    father = norm(rec.get("father_name", ""))
    if first or sur:
        if not first and full:
            first = full
        return first, sur, father
    if full:
        parts = full.split(" ")
        if len(parts) == 1:
            return "", parts[0], father
        return parts[0], " ".join(parts[1:]), father
    return "", "", father


def person_key(rec):
    p = norm_passport(rec.get("passport", ""))
    digits, letter = passport_key(p)
    if len(digits) >= 6:
        return ("P", digits), letter
    first, last, _ = split_name(rec)
    n = norm(f"{first} {last}").lower()
    if n and len(n.split(" ")) >= 3:
        return ("N", n), ""
    if n:   # short names are too ambiguous to merge on name alone
        ph0, _ = norm_phone((rec.get("phone", "") or "").split(" | ")[0])
        if ph0:
            return ("NT", n + "|" + re.sub(r"\D", "", ph0)), ""
        return ("NS", n + "|" + rec["_src"]["file"] + "|" + rec["_src"]["sheet"] + "|" + str(rec["_src"]["row"])), ""
    ph, _ = norm_phone(rec.get("phone", ""))
    if ph:
        return ("T", re.sub(r"\D", "", ph)), ""
    return None, ""


def build(xls_dir, out_dir):
    os.makedirs(out_dir, exist_ok=True)
    report = []
    sheets = read_all(xls_dir)
    seen, uniq_sheets, dup_sheets = {}, [], []
    for sh in sheets:
        if not any(any(cell(c) for c in r) for r in sh["rows"]):
            report.append(f"EMPTY SHEET skipped: {sh['file']}|{sh['sheet']}")
            continue
        h = content_hash(sh["rows"])
        if h in seen:
            dup_sheets.append((sh["file"], sh["sheet"], seen[h]))
            continue
        seen[h] = f"{sh['file']}|{sh['sheet']}"
        uniq_sheets.append(sh)
    report.append(f"Sheets read: {len(sheets)}; unique: {len(uniq_sheets)}; identical duplicates dropped: {len(dup_sheets)}")
    for f, s, o in dup_sheets:
        report.append(f"  duplicate sheet {f}|{s}  == {o}")

    all_recs, raw_archive = [], []
    for sh in sheets:   # raw archive keeps EVERYTHING, including duplicates
        raw_archive.append(dict(file=sh["file"], sheet=sh["sheet"],
                                rows=[[cell(c) for c in r] for r in sh["rows"]],
                                duplicate_of=seen.get(content_hash(sh["rows"])) if f"{sh['file']}|{sh['sheet']}" != seen.get(content_hash(sh["rows"])) else None))
    for sh in uniq_sheets:
        recs, _ = parse_sheet(sh)
        all_recs.extend(recs)
    all_recs.sort(key=lambda r: SRC_PRIORITY.get(norm(r["_src"]["sheet"]), 9))

    people = OrderedDict()
    skipped_noid = []
    key_index = {}
    merges = 0
    letter_conflicts = []
    for rec in all_recs:
        key, letter = person_key(rec)
        if key is None:
            skipped_noid.append(f"{rec['_src']['file']}|{rec['_src']['sheet']} row {rec['_src']['row']}")
            continue
        # passport digits equal but letters differ -> different people
        if key[0] == "P" and letter and key in key_index and key_index[key]["_letter"] and key_index[key]["_letter"] != letter:
            letter_conflicts.append(key[1])
            key = ("P", key[1] + letter)
        # also try attaching a name-only/phone record to an existing person via name
        person = key_index.get(key)
        if person is None:
            # name based fallback: a record without passport but same normalized name as an existing person
            if key[0] == "N":
                pass
            person = dict(_letter=letter, fields={}, conflicts=defaultdict(list), sources=[], keys=set())
            key_index[key] = person
            people[id(person)] = person
        else:
            merges += 1
        person["keys"].add(key)
        if letter and not person["_letter"]:
            person["_letter"] = letter
        for f in SCALAR_FIELDS:
            v = rec.get(f, "")
            if not v:
                continue
            cur = person["fields"].get(f, "")
            if not cur:
                person["fields"][f] = v
            elif norm(cur).lower() != norm(v).lower():
                if f in ("passport",):
                    # prefer the one with a letter
                    if not re.search(r"[A-Za-z]", cur) and re.search(r"[A-Za-z]", v):
                        person["conflicts"][f].append(cur)
                        person["fields"][f] = v
                        continue
                person["conflicts"][f].append(v)
        person["sources"].append(dict(file=rec["_src"]["file"], sheet=rec["_src"]["sheet"], row=rec["_src"]["row"],
                                       group=rec["_src"]["group"], raw=rec["_src"]["raw"], conf=rec["_conf"]))
        # index additional keys so later sources hit the same person
        first, last, _ = split_name(rec)
        nk = ("N", norm(f"{first} {last}").lower())
        if nk[1] and len(nk[1].split(" ")) >= 3 and nk not in key_index:
            key_index[nk] = person
        ph, _ = norm_phone(rec.get("phone", ""))
        if ph:
            tk = ("T", re.sub(r"\D", "", ph))
            # phone-only record / phone shared by many (e.g. an agent's number) must not glue strangers:
            key_index.setdefault(tk, person)

    # second pass: phone-only people (keys of type T) that match a real student phone get absorbed
    # (already handled by key_index.setdefault for T keys when the student came first)

    contacts = []
    phone_counts = Counter()
    for p in people.values():
        ph, _ = norm_phone(p["fields"].get("phone", "").split(" | ")[0])
        if ph:
            phone_counts[ph] += 1
    for p in people.values():
        f = p["fields"]
        first, last, father = split_name(f)
        passport = norm_passport(f.get("passport", ""))
        phone_all = [x for x in (f.get("phone", "").split(" | ")) if x]
        phones = [norm_phone(x)[0] for x in phone_all if norm_phone(x)[0]]
        raw_phones = phone_all
        level = norm_level(f.get("level", ""))
        university = norm_university(f.get("university", ""))
        srcs = p["sources"]
        # fall back to sheet-level defaults
        if not university:
            for s in srcs:
                if s["conf"].get("univ"):
                    university = s["conf"]["univ"]
                    break
        nationality = f.get("nationality", "")
        if not nationality:
            for s in srcs:
                if s["conf"].get("nationality"):
                    nationality = s["conf"]["nationality"]
                    break
        withdrawn = any(s["conf"].get("withdrawn") for s in srcs)
        groups = []
        for s in srcs:
            g = s["conf"].get("source_group", "")
            if s["group"] and s["group"] != g:
                g = f"{g} / {s['group']}" if g else s["group"]
            if g and g not in groups:
                groups.append(g)
        lastname = last or first or passport or (f"Unnamed {phones[0]}" if phones else "") or "Unknown"
        firstname = first if last else ""
        contact = OrderedDict()
        contact["firstname"] = firstname
        contact["lastname"] = lastname
        contact["mobile"] = phones[0] if phones else ""
        contact["phone"] = phones[1] if len(phones) > 1 else ""
        contact["email"] = (f.get("email") or "").split(" | ")[0].lower() if "@" in (f.get("email") or "") else ""
        contact["fields"] = OrderedDict([
            ("passport", passport),
            ("father_name", father),
            ("full_name_native", norm(" ".join(x for x in (firstname, lastname) if x))),
            ("full_name_latin", f.get("full_name_latin", "")),
            ("gender", norm_gender(f.get("gender", ""))),
            ("nationality", nationality),
            ("student_no", f.get("student_no", "")),
            ("tracking_code", f.get("tracking_code", "")),
            ("row_code", f.get("row_code", "")),
            ("level", level or ("" if not f.get("level") else "Other")),
            ("level_original", f.get("level", "")),
            ("university", university),
            ("university_original", f.get("university", "") if norm(f.get("university", "")) != university else ""),
            ("faculty", f.get("faculty", "")),
            ("major", norm_major(f.get("major", ""))),
            ("specialization", norm_major(f.get("specialization", ""))),
            ("gpa", f.get("gpa", "")),
            ("broker", f.get("broker", "")),
            ("visa_city", f.get("visa_city", "")),
            ("marital", f.get("marital", "")),
            ("entry_term", f.get("entry_term", "")),
            ("entry_type", f.get("entry_type", "")),
            ("study_start", f.get("study_start", "")),
            ("student_status", "Withdrawn" if withdrawn else "Active"),
            ("source_groups", "; ".join(groups)),
            ("phone_raw", " | ".join(raw_phones)),
            ("docs_to_carrier", f.get("docs_to_carrier", "")),
            ("docs_to_translator", f.get("docs_to_translator", "")),
            ("docs_status", f.get("docs_status", "")),
            ("docs_to_university", f.get("docs_to_university", "")),
            ("sent_to", f.get("sent_to", "")),
            ("changes", " ; ".join(x for x in [f.get("changes", "")] + p["conflicts"].get("changes", []) if x)),
            ("admission_status", f.get("admission_status", "")),
            ("changes_after_admission", f.get("changes_after_admission", "")),
            ("invoice_sent", f.get("invoice_sent", "")),
            ("receipt_no", f.get("receipt_no", "")),
            ("employment_folder", f.get("employment_folder", "")),
            ("employment_status", f.get("employment_status", "")),
            ("pay_tuition", f.get("pay_tuition", "")),
            ("pay_university_tuition", f.get("pay_university_tuition", "")),
            ("pay_persian", f.get("pay_persian", "")),
            ("persian_course", f.get("persian_course", "")),
            ("pay_docs", f.get("pay_docs", "")),
            ("pay_gpa", f.get("pay_gpa", "")),
            ("pay_paid_univ", f.get("pay_paid_univ", "")),
            ("pay_visa", f.get("pay_visa", "")),
            ("pay_photoshop", f.get("pay_photoshop", "")),
            ("pay_univ_services", f.get("pay_univ_services", "")),
            ("pay_generic", " | ".join(x for x in [f.get("pay_generic", "")] + p["conflicts"].get("pay_generic", []) if x)),
            ("lang_fee", f.get("lang_fee", "")),
            ("translator_fee", f.get("translator_fee", "")),
            ("list_label", f.get("list_label", "")),
            ("faculty_code", f.get("faculty_code", "")),
            ("major_code", f.get("major_code", "")),
            ("level_code", f.get("level_code", "")),
            ("course_period", f.get("course_period", "")),
            ("edu_mode", f.get("edu_mode", "")),
            ("overall_status", f.get("overall_status", "")),
            ("edu_rule", f.get("edu_rule", "")),
            ("notes", f.get("notes", "")),
        ])
        # description = readable provenance of EVERY source row + conflicts (full data guarantee)
        desc = []
        for s in srcs:
            desc.append(f"[{s['file']} | {s['sheet']} | row {s['row']}] " +
                        "; ".join(f"{k}={v}" for k, v in s["raw"].items()))
        if any(p["conflicts"].get(k) for k in p["conflicts"]):
            desc.append("MERGE CONFLICTS (other values seen for the same person): " +
                        "; ".join(f"{k}: {' / '.join(dict.fromkeys(v))}" for k, v in p["conflicts"].items() if v and k not in ("changes", "pay_generic")))
        contact["description"] = "\n".join(desc)
        contact["_sources"] = [dict(file=s["file"], sheet=s["sheet"], row=s["row"]) for s in srcs]
        contact["_extra_phones"] = phones[2:]
        contacts.append(contact)

    # absorb phone-only contacts whose phone matches a known student phone (handled earlier via keys)
    for i, c in enumerate(contacts):
        c["_id"] = i + 1

    # -------------------- tasks / events --------------------
    DONE_RE = re.compile(r"(تحویل داده|تحویل دانشگاه گردید|تحویل ابو|ترجمه شده|صادر شد|گردید|پذیرش از|ارسال پذیرش|اشتغال ترجمه شده)")
    BAD_RE = re.compile(r"(باطل|برگشت|خطا|اشتباه|نیاز به|ندارد|نشده)")
    TASK_DEFS = [
        ("docs_to_carrier", "Documents handed to carrier"),
        ("docs_to_translator", "Documents handed to translation office"),
        ("docs_status", "Documents status"),
        ("docs_to_university", "Documents delivered to university"),
        ("sent_to", "File sent to engineer/university"),
        ("changes", "Change / issue to review"),
        ("changes_after_admission", "Change after initial admission"),
        ("employment_status", "Employment-study certificate"),
        ("invoice_sent", "Invoice"),
        ("receipt_no", "Receipt"),
    ]
    tasks, events = [], []
    for c in contacts:
        F = c["fields"]
        for fld, label in TASK_DEFS:
            v = F.get(fld, "")
            if not v:
                continue
            if fld in ("receipt_no",) and not re.search(r"\S", v):
                continue
            done = bool(DONE_RE.search(v)) and not BAD_RE.search(v) or fld in ("sent_to",)
            tasks.append(dict(contact=c["_id"], subject=f"{label}: {v}"[:250],
                              status="Completed" if done else "Not Started", field=fld, value=v))
        if F.get("admission_status"):
            events.append(dict(contact=c["_id"], subject=f"Admission: {F['admission_status']}"[:250], type="Meeting",
                               status="Held", kind="admission", value=F["admission_status"]))
        if F.get("student_status") == "Withdrawn":
            events.append(dict(contact=c["_id"], subject="Withdrawal from university recorded", type="Meeting",
                               status="Held", kind="withdrawal", value="withdrawn"))
        if F.get("persian_course") or "Persian language course" in F.get("source_groups", ""):
            events.append(dict(contact=c["_id"], subject="Persian language course enrolment" +
                               (f": {F['persian_course']}" if F.get("persian_course") else ""), type="Meeting",
                               status="Planned", kind="persian_course", value=F.get("persian_course", "")))
        if F.get("study_start"):
            events.append(dict(contact=c["_id"], subject=f"Study start date: {F['study_start']}", type="Meeting",
                               status="Planned", kind="study_start", value=F["study_start"]))

    # -------------------- reports --------------------
    lv = Counter(c["fields"]["level"] or "(none)" for c in contacts)
    un = Counter(c["fields"]["university"] or "(none)" for c in contacts)
    mj = Counter(c["fields"]["major"] or "(none)" for c in contacts)
    report.append(f"Rows skipped (no name/passport/phone, e.g. bare row numbers or department lines; still in raw_archive.json): {len(skipped_noid)}")
    report.extend("  skipped " + x for x in skipped_noid[:200])
    report.append(f"Source rows parsed: {len(all_recs)}; merged into {len(contacts)} contacts ({merges} rows merged into an existing person)")
    report.append(f"Passport letter conflicts (same digits, different letter -> kept separate): {len(letter_conflicts)}")
    report.append(f"Tasks: {len(tasks)}; Events: {len(events)}")
    report.append("Level counts: " + json.dumps(lv, ensure_ascii=False))
    report.append("University counts: " + json.dumps(dict(un.most_common()), ensure_ascii=False))
    report.append(f"Distinct majors: {len(mj)}")
    report.append("No passport: %d; no phone: %d; no level: %d; phone shape unknown: %d" % (
        sum(1 for c in contacts if not c["fields"]["passport"]),
        sum(1 for c in contacts if not c["mobile"]),
        sum(1 for c in contacts if not c["fields"]["level"]),
        sum(1 for c in contacts if c["mobile"] and len(re.sub(r"\D", "", c["mobile"])) < 11)))
    shared = [(p, n) for p, n in phone_counts.items() if n > 5]
    report.append("Phone numbers shared by >5 different contacts (probably agents/placeholders): " +
                  json.dumps({p: n for p, n in shared}, ensure_ascii=False))

    # -------------------- Persian labels for everything stored in Vtiger (see fa_labels.py) --------------------
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import fa_labels
    import location
    for c in contacts:
        loc = location.locate(c)
        c["fields"]["country_source"] = loc.pop("country_source")
        c.update(loc)                       # mailingcity/mailingcountry/othercity/othercountry (standard fields)
        c["fields"]["source_groups"] = fa_labels.source_groups(c["fields"]["source_groups"])
        c["description"] = fa_labels.description_fa(c["description"])
    for t in tasks:
        t["subject"] = fa_labels.task_subject(t["field"], t["value"])[:250]
    for e in events:
        e["subject"] = fa_labels.event_subject(e["kind"], e["value"])[:250]

    ds = dict(contacts=contacts, tasks=tasks, events=events,
              picklists=dict(level=sorted(lv.keys() - {"(none)"}), university=sorted(un.keys() - {"(none)"}),
                             major=sorted(mj.keys() - {"(none)"}),
                             source_groups=sorted({g.strip() for c in contacts for g in c["fields"]["source_groups"].split(";") if g.strip()}),
                             nationality=sorted({c["fields"]["nationality"] for c in contacts if c["fields"]["nationality"]})))
    with open(os.path.join(out_dir, "dataset.json"), "w", encoding="utf-8") as fh:
        json.dump(ds, fh, ensure_ascii=False, indent=1)
    with open(os.path.join(out_dir, "raw_archive.json"), "w", encoding="utf-8") as fh:
        json.dump(raw_archive, fh, ensure_ascii=False)
    with open(os.path.join(out_dir, "report.txt"), "w", encoding="utf-8") as fh:
        fh.write("\n".join(report) + "\n")
    cols = ["_id", "firstname", "lastname", "mobile", "phone", "email"] + list(contacts[0]["fields"].keys()) if contacts else []
    with open(os.path.join(out_dir, "students.csv"), "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(cols)
        for c in contacts:
            w.writerow([c["_id"], c["firstname"], c["lastname"], c["mobile"], c["phone"], c["email"]] + list(c["fields"].values()))
    print("\n".join(report))
    return ds


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    build(sys.argv[1], sys.argv[2])
