"""Extra contact details derived from the sheets (rules only, nothing guessed).

  whatsapp            number from WhatsApp-labelled columns (واتساب / رقم الواتساب / شماره واتساپ ...)
  whatsapp_link       https://wa.me/<digits> for the WhatsApp number, else the mobile (opens a chat in one click)
  mobile_operator     from the dialling prefix (Iraq: Asiacell 077, Zain 078/079, Korek 075; Iran: MCI 091/099,
                      Irancell 093/090, Rightel 092)
  otherphone          (standard field) 3rd and later numbers – before they only survived in "Phone (as written)"
  title               (standard field) "مشتری" (when a level is known)
  profile_completeness / missing_info   share of key fields filled and which ones are missing (follow-up lists)
"""
import re

LEVEL_FA = {"PhD": "دکتری", "MSc": "کارشناسی ارشد", "BSc": "کارشناسی", "Associate": "کاردانی", "Other": ""}
WA_HEADER = re.compile(r"واتس|الواتساب|whatsapp", re.I)

OPERATORS = [  # (prefix, operator) longest first
    ("+96477", "آسیاسل (عراق)"), ("+96478", "زین (عراق)"), ("+96479", "زین (عراق)"), ("+96475", "کورک (عراق)"),
    ("+98910", "همراه اول"), ("+98911", "همراه اول"), ("+98912", "همراه اول"), ("+98913", "همراه اول"),
    ("+98914", "همراه اول"), ("+98915", "همراه اول"), ("+98916", "همراه اول"), ("+98917", "همراه اول"),
    ("+98918", "همراه اول"), ("+98919", "همراه اول"), ("+98990", "همراه اول"), ("+98991", "همراه اول"),
    ("+98992", "همراه اول"), ("+98993", "همراه اول"), ("+98994", "همراه اول"),
    ("+98930", "ایرانسل"), ("+98933", "ایرانسل"), ("+98935", "ایرانسل"), ("+98936", "ایرانسل"),
    ("+98937", "ایرانسل"), ("+98938", "ایرانسل"), ("+98939", "ایرانسل"), ("+98900", "ایرانسل"),
    ("+98901", "ایرانسل"), ("+98902", "ایرانسل"), ("+98903", "ایرانسل"), ("+98904", "ایرانسل"), ("+98905", "ایرانسل"),
    ("+98941", "ایرانسل"), ("+98920", "رایتل"), ("+98921", "رایتل"), ("+98922", "رایتل"),
]

KEY_FIELDS = [  # (label shown in "missing", getter)
    ("نام", lambda c: not c["lastname"].startswith(("Unnamed", "Unknown"))),
    ("پاسپورت", lambda c: bool(c["fields"].get("passport"))),
    ("موبایل", lambda c: bool(c.get("mobile"))),
    ("ایمیل", lambda c: bool(c.get("email"))),
    ("مقطع", lambda c: bool(c["fields"].get("level"))),
    ("دانشگاه", lambda c: bool(c["fields"].get("university"))),
    ("رشته", lambda c: bool(c["fields"].get("major"))),
    ("کشور", lambda c: bool(c.get("mailingcountry"))),
    ("کد پیگیری", lambda c: bool(c["fields"].get("tracking_code"))),
]


def operator(number):
    n = (number or "").strip()
    for prefix, op in OPERATORS:
        if n.startswith(prefix) and len(n) >= 12:
            return op
    return ""


def enrich(contact, raw_rows, norm_phone):
    """raw_rows: list of {header: value} dicts of every source row of the contact."""
    F = contact["fields"]
    wa = ""
    for raw in raw_rows:
        for h, v in raw.items():
            if v and WA_HEADER.search(str(h)):
                p = norm_phone(v)[0]
                if p.startswith("+") and len(p) >= 12:
                    wa = p
                    break
        if wa:
            break
    if wa and not contact.get("mobile"):
        contact["mobile"] = wa
    F["whatsapp"] = wa
    link_no = wa or (contact.get("mobile") if (contact.get("mobile") or "").startswith("+") and len(contact.get("mobile")) >= 12 else "")
    F["whatsapp_link"] = f"https://wa.me/{link_no.lstrip('+')}" if link_no else ""
    F["mobile_operator"] = operator(contact.get("mobile"))
    extra = list(contact.get("_extra_phones") or [])
    contact["otherphone"] = extra[0] if extra else ""
    lvl = LEVEL_FA.get(F.get("level", ""), "")
    contact["title"] = "مشتری" if lvl else ""
    have = [label for label, ok in KEY_FIELDS if ok(contact)]
    missing = [label for label, ok in KEY_FIELDS if not ok(contact)]
    F["profile_completeness"] = f"{round(100 * len(have) / len(KEY_FIELDS))}%"
    F["missing_info"] = "، ".join(missing)
    return contact
