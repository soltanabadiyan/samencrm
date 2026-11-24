"""What a scanned page says: passport data (machine-readable zone), document type, CV / certificate details.
Input = OCR lines [[text, confidence, x, y(, page)], ...] of ocr_worker.py. Latin text only – Arabic/Persian pages
are recognised as such (many text boxes, nothing readable) and typed from their file name or left as 'document'."""
import re

W = {c: i for i, c in enumerate("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ")}
W["<"] = 0
DIGIT_FIX = str.maketrans("OQDIlLZSBG", "0001112586")     # letters the OCR reads for digits in numeric MRZ fields
COUNTRIES = {"IRQ": "عراق", "AFG": "افغانستان", "SYR": "سوریه", "LBN": "لبنان", "YEM": "یمن", "PAK": "پاکستان",
             "IND": "هند", "TUR": "ترکیه", "EGY": "مصر", "JOR": "اردن", "BHR": "بحرین", "KWT": "کویت", "SAU": "عربستان سعودی",
             "NGA": "نیجریه", "IRN": "ایران", "AZE": "آذربایجان", "TJK": "تاجیکستان", "OMN": "عمان", "SDN": "سودان",
             "PSE": "فلسطین", "LBY": "لیبی", "TUN": "تونس", "DZA": "الجزایر", "MAR": "مراکش", "CHN": "چین", "BGD": "بنگلادش"}


def _check(s):
    return sum(W.get(ch, 0) * (7, 3, 1)[i % 3] for i, ch in enumerate(s)) % 10


def _date(yymmdd, future):
    if not re.fullmatch(r"\d{6}", yymmdd):
        return ""
    yy, mm, dd = int(yymmdd[:2]), int(yymmdd[2:4]), int(yymmdd[4:])
    if not (1 <= mm <= 12 and 1 <= dd <= 31):
        return ""
    year = 2000 + yy if (future or yy <= 12) else 1900 + yy          # births: 13..99 -> 19xx (students are adults)
    return f"{year:04d}-{mm:02d}-{dd:02d}"


def parse_mrz(lines):
    """-> dict(passport, surname_latin, given_latin, nationality_code, birth_date, sex, expiry, mrz_valid) or {}"""
    texts = [re.sub(r"\s+", "", l[0]).upper().replace("«", "<<").replace("‹", "<") for l in lines]
    l1 = next((t for t in texts if re.match(r"^P[<A-Z0-9][A-Z0-9<]{2,3}", t) and t.count("<") >= 3 and len(t) >= 28), "")
    cands = [t for t in texts if len(t) >= 26 and re.search(r"\d{6}", t) and t != l1 and (t.count("<") >= 1 or re.match(r"^[A-Z0-9]{9}\d", t))]
    l2 = ""
    for t in cands:                      # second line: 9-char number, check digit, 3-letter country, yymmdd …
        if re.match(r"^[A-Z0-9<]{9}[0-9OQDIL][A-Z0-9<]{3}[0-9OQDILZSBG]{6}", t):
            l2 = t; break
    if not l2:
        return {}
    out = {}
    num = l2[0:9].replace("<", "")
    # the number is one or two letters + digits: fix letter/digit confusions in the digit part
    m = re.match(r"^([A-Z]{1,2})(.+)$", num)
    if m:
        num = m.group(1) + m.group(2).translate(DIGIT_FIX)
    cd = l2[9].translate(DIGIT_FIX)
    out["passport"] = num
    out["mrz_valid"] = cd.isdigit() and _check(l2[0:9].replace("O", "0") if False else (num + "<" * (9 - len(num)))) == int(cd)
    nat = l2[10:13].replace("1", "I").replace("0", "O")
    out["nationality_code"] = nat
    out["birth_date"] = _date(l2[13:19].translate(DIGIT_FIX), False)
    sex = l2[20:21]
    out["sex"] = {"M": "Male", "F": "Female"}.get(sex, "")
    out["expiry"] = _date(l2[21:27].translate(DIGIT_FIX), True)
    if l1:
        body = l1[5:] if len(l1) > 5 else ""
        parts = body.split("<<", 1)
        out["surname_latin"] = re.sub(r"[^A-Z ]", "", parts[0].replace("<", " ")).strip()
        if len(parts) > 1:
            out["given_latin"] = re.sub(r"\s+", " ", re.sub(r"[^A-Z ]", "", parts[1].replace("<", " "))).strip()
        if not re.fullmatch(r"[A-Z]{3}", nat):
            n1 = l1[2:5].replace("1", "I").replace("0", "O")
            if re.fullmatch(r"[A-Z]{3}", n1):
                out["nationality_code"] = n1
    out["nationality"] = COUNTRIES.get(out.get("nationality_code", ""), "")
    return out


def visual_passport(lines):
    """Printed fields of a passport page that the MRZ does not carry: issue date, place of birth, mother's name."""
    out = {}
    ts = [l[0] for l in lines]
    dates = []
    for t in ts:
        for m in re.finditer(r"(?<!\d)(\d{2})[./\-](\d{2})[./\-]((?:19|20)\d{2})(?!\d)", t):
            dates.append(f"{m.group(3)}-{m.group(2)}-{m.group(1)}")
    if dates:
        out["dates_printed"] = sorted(set(dates))
    return out


