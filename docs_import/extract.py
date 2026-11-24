#!/usr/bin/env python3
"""
Step 1 of the student-documents import: inventory + everything that can be read without OCR.

  python3 extract.py <documents_dir> <out_dir>
  -> <out_dir>/files.json   one entry per file: folder context (university, term, year, level, major, student),
                            kind by name/extension, fields read from Word / Excel forms and text PDFs
     <out_dir>/lists.json   rows of the list workbooks (one dict per student row, canonical keys + every column)

Nothing is guessed: a field is only filled when the document states it.
Deps: python-calamine, pypdf (venv);  pdftotext for PDFs (prep_flat.py caches it in <out_dir>/pdftext).
"""
import hashlib, html, json, os, re, subprocess, sys, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import common as c
import build_dataset as bd                      # data/build_dataset.py: header names of the list sheets

EXTRA_HEADERS = {   # list-sheet headers not in build_dataset.H
    "passport": ["شماره گذرنامه", "گذرنامه", "passport", "passport no"],
    "phone": ["همراه (واتساپ)", "شماره تماس دانشجو", "واتساب", "شماره همراه", "تلفن", "موبایل", "شماره موبایل", "whatsapp"],
    "full_name": ["نام به فارسی", "نام و لقب", "اسم"],
    "full_name_latin": ["نام به انگلیسی", "name", "نام لاتین"],
    "birth_year": ["سال تولد"], "birth_date": ["تاریخ تولد"],
    "request_no": ["شماره درخواست"], "file_no": ["شماره پرونده"], "old_code": ["کد قبلی"],
    "system_status": ["وضعیت سامانه"], "admission_fee": ["حق پذیرش"], "status": ["وضعیت"],
    "tuition": ["شهریه"], "notes": ["ملاحظات", "سایر"], "address": ["محل سکونت", "آدرس"],
    "specialization": ["گرایش تحصیلی"],
}
LOOKUP = dict(bd._H_LOOKUP)
for k, names in EXTRA_HEADERS.items():
    for n in names:
        LOOKUP.setdefault(bd.norm(n).lower(), k)


def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


# ------------------------------------------------------------------ Word
def docx_text(path):
    try:
        x = zipfile.ZipFile(path).read("word/document.xml").decode("utf8", "replace")
    except Exception:
        return ""
    x = re.sub(r"</w:p>", "\n", x)
    x = re.sub(r"</w:tc>", " | ", x)
    x = re.sub(r"<w:tab/>", " ", x)
    t = html.unescape(re.sub(r"<[^>]+>", "", x)).replace("FORMTEXT", " ")
    return "\n".join(l.strip() for l in t.split("\n") if l.strip(" |"))


FORM_KEYS = [   # label in the application form (Word) -> field
    (r"Father.?s name", "father_name"), (r"Mother.?s name", "mother_name"), (r"Surname", "surname_latin"),
    (r"Name", "first_name_latin"), (r"E-?mail", "email"), (r"Date of birth", "birth_date"),
    (r"Mobile", "mobile"), (r"Marital Status", "marital"), (r"City of birth", "birth_city"),
    (r"Home address", "address"), (r"Passport Number", "passport"), (r"Job address", "job_address"),
    (r"How many siblings do you have", "siblings"),
]
PLACEHOLDER = re.compile(r"^\[?(not provided|n/?a|none|nil|-+|_+|\.+|unknown|no data)\]?\.?$", re.I)
QUESTIONS = [
    ("traveled to Iran before", "iran_travel"), ("friends or family members in Iran", "iran_contacts"),
    ("father's occupation", "father_job"), ("finance your education", "finance"),
    ("applied to multiple universities", "other_applications"), ("brief description of your job", "job_description"),
]


