#!/usr/bin/env python3
"""Read a simple contact sheet (xlsx/xls/ods/csv) and print JSON for vtiger/import_contacts.php.

    python3 vtiger/sheet_to_contacts.py contacts.xlsx "مدیران استان قم" > contacts_sheet.json

Columns are found by their header (Persian or English, any order):
  name / نام و نام خانوادگی   (or separate first / last name columns)
  phone / mobile / شماره تماس / موبایل
  title / سمت,  organisation / سازمان / شرکت,  city / شهر,  gender / جنسیت (آقا / خانم),  email / ایمیل
The second argument is a tag put on every imported contact (default: the file name).
"""
import csv, json, re, sys
from pathlib import Path

HEADERS = {
    'name':      ['نام و نام خانوادگی', 'نام کامل', 'full name', 'name'],
    'firstname': ['نام', 'first name', 'firstname'],
    'lastname':  ['نام خانوادگی', 'last name', 'lastname', 'surname'],
    'phone':     ['شماره تماس', 'تلفن همراه', 'موبایل', 'شماره موبایل', 'تلفن', 'mobile', 'phone', 'tel'],
    'title':     ['سمت', 'عنوان شغلی', 'title', 'position', 'job title'],
    'org':       ['سازمان', 'شرکت', 'نام سازمان', 'organisation', 'organization', 'company'],
    'city':      ['شهر', 'city'],
    'gender':    ['جنسیت', 'gender', 'sex'],
    'email':     ['ایمیل', 'پست الکترونیک', 'email', 'e-mail'],
}
FA_DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')


def norm(s):
    s = '' if s is None else str(s)
    if s.endswith('.0') and s[:-2].isdigit():   # numbers read from Excel
        s = s[:-2]
    s = s.replace('ي', 'ی').replace('ك', 'ک').replace('‌', '‌')
    s = re.sub(r'\s+', ' ', s).strip()
    return '' if re.fullmatch(r'[\W_]*', s) else s      # «--», «-», «/» = empty


def rows_of(path):
    p = Path(path)
    if p.suffix.lower() == '.csv':
        with open(p, encoding='utf-8-sig') as f:
            return list(csv.reader(f))
    from python_calamine import CalamineWorkbook
    wb = CalamineWorkbook.from_path(str(p))
    return wb.get_sheet_by_name(wb.sheet_names[0]).to_python()


def phone(s):
    d = re.sub(r'\D', '', s.translate(FA_DIGITS))
    if d.startswith('0098'): d = '0' + d[4:]
    elif d.startswith('98') and len(d) == 12: d = '0' + d[2:]
    if len(d) == 10 and d.startswith('9'): d = '0' + d      # Excel dropped the leading zero
    return d


def main():
    path = sys.argv[1]
    tag = norm(sys.argv[2]) if len(sys.argv) > 2 else Path(path).stem
    rows = [[norm(c) for c in r] for r in rows_of(path)]
    rows = [r for r in rows if any(r)]
    head = [h.lower() for h in rows[0]]
    col = {}
    for key, names in HEADERS.items():
        for n in names:                                   # exact header first, then "contains"
            if n.lower() in head:
                col[key] = head.index(n.lower()); break
        else:
            for i, h in enumerate(head):
                if i not in col.values() and any(n.lower() in h for n in names):
                    col[key] = i; break
    if 'name' in col and col.get('firstname') == col['name']:
        col.pop('firstname')
    get = lambda r, k: r[col[k]] if k in col and col[k] < len(r) else ''
    out, skipped = [], 0
    for r in rows[1:]:
        first, last = get(r, 'firstname'), get(r, 'lastname')
        if 'name' in col and not (first or last):
            parts = get(r, 'name').split(' ')
            first, last = (' '.join(parts[:1]), ' '.join(parts[1:])) if len(parts) > 1 else ('', parts[0])
        if not last:
            last, first = first, ''
        if not re.search(r'\w', first + last):          # «--» etc.: name the contact by its job title
            first, last = '', get(r, 'title') or get(r, 'org')
        if not last:
            skipped += 1; continue
        g = get(r, 'gender')
        sal = 'Mr.' if re.search(r'آقا|مرد|male|^m$|mr', g, re.I) and not re.search(r'female|^f$', g, re.I) else \
              'Ms.' if re.search(r'خانم|زن|female|^f$|ms|mrs', g, re.I) else ''
        out.append({'firstname': first, 'lastname': last, 'mobile': phone(get(r, 'phone')), 'title': get(r, 'title'),
                    'account': get(r, 'org'), 'city': get(r, 'city'), 'salutation': sal, 'email': get(r, 'email').lower()})
    print(json.dumps({'tag': tag, 'contacts': out}, ensure_ascii=False, indent=1))
    print(f'{len(out)} contacts, {skipped} rows without a name skipped; columns: {col}', file=sys.stderr)


if __name__ == '__main__':
    main()