KINDS = [
    ("cv", r"\bc\.?v\b|curriculum ?vitae|resume|personal (information|data|details)|work experience|\bskills\b"),
    ("visa", r"\bvisa\b|entry permit|islamic republic of iran.*(entry|visa)"),
    ("application_form", r"application ?form|applicant|educational ?background"),
    ("admission", r"admission|acceptance letter|offer letter|letter of acceptance"),
    ("recommendation", r"recommendation|to recommend"),
    ("payment_receipt", r"receipt|payment|invoice|transaction|amount"),
    ("language_certificate", r"ielts|toefl|language (certificate|proficiency)"),
    ("transcript", r"transcript|grades? of|subject|units?|marks?|credit|first year|second year|course"),
    ("degree_certificate", r"certificate|certif|whom it may concern|graduation|degree|diploma|ministry of higher|university|college"),
]
LEVEL_WORDS = [("phd", r"ph\.?d|doctor(ate|al)|doctor of philosophy"), ("master", r"master|m\.?sc|m\.?a\b|magister|higher diploma"),
               ("bachelor", r"bachelor|b\.?sc|b\.?a\b|b\.?e\b|b\.?ed"), ("diploma", r"preparatory|secondary|high school|baccalaureate")]


def classify(rec, name_kind=""):
    """-> (kind, detail)  kind in passport, photo, cv, degree_certificate, transcript, visa, admission, … , arabic_document, unknown"""
    lines = rec.get("lines", [])
    good = [l for l in lines if l[1] >= 0.5 and len(l[0]) >= 2]
    text = " ".join(l[0] for l in good)
    low = text.lower()
    low_sp = re.sub(r"(?<=[a-z])(?=[A-Z])", " ", text).lower()
    boxes = rec.get("boxes", len(lines))
    mrz = parse_mrz(lines)
    if mrz.get("passport") and (mrz.get("birth_date") or "passport" in low or mrz.get("mrz_valid")):
        return "passport", mrz
    if re.search(r"passport", low) and re.search(r"republic|surname|date of birth", low):
        return "passport", {}
    if boxes <= 4 and not re.search(r"[a-z]{5,}", low):
        w, h = rec.get("w", 1), rec.get("h", 1)
        return ("photo" if 0.55 <= w / max(h, 1) <= 1.1 else "image"), {}
    latin = sum(len(l[0]) for l in good if re.search(r"[A-Za-z]{3,}", l[0]))
    # a CV: titled "CV", or a page of "Name: … / Email: … / WhatsApp: …" lines
    if (any(l[0].strip().lower() in ("cv", "c.v", "c.v.", "resume", "curriculum vitae") for l in lines[:6])
            or (re.search(r"\bname\s*[:：]", low) and re.search(r"e-?mail|whats ?app|address|residence", low) and not re.search(r"application ?form", low))):
        return "cv", {}
    for kind, rx in KINDS:
        if re.search(rx, low) or re.search(rx, low_sp):
            det = {}
            if kind in ("degree_certificate", "transcript"):
                for lv, lrx in LEVEL_WORDS:
                    if re.search(lrx, low_sp):
                        det["level"] = lv
                        break
                if kind == "degree_certificate" and re.search(r"subject|units?\b|marks?\b|first year|second year|grades? of", low_sp):
                    kind = "transcript"
            return kind, det
    if name_kind:
        return name_kind, {}
    if boxes >= 8 and latin < 40:
        return "arabic_document", {}
    return "unknown", {}


def cv_fields(rec):
    """Name / phone / e-mail / address / work lines of a CV page ('Name: …', 'WhatsApp number: …')."""
    out = {}
    lines = [l[0] for l in rec.get("lines", [])]
    text = "\n".join(lines)
    m = re.search(r"(?:^|\n)\s*(?:full ?)?name\s*[:：]\s*([A-Za-z][A-Za-z .'\-]{4,60})", text, re.I)
    if m:
        out["name_latin"] = re.sub(r"\s+", " ", m.group(1)).strip()
    m = re.search(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}", text)
    if m:
        out["email"] = m.group(0).lower()
    m = re.search(r"(?:whats ?app|mobile|phone|tel)[^\d+]{0,25}(\+?[\d ]{9,16})", text, re.I)
    if m:
        out["phone"] = re.sub(r"\s", "", m.group(1))
    for key, rx in (("address", r"(?:address|residence)\s*[:：]\s*(.{4,90})"), ("job", r"(?:work|job|occupation|position|profession)\s*[:：]\s*(.{4,160})"),
                    ("children", r"children\s*[:：]\s*(.{1,40})"), ("marital", r"marital status\s*[:：]\s*(\w+)"),
                    ("birth", r"(?:date of birth|birth ?date|born)\s*[:：]?\s*([\d./\-]{6,10})")):
        m = re.search(rx, text, re.I)
        if m:
            out[key] = m.group(1).strip()
    return out


def certificate_fields(rec):
    """University, average and year printed on an (English) certificate / transcript."""
    out = {}
    text = " ".join(l[0] for l in rec.get("lines", []) if l[1] >= 0.55)
    sp = re.sub(r"(?<=[a-z])(?=[A-Z])", " ", text)
    m = re.search(r"(University ?of ?[A-Z][A-Za-z\-]+(?: (?:[A-Z][A-Za-z\-]+|of|and|for)){0,3}|[A-Z][A-Za-z\-]{2,20} ?University(?: ?College)?)", sp)
    if m:
        u = re.sub(r"\s+", " ", m.group(1)).strip()
        if not re.match(r"^(The|This|Scientific|Higher|Our|Your|Any|Each|Said|Above|Same|State|Public|Private|Iraqi|And) University$", u):
            out["university_latin"] = u
    m = re.search(r"(?:average|avg|rate|grade)[^\d]{0,40}\(?(\d{2}(?:\.\d{1,3})?)\)?\s*%?", sp, re.I)
    if m and 40 <= float(m.group(1)) <= 100:
        out["average"] = m.group(1)
    ys = [int(y) for y in re.findall(r"(?<!\d)(19[89]\d|20[0-2]\d)(?!\d)", sp)]
    if ys:
        out["years"] = sorted(set(ys))[-4:]
    return out