def parse_form_docx(text):
    """The institute's English application form (Father's name / Name / Surname / Passport Number / Education …)."""
    f = {}
    for rx, key in FORM_KEYS:
        m = re.search(r"(?:^|\|)\s*" + rx + r"\s*:\s*([^|\n]*)", text, re.I | re.M)
        if m and m.group(1).strip(" -_") and not PLACEHOLDER.match(m.group(1).strip()):
            f[key] = c.label(m.group(1))
    lines = text.split("\n")
    for needle, key in QUESTIONS:
        for i, l in enumerate(lines):
            if needle.lower() in l.lower():
                ans = lines[i + 1].strip(" |") if i + 1 < len(lines) else ""
                if ans and "?" not in ans and len(ans) < 300 and not PLACEHOLDER.match(ans) and not ans.lower().startswith(("have you", "do you", "what is", "how do", "please")):
                    f[key] = c.label(ans)
                break
    # education table: rows after the header "Grade/Level of study" – cells in reading order GPA, place, centre, from, to, orientation, field, level
    if "Grade/Level of study" in text:
        tail = text.split("Grade/Level of study", 1)[1].split("Work Experiences", 1)[0]
        cells = [x.strip() for x in tail.replace("\n", "|").split("|")]
        cells = [x for x in cells if x not in ("From", "To")]
        vals = [x for x in cells if x]
        edu = []
        i = 0
        while i < len(vals):
            if re.fullmatch(r"\d{1,3}([.,/]\d+)?%?", vals[i]) and float(re.sub(r"[^\d.]", "", vals[i].replace(",", ".").replace("/", ".")) or 0) <= 100:
                row = vals[i:i + 8]
                j = 1
                while j < len(row) and not re.fullmatch(r"\d{1,3}([.,/]\d+)?%?", row[j]) or (j < len(row) and re.fullmatch(r"(19|20)\d\d", row[j])):
                    j += 1
                    if j >= len(row):
                        break
                row = vals[i:i + j]
                e = {"gpa": row[0]}
                years = [x for x in row[1:] if re.fullmatch(r"(19|20)\d\d", x)]
                other = [x for x in row[1:] if x not in years]
                if years:
                    e["from"] = years[0]; e["to"] = years[-1] if len(years) > 1 else ""
                if other:
                    e["level"] = other[-1]
                if len(other) >= 2:
                    e["field"] = other[-2]
                if len(other) >= 3:
                    e["place"] = other[0]
                if len(other) >= 4:
                    e["centre"] = other[1]
                elif len(other) == 3:
                    e["centre"] = other[0] if "uni" in other[0].lower() else ""
                edu.append({k: c.label(v) for k, v in e.items() if v})
                i += max(j, 1)
            else:
                i += 1
        if edu:
            f["education"] = edu[:4]
    return f


def generic_text_fields(text):
    """Passport numbers, e-mails and phone numbers stated anywhere in a text."""
    f = {}
    pp = c.passports_in(text)
    if pp:
        f["passports_in_text"] = pp[:5]
    em = re.findall(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}", text)
    if em:
        f["emails_in_text"] = list(dict.fromkeys(e.lower() for e in em))[:4]
    ph = re.findall(r"(?<!\d)(?:\+|00)?(?:964|98)?0?7\d{9}(?!\d)|(?<!\d)(?:\+|00)?98\s?9\d{9}(?!\d)|(?<!\d)09\d{9}(?!\d)", text)
    if ph:
        f["phones_in_text"] = list(dict.fromkeys(ph))[:4]
    return f


# ------------------------------------------------------------------ Excel
def cellv(v):
    return bd.cell(v)


def read_workbook(path):
    from python_calamine import CalamineWorkbook
    try:
        wb = CalamineWorkbook.from_path(path)
    except Exception:
        return []
    out = []
    for sn in wb.sheet_names:
        try:
            rows = [[cellv(x) for x in r] for r in wb.get_sheet_by_name(sn).to_python()]
        except Exception:
            continue
        rows = [r for r in rows if any(r)]
        if rows:
            out.append((sn, rows))
    return out


