#!/usr/bin/env python3
"""Tests of the data pipeline rules (normalisation, merge keys, derived details). Invented sample values only.

  python3 -m unittest discover -s tests -v        (needs: pip install python-calamine)
"""
import os
import sys
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "data"))

import build_dataset as bd  # noqa: E402
import enrich  # noqa: E402
import location  # noqa: E402


class Normalise(unittest.TestCase):
    def test_letters_and_digits(self):
        self.assertEqual(bd.norm("  علي   كريم "), "علی کریم")       # Arabic ي/ك -> Persian
        self.assertEqual(bd.norm("۱۲۳٤٥٦"), "123456")                # Persian and Arabic digits
        self.assertEqual(bd.norm(None), "")

    def test_cell(self):
        self.assertEqual(bd.cell(12.0), "12")
        self.assertEqual(bd.cell(float("nan")), "")
        self.assertEqual(bd.cell(None), "")
        self.assertEqual(bd.cell(" a  b "), "a b")

    def test_phone(self):
        cases = {
            "07701234567": "+9647701234567",       # Iraqi, leading 0
            "7701234567": "+9647701234567",        # Iraqi, no 0
            "9647701234567": "+9647701234567",
            "009647701234567": "+9647701234567",
            "+964 770 123 4567": "+9647701234567",
            "09121234567": "+989121234567",        # Iranian mobile
            "9121234567": "+989121234567",
            "۰۹۱۲۱۲۳۴۵۶۷": "+989121234567",        # Persian digits
        }
        for raw, want in cases.items():
            self.assertEqual(bd.norm_phone(raw)[0], want, raw)
        self.assertEqual(bd.norm_phone(""), ("", ""))
        self.assertEqual(bd.norm_phone("12345")[0], "12345")          # odd length: kept as written
        self.assertEqual(bd.norm_phone(7701234567.0)[0], "+9647701234567")  # numeric cell

    def test_passport(self):
        self.assertEqual(bd.norm_passport(" a-123 4567 "), "A1234567")
        self.assertEqual(bd.passport_key("A01234567"), ("1234567", "A"))
        self.assertEqual(bd.passport_key(""), ("", ""))

    def test_level_gender_university(self):
        self.assertEqual(bd.norm_level("دکتری تخصصی"), "PhD")
        self.assertEqual(bd.norm_level("کارشناسی ارشد"), "MSc")      # "ارشد" wins over "کارشناسی"
        self.assertEqual(bd.norm_level("کارشناسی"), "BSc")
        self.assertEqual(bd.norm_level("کاردانی"), "Associate")
        self.assertEqual(bd.norm_level(""), "")
        self.assertEqual(bd.norm_level("زبان"), "Other")
        self.assertEqual(bd.norm_gender("خانم"), "Female")
        self.assertEqual(bd.norm_gender("مرد"), "Male")
        self.assertEqual(bd.norm_gender("?"), "")
        self.assertEqual(bd.norm_university("ارومیه"), "دانشگاه ارومیه")
        self.assertEqual(bd.norm_university("؟؟"), "")
        self.assertEqual(bd.norm_university("دانشگاه مدرس"), "دانشگاه تربیت مدرس")

    def test_headers(self):
        self.assertEqual(bd.map_header("نام پدر"), "father_name")
        self.assertEqual(bd.map_header(" نام   خانوادگي "), "surname")
        self.assertIsNone(bd.map_header("ستون ناشناخته"))


class MergeKeys(unittest.TestCase):
    SRC = {"_src": {"file": "f.xlsx", "sheet": "s", "row": 3}}

    def key(self, **rec):
        return bd.person_key(dict(self.SRC, **rec))

    def test_split_name(self):
        self.assertEqual(bd.split_name({"full_name": "علی حسن کریم"}), ("علی", "حسن کریم", ""))
        self.assertEqual(bd.split_name({"full_name": "علی"}), ("", "علی", ""))
        self.assertEqual(bd.split_name({"first_name": "علی", "surname": "کریم", "father_name": "حسن"}),
                         ("علی", "کریم", "حسن"))

    def test_passport_first(self):
        a = self.key(passport="A1234567", full_name="علی حسن کریم")
        b = self.key(passport="a-01234567", full_name="نام دیگر")
        self.assertEqual(a, b)                                        # same person whatever the name says
        self.assertEqual(a, (("P", "1234567"), "A"))

    def test_long_name_then_phone(self):
        self.assertEqual(self.key(full_name="علي حسن كريم")[0], self.key(full_name="علی  حسن کریم")[0])
        self.assertEqual(self.key(full_name="علی کریم", phone="07701234567")[0][0], "NT")
        # a short name with no phone never merges: the key carries file, sheet and row
        k1 = self.key(full_name="علی کریم")[0]
        k2 = bd.person_key({"full_name": "علی کریم", "_src": {"file": "f.xlsx", "sheet": "s", "row": 4}})[0]
        self.assertEqual(k1[0], "NS")
        self.assertNotEqual(k1, k2)

    def test_phone_only_and_empty(self):
        self.assertEqual(self.key(phone="7701234567")[0], ("T", "9647701234567"))
        self.assertEqual(self.key(), (None, ""))


