"""Shared helpers of the student-documents import: text normalisation, folder context, identity keys."""
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data"))

_TR = str.maketrans({
    "ي": "ی", "ى": "ی", "ك": "ک", "ۀ": "ه", "ة": "ه", "ؤ": "و", "أ": "ا", "إ": "ا", "آ": "ا", "ئ": "ی",
    "‌": " ", "‏": "", "‎": "", " ": " ", "⁨": "", "⁩": "", "‍": "", "ـ": "",
    "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4", "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
    "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
})
_DIAC = re.compile(r"[ً-ٰٟ]")

IMG = (".jpg", ".jpeg", ".jfif", ".png")
DOCS = IMG + (".pdf", ".docx", ".doc", ".xlsx", ".xls", ".rar", ".zip")
NOISE = ("_files", "v2rayN", "powerpoint-template", "imagecompressor")


def norm(s):
    """Arabic/Persian letter variants, digits and whitespace normalised (matching and labels)."""
    if s is None:
        return ""
    s = _DIAC.sub("", str(s).translate(_TR))
    return re.sub(r"\s+", " ", s).strip()


def label(s):
    """Text for display: Arabic ي/ك and digits unified, whitespace tidied (آ and ئ are kept)."""
    if s is None:
        return ""
    t = str(s)
    for a, b in (("ي", "ی"), ("ى", "ی"), ("ك", "ک"), ("\u200f", ""), ("\u200e", ""), ("\u2068", ""), ("\u2069", ""), ("\u00a0", " ")):
        t = t.replace(a, b)
    t = t.translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789"))
    return re.sub(r"\s+", " ", t).strip()


def name_key(s):
    """Key for comparing person names written in Arabic script: no 'ال', no spaces, hamza/alef variants folded."""
    s = norm(s).replace("عبد ال", "عبدال").replace("ابو ", "ابو").replace("ال ", "ال")
    words = [re.sub(r"^ال", "", w) for w in re.split(r"[\s\-_]+", s) if w]
    return " ".join(w for w in words if w)


# university as written in a folder name -> label used in the CRM (existing picklist value where there is one)
UNIVERSITIES = [
    ("صنعتی ارومیه", "دانشگاه صنعتی ارومیه"), ("ارومیه", "دانشگاه ارومیه"),
    ("علوم پزشکی قم", "دانشگاه علوم پزشکی قم"), ("پزشکی قم", "دانشگاه علوم پزشکی قم"),
    ("قم", "دانشگاه قم"), ("qom", "دانشگاه قم"),
    ("تربیت مدرس", "دانشگاه تربیت مدرس"), ("تربیت رجایی", "دانشگاه تربیت دبیر شهید رجایی"), ("تربیت", "دانشگاه تربیت مدرس"),
    ("تهران", "دانشگاه تهران"), ("خوارزمی", "دانشگاه خوارزمی"),
    ("فارابی", "پردیس فارابی دانشگاه تهران"), ("البرز", "پردیس البرز دانشگاه تهران"),
    ("شهید بهشتی", "دانشگاه شهید بهشتی"), ("علم و صنعت", "دانشگاه علم و صنعت"), ("علم وصنعت", "دانشگاه علم و صنعت"),
    ("مازندران", "دانشگاه مازندران"), ("اراک", "دانشگاه اراک"),
    ("نوشیروانی بابل", "دانشگاه نوشیروانی"), ("بابل نوشیروانی", "دانشگاه نوشیروانی"),
    ("علوم پزشکی بابل", "دانشگاه علوم پزشکی بابل"),
    ("پزشکی سمنان", "دانشگاه علوم پزشکی سمنان"),
    ("علوم پزشکی کرمانشاه", "دانشگاه علوم پزشکی کرمانشاه"), ("پزشکی کرمانشاه", "دانشگاه علوم پزشکی کرمانشاه"),
    ("کرمانشاه", "دانشگاه رازی"), ("تبریز", "دانشگاه تبریز"), ("ایلام", "دانشگاه ایلام"),
    ("فردوسی", "دانشگاه فردوسی"), ("مدنی", "دانشگاه شهید مدنی تبریز"), ("مراغه", "دانشگاه مراغه"),
    ("شهرکرد", "دانشگاه شهرکرد"), ("لرستان", "دانشگاه لرستان"), ("lorestan", "دانشگاه لرستان"),
]
_UNI = {norm(k).lower(): v for k, v in UNIVERSITIES}
GENERIC = {norm(x).lower() for x in (
    "uni", "uni2", "UNI3", "دانشگاه ها", "desktopo", "New folder", "New folder (2)", "new", "SAMANEH", "Zahra 🧸", "Zahra",
    "دانشجویان", "لیست دانشجویان", "ویزا", "VISA", "مالی", "سایر دانشگاه ها", "لیست رشته ها", "4", "دانشگاه")}