def parse_alborz_form(rows):
    """Bilingual per-student application sheet (label | Persian value | English value | Label)."""
    f = {}

    def vals(r):
        return [x for x in r if x]

    def marked(r, pairs):
        """options with '*' beside them: return the option whose neighbour is '*'."""
        v = vals(r)
        for i, x in enumerate(v):
            if x == "*":
                for j in (i - 1, i + 1):
                    if 0 <= j < len(v) and v[j] in pairs:
                        return pairs[v[j]]
        return ""

    level_rows = {"ديپلم": "Diploma", "دیپلم": "Diploma", "فوق ديپلم": "Associate", "كارشناسي": "BSc", "کارشناسی": "BSc",
                  "كارشناسي ارشد": "MSc", "کارشناسی ارشد": "MSc", "ارشد": "MSc", "دكتري": "PhD", "دکتری": "PhD"}
    section = ""
    for r in rows:
        v = vals(r)
        if not v:
            continue
        k = bd.norm(v[0])
        low = " ".join(v).lower()
        if k == "نام" and len(v) >= 3:
            f["first_name"] = v[1]; f["first_name_latin"] = v[2]
        elif k.startswith("نام خانوادگ") and len(v) >= 3:
            f["surname"] = v[1]; f["surname_latin"] = v[2]
        elif k.startswith("تبعه") and len(v) >= 2:
            f["nationality"] = v[1]
        elif k.startswith("شماره پاسپورت"):
            pp = [x for x in v if re.fullmatch(r"[A-Za-z]{1,2}\d{6,9}", x.replace(" ", ""))]
            dd = [x for x in v if re.fullmatch(r"\d{4}-\d{2}-\d{2}.*", x)]
            if pp:
                f["passport"] = pp[0].replace(" ", "").upper()
            if dd:
                f["passport_issue"] = dd[0][:10]
        elif k.startswith("تاریخ و محل تولد") or "date of birth" in low:
            dd = [x for x in v if re.fullmatch(r"\d{4}-\d{2}-\d{2}.*", x)]
            if dd:
                f["birth_date"] = dd[0][:10]
            if "دين" in v or "دین" in v:
                i = v.index("دين") if "دين" in v else v.index("دین")
                if i + 1 < len(v):
                    f["religion"] = v[i + 1]
        elif k.startswith("جنسیت"):
            g = marked(r, {"مذكر": "Male", "مذکر": "Male", "مونث": "Female", "Male": "Male", "Female": "Female"})
            if g:
                f["gender"] = g
        elif k.startswith("وضعیت تاهل"):
            g = marked(r, {"متاهل": "متاهل", "مجرد": "مجرد"})
            if g:
                f["marital"] = g
        elif k == "تلفن" and len(v) >= 2:
            f["mobile"] = v[1]
        elif k == "آدرس" or k == "ادرس":
            if len(v) >= 2:
                f["address"] = v[1]
        elif k.startswith("پست الکترونیک") and len(v) >= 2:
            f["email"] = v[1]
        elif k == "شغل" and len(v) >= 2 and v[1].strip("_ "):
            f["job"] = v[1]
        elif k.startswith("مدرک تحصیلی"):
            section = "degree"
            if "*" in v:
                f["prev_level"] = "Diploma"
        elif k.startswith("رشته تحصیلی") and len(v) >= 2:
            f["prev_field"] = v[1]
            if len(v) >= 3:
                f["prev_field_latin"] = v[2]
        elif k == "دانشگاه" and len(v) >= 2:
            f["prev_university"] = v[1]
        elif k == "معدل":
            section = "gpa"
        elif k.startswith("متقاضی ادامه تحصیل"):
            section = "apply"
            if len(v) >= 2:
                f["apply_faculty"] = v[1]
        elif k == "مقطع":
            section = "apply"
            if "*" in v:
                f["apply_level"] = "BSc"
        elif k == "رشته" and len(v) >= 2:
            f["apply_major"] = v[1]
            if len(v) >= 3:
                f["apply_major_latin"] = v[2]
            section = ""
        elif v[0].strip() in level_rows:
            lv = level_rows[v[0].strip()]
            if section == "degree" and "*" in v:
                f["prev_level"] = lv
            elif section == "apply" and "*" in v:
                f["apply_level"] = lv
            elif section == "gpa":
                nums = [x for x in v[1:] if re.fullmatch(r"\d{1,3}(\.\d+)?", x)]
                if nums:
                    f["gpa_" + lv.lower()] = nums[0]
    return {k: c.label(v) for k, v in f.items() if v and v.strip("_ ")}


def parse_list_sheet(rows):
    hi = bd.find_header_row(rows)
    best, bi = 0, hi
    for i in range(min(8, len(rows))):                 # header = row with most known column names
        n = sum(1 for x in rows[i] if bd.norm(x).lower() in LOOKUP)
        if n > best:
            best, bi = n, i
    if best < 2:
        return []
    head = rows[bi]
    out = []
    for r in rows[bi + 1:]:
        rec, raw = {}, {}
        for h, v in zip(head, r):
            if not v or not h:
                continue
            raw[c.label(h)] = c.label(v)
            k = LOOKUP.get(bd.norm(h).lower())
            if k and k not in rec:
                rec[k] = c.label(v)
        if len(raw) >= 2 and (rec.get("full_name") or rec.get("passport") or rec.get("surname")):
            rec["_raw"] = raw
            out.append(rec)
    return out


