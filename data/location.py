"""City / country for contacts, derived only from what the sheets actually contain.

Home (Vtiger "Mailing" address):
  country  <- nationality column  >  phone country code  >  visa city
              (Iranian +98 numbers are NOT used: foreign students use local SIMs, and one agent number is
               shared by 135 students; malformed +7... numbers are ignored too)
  city     <- visa city (شهر محل اخذ ویزا) when present - the sheets carry no other home-city data
Study (Vtiger "Other" address):
  city     <- city of the university, country = ایران
`country_source` records how the home country was obtained, so derived values are never mistaken for typed ones.
"""

NATIONALITY = {"Afghanistan": "افغانستان", "iraq": "عراق", "Iraq": "عراق", "syria": "سوریه", "Syria": "سوریه",
               "افغانستان": "افغانستان", "عراق": "عراق", "سوریه": "سوریه"}

PHONE_CC = [  # longest prefix first
    ("+964", "عراق"), ("+963", "سوریه"), ("+961", "لبنان"), ("+962", "اردن"), ("+965", "کویت"),
    ("+966", "عربستان سعودی"), ("+967", "یمن"), ("+968", "عمان"), ("+971", "امارات متحده عربی"),
    ("+973", "بحرین"), ("+974", "قطر"), ("+994", "آذربایجان"), ("+992", "تاجیکستان"), ("+993", "ترکمنستان"),
    ("+90", "ترکیه"), ("+92", "پاکستان"), ("+93", "افغانستان"), ("+20", "مصر"), ("+91", "هند"),
]

VISA_CITY = {  # visa city as written -> (city, country)
    "بغداد": ("بغداد", "عراق"), "النجف": ("نجف", "عراق"), "نجف": ("نجف", "عراق"),
    "کربلاء": ("کربلا", "عراق"), "کربلا": ("کربلا", "عراق"), "البصرة": ("بصره", "عراق"), "بصره": ("بصره", "عراق"),
    "اربیل": ("اربیل", "عراق"), "ادلب": ("ادلب", "سوریه"), "دمشق": ("دمشق", "سوریه"), "کابل": ("کابل", "افغانستان"),
    "هرات": ("هرات", "افغانستان"), "مزار شریف": ("مزار شریف", "افغانستان"),
}

UNIVERSITY_CITY = {
    "دانشگاه ارومیه": "ارومیه", "دانشگاه صنعتی ارومیه": "ارومیه", "دانشگاه قم": "قم", "دانشگاه صنعتی قم": "قم",
    "دانشگاه ادیان و مذاهب": "قم", "دانشگاه المصطفی": "قم", "شهاب دانش قم": "قم", "دانشگاه شهرکرد": "شهرکرد",
    "دانشگاه لرستان": "خرم‌آباد", "دانشگاه رازی": "کرمانشاه", "دانشگاه آزاد کرمانشاه": "کرمانشاه",
    "دانشگاه آزاد اصفهان": "اصفهان", "دانشگاه اصفهان": "اصفهان", "دانشگاه مازندران": "بابلسر",
    "دانشگاه چمران اهواز": "اهواز", "دانشگاه تربیت مدرس": "تهران", "دانشگاه آزاد جنوب": "تهران",
    "دانشگاه آزاد علوم و تحقیقات": "تهران", "دانشگاه آزاد تحقیقات": "تهران", "دانشگاه ایران": "تهران",
    "دانشگاه شهید مدنی تبریز": "تبریز", "دانشگاه ایلام": "ایلام", "دانشگاه اراک": "اراک",
    "دانشگاه بوعلی سینا": "همدان", "آزاد شیراز": "شیراز", "دانشگاه آزاد شیراز": "شیراز",
    "دانشگاه فردوسی": "مشهد", "دانشگاه مشهد": "مشهد", "دانشگاه آزاد قزوین": "قزوین",
    "دانشگاه انوشیروان": "بابل", "دانشگاه نوشیروانی": "بابل",
}


def phone_country(number):
    n = (number or "").strip()
    if not n.startswith("+") or n.startswith("+98") or n.startswith("+7"):
        return ""
    for cc, country in PHONE_CC:
        if n.startswith(cc):
            return country
    return ""


def locate(contact):
    """-> dict(mailingcity, mailingcountry, othercity, othercountry, country_source)"""
    F = contact["fields"]
    home_city, home_country, source = "", "", ""
    vc = VISA_CITY.get((F.get("visa_city") or "").strip())
    nat = NATIONALITY.get((F.get("nationality") or "").strip())
    if nat:
        home_country, source = nat, "ملیت (فایل)"
    if not home_country:
        for num in [contact.get("mobile"), contact.get("phone")] + list(contact.get("_extra_phones") or []):
            c = phone_country(num)
            if c:
                home_country, source = c, f"پیش‌شماره تلفن {num[:4]}"
                break
    if vc:
        home_city = vc[0]
        if not home_country:
            home_country, source = vc[1], "شهر محل اخذ ویزا"
    uni = (F.get("university") or "").strip()
    study_city = UNIVERSITY_CITY.get(uni, "")
    return dict(mailingcity=home_city, mailingcountry=home_country,
                othercity=study_city, othercountry="ایران" if uni else "",
                country_source=source)