LEVELS = {"دکتری": "PhD", "دکترا": "PhD", "کارشناسی ارشد": "MSc", "ارشد": "MSc", "کارشناسی": "BSc"}
LEVEL_FA = {"PhD": "دکتری", "MSc": "کارشناسی ارشد", "BSc": "کارشناسی", "Associate": "کاردانی"}
KNOWN_MAJORS = {"فناوری اطلاعات"}
# first word of a field-of-study folder (a folder under دکتری / کارشناسی ارشد is a major or a student)
MAJOR_WORD = re.compile(r"^(حقوق|زبان|مهندسی|مدیریت|فیزیک|روانشناسی|حسابداری|تاریخ|فقه|زیست|اموزش|فناوری|فیزیولوژی|علوم|ادبیات|شیمی|ریاضی|اقتصاد)")


def classify_segment(seg, parent_kind=None):
    """-> (kind, info)  kind: generic | univ | level | major | person"""
    s = norm(seg)
    low = s.lower()
    if low in GENERIC:
        return "generic", {}
    if s in LEVELS:
        return "level", {"level": LEVELS[s]}
    info = {}
    rest = s
    m = re.search(r"(مهر|بهمن)", rest)
    if m:
        info["term"] = m.group(1); rest = rest.replace(m.group(1), " ")
    m = re.search(r"(1[34]\d\d)", rest)
    if m:
        info["year"] = m.group(1); rest = rest.replace(m.group(1), " ")
    rest = re.sub(r"\d+", " ", rest)
    lvl = None
    if re.match(r"^ارشد\s", rest):
        lvl = "MSc"; rest = rest[4:]
    rest = norm(re.sub(r"^(دانشگاه|دانشجویان)\s+", "", norm(rest))).lower()
    if rest in _UNI:
        info["university"] = _UNI[rest]; info["university_original"] = s
        if lvl:
            info["level"] = lvl
        return "univ", info
    if s in KNOWN_MAJORS or (parent_kind == "level" and "+" not in s and MAJOR_WORD.match(s)):
        return "major", {"major": label(seg.replace("-", " - "))}
    return "person", {}


def clean_person(seg):
    """Folder name of a student -> (name, flags)"""
    s = label(seg)
    flags = []
    if "+" in s:
        flags.append("plus"); s = s.replace("+", " ")
    if "ناقص" in s:
        flags.append("incomplete"); s = re.sub(r"ناقص\S*", " ", s)
    s = re.sub(r"[\-_]\s*(%s)\s*$" % "|".join(re.escape(label(k)) for k, _ in UNIVERSITIES), " ", s)
    s = re.sub(r"\.pdf$|\(\d+\)|^_+|_+$|\d+$", " ", s)
    return label(s), flags


def context(relpath):
    """Folder context of a file: university, term, year, level, major, student folder name."""
    segs = relpath.split(os.sep)[:-1]
    ctx = {"groups": []}
    kind = None
    for i, seg in enumerate(segs):
        if "person" in ctx:
            break
        kind, info = classify_segment(seg, kind)
        if kind == "person":
            name, flags = clean_person(seg)
            ctx["person"] = name; ctx["person_dir"] = os.sep.join(segs[:i + 1]); ctx["flags"] = flags
        elif kind == "generic":
            ctx["groups"].append(label(seg))
        else:
            for k, v in info.items():                 # the outer folder wins ("پزشکی قم مهر 1404/qom/…" is پزشکی قم)
                if k == "university_original" or k not in ctx or k in ("level", "major"):
                    if not (k == "university_original" and "university" in ctx and ctx.get("university") != info.get("university")):
                        ctx[k] = v
    return ctx


PASSPORT_RE = re.compile(r"(?<![A-Za-z0-9])([A-Z]{1,2})\s?(\d{7,9})(?![0-9])")


def passports_in(text):
    out = []
    for m in PASSPORT_RE.finditer(text or ""):
        p = m.group(1) + m.group(2)
        if p not in out:
            out.append(p)
    return out


def phone_e164(v):
    from build_dataset import norm_phone
    return norm_phone(v)[0]