# ------------------------------------------------------------------ PDF
PDF_KINDS = [
    (r"Application Receipt Confirmation|application-\d", "application_receipt"),
    (r"registration-certificate|گواهی ثبت ?نام", "registration_certificate"),
    (r"initial-certificate|پذیرش اولیه|گواهی مشروط|Conditional", "initial_admission"),
    (r"گواهی پذیرش نهایی|final admission|_admission", "final_admission"),
    (r"^receipt-|رسید", "payment_receipt"),
    (r"رزومه|resume|\bcv\b|curriculum", "cv"),
    (r"visa|ویزا|روادید", "visa"),
]


def parse_pdf(name, text):
    f = {}
    blob = name + "\n" + text[:3000]
    for rx, kind in PDF_KINDS:
        if re.search(rx, blob, re.I | re.M):
            f["kind"] = kind
            break
    m = re.search(r"application for the (.+?) at (.+?) has been successfully received on (\d{4}/\d{2}/\d{2})", re.sub(r"\s+", " ", text))
    if m:
        f["apply_major_latin"] = m.group(1).strip(" -"); f["apply_university_latin"] = m.group(2); f["apply_date"] = m.group(3)
    m = re.search(r"Program:\s*(Master|PhD|Ph\.D|Doctor\w*|Bachelor)\w* in (.+)", text)
    if m:
        f["apply_level"] = {"master": "MSc", "phd": "PhD", "ph.d": "PhD", "bachelor": "BSc"}.get(m.group(1).lower(), "PhD" if m.group(1).lower().startswith("doc") else "")
    m = re.search(r"Current Status:\s*(.+)", text)
    if m:
        f["application_status"] = m.group(1).strip()
    f.update(generic_text_fields(text))
    return f


NAME_KIND = [   # document type stated by the FILE NAME (the scans' content is classified after OCR)
    (r"پاسپورت|جواز|passport|pass\b", "passport"), (r"عکس|صوره|صورة|شخصی|photo(?!_|\d)", "photo"),
    (r"ماستر|ماجستير|master|msc|ارشد", "master_degree"), (r"بكالوريوس|بکالوریوس|لیسانس|bachelor|bsc|کارشناسی", "bachelor_degree"),
    (r"دكتورا|دکتورا|phd", "phd_degree"), (r"ریز ?نمرات|درجات|transcript|نمرات", "transcript"),
    (r"رزومه|resume|\bcv\b|سیره|سيرة", "cv"), (r"ویزا|فيزا|visa", "visa"), (r"پذیرش|قبول|admission", "admission"),
    (r"توصیه|recommendation", "recommendation"), (r"اعدادی|اعدادية|دیپلم|diploma", "diploma"),
    (r"شناسنامه|جنسية|جنسیه|هویت|موحده|البطاقة|بطاقه", "national_id"), (r"رسید|receipt|فیش|وصل", "payment_receipt"),
    (r"پروپوزال|proposal|پیشنهاده", "proposal"), (r"مقاله|article|بحث", "paper"),
]


def kind_by_name(name):
    base = os.path.splitext(name)[0]
    for rx, kind in NAME_KIND:
        if re.search(rx, base, re.I):
            return kind
    return ""


def person_in_filename(name):
    """A person's name written in the file name ('_⁨علاء جبار هادي⁩.pdf', 'گواهی پذیرش نهایی -وسام عبدایوب …-1405_5_29.pdf')."""
    base = c.label(os.path.splitext(name)[0])
    base = re.sub(r"گواهی پذیرش نهایی|گواهی مشروط|رزومه|cv|resume|copy|\(\d+\)|[\d_]+$", " ", base, flags=re.I)
    parts = [p.strip(" -_") for p in re.split(r"\s-\s|-|_", base)]
    for p in parts:
        w = [x for x in p.split() if re.fullmatch(r"[ء-يپچژکگیۀ]+", x)]
        if len(w) >= 2 and len(w) == len(p.split()) and not c.classify_segment(p)[0] in ("univ", "level", "major"):
            return " ".join(w)
    return ""


