"""Shared definition of the custom Contacts fields (used by make_schema.py and import_to_vtiger.py)."""

# (dataset key, label, kind)  kind: text | picklist | long
BLOCKS = [
    ("Student Profile", [
        ("passport", "Passport No", "text"),
        ("father_name", "Father Name", "text"),
        ("full_name_native", "Full Name (Persian/Arabic)", "text"),
        ("full_name_latin", "Full Name (Latin)", "text"),
        ("gender", "Gender", "picklist"),
        ("nationality", "Nationality", "picklist"),
        ("student_no", "University Student No", "text"),
        ("tracking_code", "Saorg / Tracking Code", "text"),
        ("row_code", "List Code", "text"),
        ("level", "Level", "picklist"),
        ("level_original", "Level (as written in sheet)", "text"),
        ("university", "University", "picklist"),
        ("university_original", "University (as written in sheet)", "text"),
        ("faculty", "Faculty", "text"),
        ("major", "Major", "picklist"),
        ("specialization", "Specialization", "text"),
        ("gpa", "GPA", "text"),
        ("broker", "Broker / Agent", "text"),
        ("visa_city", "Visa City", "text"),
        ("marital", "Marital Status", "text"),
        ("entry_term", "Entry Term", "text"),
        ("entry_type", "Entry Type", "text"),
        ("study_start", "Study Start Date", "text"),
        ("student_status", "Student Status", "picklist"),
        ("source_groups", "Source Lists / Groups", "long"),
        ("phone_raw", "Phone (as written in sheet)", "text"),
        ("faculty_code", "Faculty Code", "text"),
        ("major_code", "Major Code", "text"),
        ("level_code", "Level Code", "text"),
        ("course_period", "Course Period", "text"),
        ("edu_mode", "Education Mode", "text"),
        ("overall_status", "Overall Status", "text"),
        ("edu_rule", "Education Rule", "text"),
        ("list_label", "List Label", "text"),
        ("notes", "Notes (sheet)", "long"),
    ]),
    ("Admission & Documents Workflow", [
        ("docs_to_carrier", "Docs Handed To Carrier", "text"),
        ("docs_to_translator", "Docs Handed To Translation Office", "text"),
        ("docs_status", "Documents Status", "text"),
        ("docs_to_university", "Docs Delivered To University", "text"),
        ("sent_to", "File Sent To (engineer/university)", "text"),
        ("changes", "Changes / Issues", "long"),
        ("admission_status", "Admission Status", "text"),
        ("changes_after_admission", "Changes After Initial Admission", "long"),
        ("invoice_sent", "Invoice Sent", "text"),
        ("receipt_no", "Receipt No", "text"),
        ("employment_folder", "Employment-Study Folder", "text"),
        ("employment_status", "Employment-Study Status", "text"),
        ("persian_course", "Persian Course", "text"),
    ]),
    ("Payments & Fees", [
        ("pay_tuition", "Paid: Tuition (to institute)", "text"),
        ("pay_university_tuition", "Paid: Tuition (to university)", "text"),
        ("pay_persian", "Paid: Persian Language", "text"),
        ("pay_docs", "Paid: Doc Approval & Translation", "text"),
        ("pay_gpa", "Paid: GPA Evaluation", "text"),
        ("pay_paid_univ", "Paid: Paid Universities", "text"),
        ("pay_visa", "Paid: Visa", "text"),
        ("pay_photoshop", "Paid: Photoshop", "text"),
        ("pay_univ_services", "Paid: University Services", "text"),
        ("pay_generic", "Payment (generic / transfer amount)", "text"),
        ("lang_fee", "Language Fee", "text"),
        ("translator_fee", "Translator Fee", "text"),
    ]),
]

PREFIX = "cf_ss_"


def cf_name(key):
    return PREFIX + key


def all_keys():
    return [k for _, fs in BLOCKS for k, _, _ in fs]
