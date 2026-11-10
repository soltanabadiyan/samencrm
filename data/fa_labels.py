"""Persian labels for everything the data pipeline writes into Vtiger (list names, task/event subjects, ...).

Used by build_dataset.py (final output step), vtiger/make_schema.py (group/filter names) and
vtiger/import_to_vtiger.py (activity descriptions). Internal keys stay English; only stored text is Persian.
"""
import re

LEVEL = {"PhD": "دکتری", "MSc": "کارشناسی ارشد", "BSc": "کارشناسی", "Associate": "کاردانی",
         "Other": "سایر", "": "نامشخص"}

SOURCE_GROUPS = {
    "Afghanistan students": "مشتریان افغانستان",
    "All students master list": "فهرست کل مشتریان",
    "Documents handling": "پیگیری مدارک",
    "EVA list": "فهرست EVA",
    "Employment-study certificates": "گواهی‌های اشتغال به تحصیل",
    "Lorestan University": "دانشگاه لرستان",
    "Lorestan new": "لرستان جدید",
    "Non-Iranian students form": "فرم مشتریان غیرایرانی",
    "Orhan broker transfers": "واریزی‌های معقب اورهان",
    "Payments list": "فهرست پرداخت‌ها",
    "Persian language course": "دوره زبان فارسی",
    "Phone-only list A": "فهرست شماره تلفن الف",
    "Phone-only list B": "فهرست شماره تلفن ب",
    "Qom University": "دانشگاه قم",
    "Qom University list": "فهرست دانشگاه قم",
    "Qom new": "قم جدید",
    "Quran & Hadith PhD": "دکتری علوم قرآن و حدیث",
    "Registration lists": "فهرست‌های ثبت‌نام",
    "Shahrekord University": "دانشگاه شهرکرد",
    "Shahrekord/EVA batch": "دسته شهرکرد/EVA",
    "University of Religions": "دانشگاه ادیان و مذاهب",
    "Urmia Tech students": "مشتریان دانشگاه صنعتی ارومیه",
    "Urmia new admissions": "پذیرش‌های جدید ارومیه",
    "Urmia registration": "ثبت‌نام ارومیه",
    "Withdrawn students": "مشتریان انصرافی",
}

TASK = {  # field -> subject prefix
    "docs_to_carrier": "تحویل مدارک به پیک",
    "docs_to_translator": "تحویل مدارک به دارالترجمه",
    "docs_status": "وضعیت مدارک",
    "docs_to_university": "تحویل مدارک به دانشگاه",
    "sent_to": "ارسال پرونده به مهندس/دانشگاه",
    "changes": "تغییر / مشکل برای بررسی",
    "changes_after_admission": "تغییر پس از پذیرش اولیه",
    "employment_status": "گواهی اشتغال به تحصیل",
    "invoice_sent": "فاکتور",
    "receipt_no": "رسید",
}
EVENT = {  # kind -> subject prefix
    "admission": "پذیرش",
    "withdrawal": "ثبت انصراف از دانشگاه",
    "persian_course": "ثبت‌نام دوره زبان فارسی",
    "study_start": "تاریخ شروع تحصیل",
}
MERGE_CONFLICTS = "مقادیر متفاوت دیگر برای همین شخص (ادغام)"
FIELD_FA = {}  # filled lazily from ss_schema for activity descriptions


def source_groups(text):
    """'A; B / گروه 11' -> Persian, list by list (unknown names kept)."""
    out = []
    for g in [x.strip() for x in text.split(";") if x.strip()]:
        head, sep, tail = g.partition(" / ")
        out.append(SOURCE_GROUPS.get(head, head) + (sep + tail if sep else ""))
    return "; ".join(out)


def task_subject(field, value):
    return f"{TASK.get(field, field)}: {value}"


def event_subject(kind, value):
    if kind == "withdrawal":
        return EVENT[kind]
    if kind == "persian_course" and not value:
        return EVENT[kind]
    return f"{EVENT.get(kind, kind)}: {value}"


def description_fa(desc):
    return re.sub(r"^MERGE CONFLICTS \(other values seen for the same person\): ", MERGE_CONFLICTS + ": ", desc, flags=re.M)