def main():
    root, out = sys.argv[1], sys.argv[2]
    os.makedirs(out, exist_ok=True)
    files, lists = [], []
    seen_wb = set()
    # the tree itself, then the unpacked archives (unpack_archives.py): a file inside "X/y.rar" is read as "X/<inner path>"
    walk = []
    for d, _, fs in os.walk(root):
        if not any(n in d for n in c.NOISE):
            walk += [(os.path.join(d, fn), os.path.relpath(os.path.join(d, fn), root), "") for fn in sorted(fs)]
    unpacked = {}
    up = os.path.join(out, "unpacked.json")
    if os.path.exists(up):
        unpacked = {k: v for k, v in json.load(open(up, encoding="utf-8")).items() if v.get("dir")}
    for arel, v in sorted(unpacked.items()):
        base = os.path.join(out, v["dir"])
        for d, _, fs in os.walk(base):
            if any(n in d for n in c.NOISE) or "__MACOSX" in d:
                continue
            for fn in sorted(fs):
                inner = os.path.relpath(os.path.join(d, fn), base)
                walk.append((os.path.join(d, fn), os.path.join(os.path.dirname(arel), inner), arel))
    seen_tree = set()
    for path, rel, archive in walk:
        if True:
            fn = os.path.basename(path)
            ext = os.path.splitext(fn)[1].lower()
            if ext not in c.DOCS or fn.startswith(("~$", "._")):
                continue
            if ext in (".rar", ".zip") and (rel in unpacked or archive):
                continue                              # its content is in the list instead
            h = md5(path)
            if archive and (h, os.path.dirname(rel)) in seen_tree:
                continue                              # the same file already lies unpacked in that folder
            seen_tree.add((h, os.path.dirname(rel)))
            rec = {"rel": rel, "md5": h, "ext": ext, "size": os.path.getsize(path), "ctx": c.context(rel),
                   "name_kind": kind_by_name(fn), "fields": {}, "path": path, "mtime": int(os.path.getmtime(path))}
            if archive:
                rec["archive"] = archive
            if "person" not in rec["ctx"]:
                pn = person_in_filename(fn)
                if pn:
                    rec["name_in_file"] = pn
            if ext == ".docx":
                text = docx_text(path)
                rec["text"] = text[:6000]
                if "Passport Number" in text and "Father" in text:
                    rec["fields"] = parse_form_docx(text); rec["kind"] = "application_form"
                rec["fields"].update({k: v for k, v in generic_text_fields(text).items()})
            elif ext in (".xlsx", ".xls"):
                sheets = read_workbook(path)
                flat = " ".join(" ".join(r) for _, rows in sheets[:1] for r in rows[:6])
                if "Application Form" in flat or "فرم درخواست" in flat:
                    rec["fields"] = parse_alborz_form(sheets[0][1]); rec["kind"] = "application_form_sheet"
                else:
                    rec["kind"] = "list_workbook"
                    if h not in seen_wb:
                        seen_wb.add(h)
                        for sn, rows in sheets:
                            rws = parse_list_sheet(rows)
                            for i, r in enumerate(rws):
                                r["_src"] = {"rel": rel, "sheet": sn, "n": i}
                                r["_ctx"] = {k: v for k, v in rec["ctx"].items() if k in ("university", "term", "year", "level", "major")}
                            lists += rws
                            rec.setdefault("sheets", []).append([sn, len(rows), len(rws)])
            elif ext == ".pdf":
                tp = os.path.join(out, "pdftext", h + ".txt")
                text = open(tp, encoding="utf-8").read() if os.path.exists(tp) else ""
                rec["text"] = text[:6000]
                rec["fields"] = parse_pdf(fn, text)
                if "kind" in rec["fields"]:
                    rec["kind"] = rec["fields"].pop("kind")
            elif ext in (".rar", ".zip"):
                try:
                    ls = subprocess.run(["7z", "l", "-ba", "-slt", path], capture_output=True, text=True, timeout=60).stdout
                    rec["members"] = [l[7:] for l in ls.split("\n") if l.startswith("Path = ")][:200]
                except Exception:
                    pass
                rec["kind"] = "archive"
            files.append(rec)
    json.dump(files, open(os.path.join(out, "files.json"), "w", encoding="utf-8"), ensure_ascii=False)
    json.dump(lists, open(os.path.join(out, "lists.json"), "w", encoding="utf-8"), ensure_ascii=False)
    import collections
    print("files", len(files), "unique", len({f["md5"] for f in files}))
    print("kinds", collections.Counter(f.get("kind") or f["name_kind"] or f["ext"] for f in files).most_common(30))
    print("forms (docx)", sum(1 for f in files if f.get("kind") == "application_form"),
          "forms (sheet)", sum(1 for f in files if f.get("kind") == "application_form_sheet"), "list rows", len(lists))
    print("files with a passport number in fields", sum(1 for f in files if f["fields"].get("passport") or f["fields"].get("passports_in_text")))


if __name__ == "__main__":
    main()