class Derived(unittest.TestCase):
    def contact(self, **kw):
        c = {"lastname": "کریم", "mobile": "", "phone": "", "email": "", "fields": {}}
        c.update(kw)
        return c

    def test_phone_country(self):
        self.assertEqual(location.phone_country("+9647701234567"), "عراق")
        self.assertEqual(location.phone_country("+93701234567"), "افغانستان")
        self.assertEqual(location.phone_country("+989121234567"), "")   # Iranian SIMs say nothing about home
        self.assertEqual(location.phone_country("07701234567"), "")
        self.assertEqual(location.phone_country(None), "")

    def test_locate_priority(self):
        # nationality beats the phone code; the visa city only gives the city then
        c = self.contact(mobile="+9647701234567", fields={"nationality": "Syria", "visa_city": "بغداد",
                                                           "university": "دانشگاه قم"})
        r = location.locate(c)
        self.assertEqual((r["mailingcountry"], r["mailingcity"]), ("سوریه", "بغداد"))
        self.assertEqual((r["othercity"], r["othercountry"]), ("قم", "ایران"))
        self.assertEqual(r["country_source"], "ملیت (فایل)")
        r = location.locate(self.contact(mobile="+9647701234567"))
        self.assertEqual(r["mailingcountry"], "عراق")
        self.assertTrue(r["country_source"].startswith("پیش‌شماره"))
        r = location.locate(self.contact(fields={"visa_city": "کابل"}))
        self.assertEqual((r["mailingcountry"], r["country_source"]), ("افغانستان", "شهر محل اخذ ویزا"))
        r = location.locate(self.contact())
        self.assertEqual(set(r.values()), {""})                        # nothing known -> nothing guessed

    def test_operator(self):
        self.assertEqual(enrich.operator("+9647701234567"), "آسیاسل (عراق)")
        self.assertEqual(enrich.operator("+989121234567"), "همراه اول")
        self.assertEqual(enrich.operator("+989351234567"), "ایرانسل")
        self.assertEqual(enrich.operator("+96477"), "")
        self.assertEqual(enrich.operator(""), "")

    def test_enrich(self):
        c = self.contact(_extra_phones=["+9647801234567"], fields={"level": "MSc", "passport": "A1234567"})
        enrich.enrich(c, [{"نام": "x", "رقم الواتساب": "07701234567"}], bd.norm_phone)
        F = c["fields"]
        self.assertEqual(F["whatsapp"], "+9647701234567")
        self.assertEqual(c["mobile"], "+9647701234567")                # empty mobile takes the WhatsApp number
        self.assertEqual(F["whatsapp_link"], "https://wa.me/9647701234567")
        self.assertEqual(F["mobile_operator"], "آسیاسل (عراق)")
        self.assertEqual(c["otherphone"], "+9647801234567")
        self.assertEqual(c["title"], "مشتری")
        self.assertEqual(F["profile_completeness"], "44%")            # name, passport, mobile, level of 9
        self.assertEqual(F["missing_info"], "ایمیل، دانشگاه، رشته، کشور، کد پیگیری")

    def test_enrich_nothing_known(self):
        c = self.contact(lastname="Unnamed +964")
        enrich.enrich(c, [], bd.norm_phone)
        self.assertEqual(c["fields"]["whatsapp_link"], "")
        self.assertEqual(c["fields"]["profile_completeness"], "0%")
        self.assertEqual(c["title"], "")


if __name__ == "__main__":
    unittest.main()
