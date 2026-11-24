#!/usr/bin/env python3
"""Tests of the student-documents import rules (folder context, names, passport reading, document types).
Invented sample values only.   python3 -m unittest discover -s tests -v   (needs: pip install python-calamine)"""
import os
import sys
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "docs_import"))
sys.path.insert(0, os.path.join(ROOT, "data"))

import common as c  # noqa: E402
import interpret as I  # noqa: E402


def mrz_line2(number, nat, birth, sex, expiry):
    cd = lambda s: str(I._check(s))
    num = number.ljust(9, "<")
    body = num + cd(num) + nat + birth + cd(birth) + sex + expiry + cd(expiry) + "<" * 14
    return body + "00"


class FolderContext(unittest.TestCase):
    def test_university_term_year_level_major_person(self):
        x = c.context("uni/دانشگاه ها/قم بهمن 1404/کارشناسی ارشد/حقوق-خصوصی/علي كريم+/a.jpg")
        self.assertEqual((x["university"], x["term"], x["year"], x["level"]), ("دانشگاه قم", "بهمن", "1404", "MSc"))
        self.assertEqual(x["major"], "حقوق - خصوصی")
        self.assertEqual((x["person"], x["flags"]), ("علی کریم", ["plus"]))

    def test_student_directly_under_level(self):
        x = c.context("UNI3/قم بهمن 1404/دکتری/محمد عباس محمد+/p.jpg")
        self.assertEqual((x["level"], x["person"]), ("PhD", "محمد عباس محمد"))
        self.assertNotIn("major", x)

    def test_outer_university_wins_and_suffix_is_dropped(self):
        x = c.context("uni/دانشگاه ها/پزشکی قم مهر 1404/qom/سارا علی+/a.jpg")
        self.assertEqual(x["university"], "دانشگاه علوم پزشکی قم")
        x = c.context("uni2/لیست دانشجویان/دانشگاه مازندران/علی عبدعون المعموری_مازندران/a.jpg")
        self.assertEqual((x["university"], x["person"]), ("دانشگاه مازندران", "علی عبدعون المعموری"))

    def test_loose_file_has_no_person(self):
        self.assertNotIn("person", c.context("UNI3/photo123.jpg"))
        self.assertNotIn("person", c.context("uni/دانشگاه ها/ارومیه مهر 1405/list.xlsx"))

    def test_incomplete_flag(self):
        x = c.context("uni/دانشگاه ها/دانشجویان البرز/لوا النعیمی ناقصی/a.jpg")
        self.assertEqual((x["person"], x["flags"], x["university"]), ("لوا النعیمی", ["incomplete"], "پردیس البرز دانشگاه تهران"))


class Names(unittest.TestCase):
    def test_name_key(self):
        self.assertEqual(c.name_key("عبد الله الكروي"), c.name_key("عبدالله کروی"))
        self.assertEqual(c.name_key("أحمد  إبراهيم آل علي"), c.name_key("احمد ابراهیم ال علی"))
        self.assertNotEqual(c.name_key("علی کریم"), c.name_key("علی کاظم"))

    def test_label_keeps_alef_madda(self):
        self.assertEqual(c.label("آموزش  زبان انگليسي"), "آموزش زبان انگلیسی")

    def test_passports_in_text(self):
        self.assertEqual(c.passports_in("Passport No: A12345678, old B 87654321."), ["A12345678", "B87654321"])
        self.assertEqual(c.passports_in("phone 07701234567"), [])


class Passport(unittest.TestCase):
    L1 = "P<IRQALSAMPLE<<ALI<HASAN<KAREEM<<<<<<<<<<<<<<<<<"

    def test_reads_fields_and_checks_digit(self):
        l2 = mrz_line2("A12345678", "IRQ", "900215", "M", "300101")
        m = I.parse_mrz([[self.L1, 0.9, 0, 0], [l2, 0.9, 0, 10]])
        self.assertEqual((m["passport"], m["mrz_valid"]), ("A12345678", True))
        self.assertEqual((m["birth_date"], m["expiry"], m["sex"]), ("1990-02-15", "2030-01-01", "Male"))
        self.assertEqual((m["surname_latin"], m["given_latin"], m["nationality"]), ("ALSAMPLE", "ALI HASAN KAREEM", "عراق"))

    def test_ocr_confusions_are_repaired(self):
        l2 = mrz_line2("A12345678", "IRQ", "900215", "F", "300101").replace("IRQ", "1RQ", 1)
        l2 = l2[:3] + "O" + l2[4:] if l2[3] == "0" else l2                   # a zero read as the letter O
        m = I.parse_mrz([[self.L1, 0.9, 0, 0], [l2, 0.9, 0, 10]])
        self.assertEqual((m["nationality_code"], m["sex"]), ("IRQ", "Female"))

    def test_wrong_digit_is_flagged(self):
        l2 = mrz_line2("A12345678", "IRQ", "900215", "M", "300101")
        bad = "A12345679" + l2[9:]
        self.assertFalse(I.parse_mrz([[self.L1, 0.9, 0, 0], [bad, 0.9, 0, 10]])["mrz_valid"])

    def test_no_mrz(self):
        self.assertEqual(I.parse_mrz([["Ministry of Higher Education", 0.9, 0, 0]]), {})


class DocumentType(unittest.TestCase):
    def rec(self, *texts, boxes=None, w=900, h=1280):
        return {"lines": [[t, 0.9, 0, i * 10] for i, t in enumerate(texts)], "boxes": boxes if boxes is not None else len(texts), "w": w, "h": h}

    def test_passport(self):
        l2 = mrz_line2("A12345678", "IRQ", "900215", "M", "300101")
        k, det = I.classify(self.rec("REPUBLIC OF IRAQ", "PASSPORT", Passport.L1, l2))
        self.assertEqual((k, det["passport"]), ("passport", "A12345678"))

    def test_cv_with_short_title(self):
        r = self.rec("CV", "Name: Ali Hasan Kareem", "Work: teacher at Sample University College", "WhatsApp number: 07701234567",
                     "Email: sample@example.com", "Holdings", "Master's degree document")
        self.assertEqual(I.classify(r)[0], "cv")
        f = I.cv_fields(r)
        self.assertEqual((f["name_latin"], f["email"], f["phone"]), ("Ali Hasan Kareem", "sample@example.com", "07701234567"))
        self.assertIn("teacher", f["job"])

    def test_certificate_and_transcript(self):
        k, det = I.classify(self.rec("Ministry of Higher Education", "To Whom it May Concern", "has got a Master Degree in Physics", "University of Sample"))
        self.assertEqual((k, det.get("level")), ("degree_certificate", "master"))
        k, det = I.classify(self.rec("University of Sample", "Bachelor", "First Year", "Subject", "Mark", "Units", "Arabic Language"))
        self.assertEqual((k, det.get("level")), ("transcript", "bachelor"))

    def test_photo_and_arabic_page(self):
        self.assertEqual(I.classify(self.rec(boxes=1, w=600, h=800))[0], "photo")
        r = {"lines": [["ylua", 0.3, 0, 0]], "boxes": 40, "w": 900, "h": 1280}
        self.assertEqual(I.classify(r)[0], "arabic_document")
        self.assertEqual(I.classify(r, "master_degree")[0], "master_degree")       # the file name says what it is

    def test_certificate_fields(self):
        r = self.rec("University of Sample", "with an average of (82.36)", "for the academic year 2013-2014")
        f = I.certificate_fields(r)
        self.assertEqual((f["university_latin"], f["average"], f["years"][-1]), ("University of Sample", "82.36", 2014))


if __name__ == "__main__":
    unittest.main()
