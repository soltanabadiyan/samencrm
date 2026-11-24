# SamenCRM — سامانه مدیریت ارتباط با مشتری

Vtiger CRM 8.4 turned into a Persian product: RTL, Shamsi calendar, SamenCRM theme and branding, Telegram messaging,
dashboard widgets and an in-app academy — plus the kit that deploys it with Docker and loads a company's data.
It started as the CRM of SamenStudent (admission & support of international students) and now serves several
companies from one server, one instance per company. This repository holds no Vtiger source: everything is added on
top as standard Vtiger packages (language pack + extension modules) and scripts.

Everything is driven from your PC with `run_all.sh`; each instance lives in its own folder on the server
(`/opt/crm_students` by default) as a Docker stack.

## Server address  (`server.env`)
The server's address is **not in git**. Copy `server.env.example` to `server.env` and fill it in; `run_all.sh` and
the tools in `tools/` read it (variables set in the environment win):

| variable | meaning |
|---|---|
| `SSH_TARGET` | `user@host` for ssh/scp (`root@…`, or a user with passwordless sudo) |
| `SSH_PORT` | ssh port (default 22) |
| `SSH_ALIAS` | `Host` entry of `~/.ssh/config` used by `tools/get_session.sh` and `tools/sync_theme.sh` (default: `SSH_TARGET`) |
| `SERVER_HOST` | address users open in the browser (default: host part of `SSH_TARGET`) |

Below, `<server>` stands for that address and `crm-server` for the ssh alias.

Admin login: `admin` / password generated on the server in `<instance>/.env` (`VT_ADMIN_PASSWORD`, root-only):
`ssh crm-server 'grep VT_ADMIN /opt/crm_students/.env'`. Change it after the first login (My Preferences).

## Ports
* **9081** = Vtiger web of the first instance (`WEB_PORT`; other instances take another port, e.g. 9071)
* **9082** = Adminer (DB UI, optional), bound to `127.0.0.1` only. Use `ssh -L 9082:127.0.0.1:9082 crm-server` and
  `docker compose --profile tools up -d adminer`.

## VPN note
With the PC's full-tunnel VPN (tun*) on, a server that drops foreign IPs is unreachable. `run_all.sh` detects this and
sends SSH out of the LAN interface through `tools/direct_proxy.py` (SO_BINDTODEVICE). Routes are not changed.
For a manual shell: `ssh -o ProxyCommand='python3 tools/direct_proxy.py %h %p wlp5s0' crm-server`.
The browser behind the VPN can't open the CRM either; open it with the VPN off, or add the server to the VPN's bypass list.

## Server-side fixes baked in
* `deb.debian.org` is blocked from the server, so the image uses `http://mirror.iranserver.com` (`DEBIAN_MIRROR` build arg).
* Base image is `php:8.2-apache-bookworm` (trixie has no `libc-client-dev`, which the IMAP extension needs).
* The web installer is scripted (`deploy/scripts/auto_install.sh`), so no browser wizard is needed. Currency is IRR and the timezone Asia/Tehran.

## Steps
```bash
cp server.env.example server.env        # 0. once: the server's address
./run_all.sh build                      # 1. Excel (../xls, or XLS_DIR=...) -> output/ (check output/report.txt and students.csv)
./run_all.sh install                    # 2. docker stack on the server + automated web installer
./run_all.sh import --limit 20          # 3a. optional test import of 20 contacts
./run_all.sh import                     # 3b. full import (resumable; re-running skips what exists)
./run_all.sh details                    # derived contact details (see below)
./run_all.sh persian                    # Persian language pack + PersianCalendar + SamenTheme
./run_all.sh widgets                    # reminder bell + dashboard widgets
./run_all.sh academy                    # help on every page + academy
./run_all.sh telegram                   # Telegram module (see below)
```
If the server cannot reach SourceForge, download `vtigercrm8.4.0.tar.gz` on your PC and run
`VTIGER_TARBALL=/path/vtigercrm8.4.0.tar.gz ./run_all.sh install`.
Server needs `python3` (for the importer).

## Several companies on one server
Each company is its own instance: a folder, a port and a Docker Compose project of its own (database, files,
passwords and backups are separate).
```bash
APP_DIR=/opt/crm_gama WEB_PORT=9071 COMPANY=GAMA ./run_all.sh install
APP_DIR=/opt/crm_gama ./run_all.sh persian        # then widgets, academy
APP_DIR=/opt/crm_gama ./run_all.sh contacts /path/contacts.xlsx [tag]
```
* The instances on the server are `/opt/crm_students` (SamenStudent, port 9081 – the default of every command) and
  `/opt/crm_gama` (GAMA, port 9071). Their Docker Compose projects are still called `vtiger` and `gama` (the folders
  were renamed later; a project's name is what its containers and network are named after).
* **Renaming an instance's folder**: the containers mount `data/` by absolute path, so stop it first –
  `docker compose stop`, `mv`, then `docker compose up -d` in the new folder (it recreates the containers on the new
  paths), and point its Telegram cron file (`/etc/cron.d/samencrm-telegram-<folder>`) at the new folder.
* `APP_DIR` selects the instance for every command; `import` and `details` talk to that instance's own port
  (`WEB_PORT` in its `.env`).
* `contacts` loads a **simple contact sheet** (name, phone, title, organisation, city, gender) as Organizations +
  Contacts (`vtiger/sheet_to_contacts.py`, `vtiger/import_contacts.php`), with a DB backup first. The student import
  (`build` / `import` / `details`) is specific to the SamenStudent sheets.
* `telegram` works per instance too (`APP_DIR=/opt/crm_gama ./run_all.sh telegram`): each instance has its own bots,
  settings, queue and host cron file (`/etc/cron.d/samencrm-telegram-<folder>`).
* **Domain behind a reverse proxy**: on the server run
  `sudo bash /opt/crm_gama/scripts/set_public_url.sh /opt/crm_gama https://crm.example.com`. It sets Vtiger's `$site_URL`
  (Vtiger redirects every other address to it, which otherwise breaks the login) and makes Apache honour the
  proxy's `X-Forwarded-Proto`.

## Student documents import  (`docs_import/`)
The office's folders of student documents (scans of passports, certificates, transcripts, photos, CVs; Word / Excel
application forms; list workbooks; admission PDFs; zipped student folders) become CRM data: one contact per student,
every document attached to it. Run on the PC, except the last step.

```bash
python3 docs_import/unpack_archives.py DOCS output/docs     # .rar/.zip -> output/docs/unpacked (7z, unrar)
python3 docs_import/prep_flat.py DOCS output/docs           # one small JPEG per scan / scanned PDF page, pdftotext
python3 docs_import/prep_flat.py output/docs/unpacked output/docs
python3 docs_import/ocr_flat.py output/docs/flat output/docs 0 1      # OCR (RapidOCR, CPU). On a many-core machine:
                                                            #   copy flat/ there, one process per 2 cores (see the file's header)
python3 docs_import/extract.py DOCS output/docs             # folder context, forms, lists, PDFs  -> files.json, lists.json
python3 docs_import/build_students.py output/docs crm_contacts.tsv    # one record per student -> students.json, report.txt
python3 docs_import/make_import.py output/docs              # -> import.json, review.csv, stage/files/<md5>.<ext>
# copy import.json, crm_docs_import.php and stage/files/ to <instance>/ss/docs/, back up the DB, then in the instance:
docker compose exec -T -u www-data web php /opt/ss/docs/crm_docs_import.php setup  /opt/ss/docs/import.json
docker compose exec -T -u www-data web php /opt/ss/docs/crm_docs_import.php import /opt/ss/docs/import.json [--only ID,ID] [--no-files]
```
`crm_contacts.tsv` = the contacts' identity columns exported from the CRM (see `build_students.py match_crm`).
Needs `pip install python-calamine python-docx rapidocr-onnxruntime pillow` (a venv), `pdftotext`/`pdftoppm`, `7z`, `unrar`.

* **Folder = context.** `<university> <مهر|بهمن> <year>/[<level>/[<major>/]]<student>/…` gives university, intake, level,
  major and the student's name (`common.py`: the list of universities, `+` and `ناقصی` marks, `-مازندران` suffixes).
* **What is read**: passports through their machine-readable lines (number with its check digit, birth date, sex,
  expiry, nationality, Latin name); CV pages (phone, e-mail, address, work); English certificates / transcripts (degree
  level, university, average, year); the Word application form and the per-student Excel form (parents, birth, marital
  status, previous education with GPA, level and major applied for, questions); list workbooks (tracking code, agent,
  admission status, student number, payments – header names of `data/build_dataset.py`); admission / registration PDFs.
  Arabic and Persian scans cannot be read by the OCR: they are attached as "مدرک (عربی/فارسی)" or typed by their file name.
* **No duplicates**: a student is one passport (old and renewed passports are both kept); else a byte-identical file
  in two folders; else the same full name (3+ words; 2 words only inside one university). Against the CRM: passport,
  passport digits (lists typed without the letter), a phone that belongs to one contact, a full name. A number whose
  check digit fails is never a key. Phones / e-mails shared by 3+ people are the agent's (`cf_ss_agent_contact`).
* **Level** when the folder does not state it: the application form, the lists, else one above the highest degree
  among the documents ("از روی مدارک" in `cf_ss_level_source`) – a folder "ارومیه 1405" with a master's certificate is a
  PhD applicant.
* **New fields** (3 blocks on Contacts, 37 fields): وضعیت فعلی دانشجو ("دانشجوی دکتری ورودی مهر 1405 – دانشگاه ارومیه"),
  دانشگاه فعلی, ترم/سال ورود, مرحله پرونده, وضعیت مدارک / مدارک ناموجود / انواع مدارک, سوابق درخواست‌ها; نام مادر, محل تولد,
  تاریخ صدور و انقضای گذرنامه, وضعیت گذرنامه; آخرین مدرک, رشته, دانشگاه, معدل و سال; وضعیت شغلی, شغل, منبع تأمین هزینه …
  `vtiger_contactscf` is at MySQL's row-size limit, so columns that cannot be added as VARCHAR are added as TEXT.
* **Update rules**: identity and contact fields are only filled when empty; the import's own fields are always set;
  university / level / major / entry term are replaced when a folder states the intake (the old values go to
  سوابق درخواست‌ها). Existing contacts are written with SQL (no workflow fires); new ones the Vtiger way.
* **Documents**: one Documents record per file, in a folder per type (گذرنامه, عکس پرسنلی, مدارک تحصیلی, رزومه و فرم‌ها,
  پذیرش و ثبت‌نام, ویزا, مالی, پژوهشی, سایر مدارک دانشجو), tagged with type / university / intake, linked to the contact.
  Attachments are written directly: Vtiger's upload check refuses any image whose bytes contain `<?` (most scans).
* **Tags** on contacts for reports: `مقطع:` `رشته:` `دانشگاه:` `ورودی:` `مرحله:` `مدارک: کامل|ناقص` `ندارد: <مدرک>`
  `گذرنامه: معتبر|منقضی|رو به انقضا` `مدرک قبلی:` `وضعیت شغلی:` `ملیت:` `چند دانشگاه` `منبع:`.
* Resumable: table `samen_docs_import` maps student id -> contact and file -> document; re-running updates fields and
  adds only missing files. `report.txt` lists merges, conflicts and the loose files no person could be given.
* Everything under `output/` is personal data and is never committed.

## Duplicate contacts
`vtiger/merge_duplicates.php` (run in the web container; copy it to `<instance>/ss/` first) lists duplicate contacts,
and merges them with `--apply`:
```bash
docker compose exec -T web php /opt/ss/merge_duplicates.php            # dry run
docker compose exec -T web php /opt/ss/merge_duplicates.php --apply
```
Keys are the passport number, then the phone (last 10 digits); names are not used, and contacts that share a phone
but have different passports are never merged. The kept record takes the empty fields, related records and tags of
the others, which go to the Recycle Bin. No workflow fires. Take a DB backup first.

## Persian UI, Shamsi calendar and the Samen theme  (`./run_all.sh persian`)
All three are standard Vtiger packages (Settings > Module Management can also import the zips by hand):

| package | what | source |
|---|---|---|
| `Persian_fa_ir.zip` (language) | full fa_ir pack: 4,047 strings + labels of the custom student fields/picklists; default language | `i18n/` (`fa/*.txt` = translations, `build_langpack.py` validates placeholders/HTML) |
| `PersianCalendar.zip` (extension) | Jalali dates everywhere in the UI, Jalali date picker on every date field, Shamsi FullCalendar (RTL, week starts Saturday). Dates stay Gregorian in the DB. | `modules/PersianCalendar/` (`tests/convert.test.js`) |
| `SamenTheme.zip` (extension) | **Vtiger 8 custom skin `samen`** (`layouts/v7/custom_skins/samen`): Vtiger's own LESS recompiled with the palette, then the SamenCRM design on top – the look of the Samen cloud panel: cream surfaces, brown text, one teal accent (`#124E5E`); a header with the logo, search and the user box; the app menu as a **permanent icon rail** (drawer on phones/tablets) whose **sub-menu** slides out beside it with the app's modules; "frame" cards, pill buttons, striped tables with a dark header rule, cream dialogs; Vazir font and Simple Line Icons; complete RTL. Brand login page, installer and first-login pages, favicon, company logo, footer, report print. | `modules/SamenTheme/` |

Both extensions have no records, so they are **not in the app menus**; each has an admin page in
**Settings > Extensions**: «پوسته SamenCRM» (status of skin / login page / logo, palette, *apply again*) and
«تقویم شمسی» (status, every user's language / week start / date format, *set all users to Persian*).
Old links such as `index.php?module=SamenTheme&view=List` redirect there.

* The login page preselects the `samen` skin (Vtiger keeps the choice in the session). Vtiger has no hook for the login
  page, so the installer adds one marked block to `layouts/v7/modules/Users/Login.tpl`; it is re-applied on update and removed
  when SamenTheme is disabled.
* Rebuild the skin after changing colours/CSS: `npm i -g less@4`, then `modules/SamenTheme/build.sh`
  (palette of the compiled LESS: `tools/source_less/samen/variables.less`; design tokens, header, rail, sub-menu and
  components: `resources/theme.css`; RTL fixes: `resources/rtl.css`; tablet/phone: `resources/responsive.css`;
  menu highlighting and the dashboard width: `resources/theme.js`).
* Navigation: the rail is Vtiger's own app menu (`.app-menu`), so Settings > Menu Editor still decides which modules
  each app lists. On desktop the old 42px strip of module icons is hidden (its width is the page gutter).
  After a Vtiger upgrade refresh `tools/source_less` and `tools/source_css` from the server and rebuild.
* `tools/sync_theme.sh` copies theme/calendar files straight to the server while developing;
  `tools/get_session.sh` + `tools/ui_shot.mjs` take headless screenshots (needs `ssh -D 19080 crm-server`).
* Data labels are Persian too (groups `مقطع - …`, filters, tags, task/event subjects, list names) – see `data/fa_labels.py`.

## Contact details derived from the sheets  (`./run_all.sh details`)
Rules only – nothing is guessed (`data/location.py`, `data/enrich.py`); only empty fields are filled, so edits made in the
CRM are kept (the two computed indicators are refreshed every run).

| field | rule | contacts |
|---|---|---|
| Mailing country / city (home) | nationality column → phone country code (+964 Iraq, +93 Afghanistan …; Iranian +98 ignored) → visa city; city = visa city | 3,597 / 18 |
| Other city / country (study) | city of the university, ایران | 4,204 |
| Country Source (منبع تعیین کشور) | which rule gave the country | 3,597 |
| WhatsApp (واتساپ) | WhatsApp-labelled columns | 1,066 |
| WhatsApp Chat Link | `https://wa.me/<number>` (WhatsApp number, else mobile) – one click opens a chat | 3,632 |
| Mobile Operator (اپراتور موبایل) | dialling prefix: Asiacell/Zain/Korek (Iraq), Hamrah-e Aval/Irancell/Rightel (Iran) | 3,586 |
| Title (سمت) | "دانشجوی <مقطع>" | 4,036 |
| Profile Completeness / Missing Information | share of 9 key fields filled (name, passport, mobile, email, level, university, major, country, tracking code) and which are missing – use them as follow-up lists | 5,995 |

Contact ids are stable across rebuilds (`_id` ↔ `import_state.json`); a parsing change that alters how rows merge must
be checked against `output/dataset.en_labels.json` before running `details`.

## UI checks (desktop + mobile)
`tools/ui_audit.mjs` drives headless Chrome through `tools/ui_audit_steps.json` (all main pages, the app menu and every
sub-menu, dropdowns, quick-create, date picker, calendar, settings…) on 1440px and 390px viewports;
`tools/contact_sheet.py` turns the screenshots into review sheets. Needs `ssh -D 19080 crm-server` and
`SESSION=$(tools/get_session.sh)`.

`tools/ui_sweep.mjs out_dir tools/ui_sweep_pages.json` is the error sweep: it opens every app-menu and Settings link
(collected from the live app) plus list / create / detail / edit of every module and the extension pages
(`ui_sweep_pages.json`, key pages also at phone width) and reports per page JS exceptions, console errors, failed
requests, PHP/Vtiger error text, horizontal overflow and English words left in the Persian UI; screenshots only for
pages with findings.

## Reminder bell and dashboard widgets  (`./run_all.sh widgets`)
Module `modules/SamenWidgets` (vtlib extension):
- **Bell** at the top-left of the header (floating on phones) with the number of the user's tasks/events whose time is up
  (overdue + due today); the panel lists them. Vtiger's reminder pop-ups, which floated over the right-hand menu,
  are collected into the bell instead.
- **Dashboard widgets** (Home › افزودن ابزارک, placed at the top of every user's default tab on install): کارهای بدون
  زمان باقی‌مانده, Jalali mini calendar (days with open work marked), and charts with bundled Chart.js 4.4.4 –
  contacts by lead source (doughnut), country (pie), sales pipeline (horizontal bars), won amount per month (line),
  leads by status, tickets by status, profile
  completeness (bar), task/event status (polar area), open work in the next 14 days (bar + trend line), new contacts and
  activities over 12 Jalali months (line). Admins see all data, other users their own records.

## Help and academy  (`./run_all.sh academy`)
Module `modules/SamenAcademy` (vtlib extension, menu **Marketing / Sales / Tools › آکادمی SamenCRM**):
- **«راهنما» on every page** (top bar; floating button on phones; keys `?` / `F1`): what the page is for, step-by-step
  tasks, sales & marketing tips, common mistakes, and an explanation of every field on the page (`resources/help/pages.json`
  per module:view, `resources/help/fields.json` per module by visible label). «نشان‌دادن راهنما روی فیلدها» marks
  every field label on the page with its explanation.
- **Academy** (`index.php?module=SamenAcademy&view=Index`): 5 courses / 39 lessons – getting started, SamenCRM for the
  salesperson, admin, professional selling, marketing – with annotated screenshots, workflow diagrams, funnels,
  checklists (progress kept per user in the browser), MathML formulas and calculators (CPL, CAC, LTV, ROI, ROAS,
  payback, pipeline velocity, weighted forecast, NPS, retention, lead score, A/B z-test and sample size).
- Lessons are generated from `modules/SamenAcademy/tools/lessons/*.py` (`python3 tools/lessons/build_all.py`).
- Screenshots: `SESSION=$(tools/get_session.sh) node modules/SamenAcademy/tools/academy_shots.mjs [filter]`
  (`tools/shots.json` = page + numbered markers). Every personal value on the page is replaced with invented sample data
  before capture (`tools/page_tools.js`), and a shot is refused if any original value is still visible.
  Review new images by eye before committing anyway.

## Telegram messaging  (`./run_all.sh telegram`)
Module `modules/TelegramBot` (vtlib extension, menu **Marketing > تلگرام**, settings in **Settings > Extensions > ربات تلگرام**).
Uses the Telegram **Bot API** (bot from @BotFather). `./run_all.sh telegram` builds `dist/TelegramBot.zip`, backs up the DB to
`/opt/crm_students/backups/pre_telegram_*.sql.gz`, installs/updates the module and adds the host cron `/etc/cron.d/samencrm-telegram-<instance folder>`;
`./run_all.sh telegram test` also runs the end-to-end test against a mock Bot API (62 checks, cleans up after itself).

* **Bots**: one or more tokens, stored only in the CRM DB (`vtiger_telegrambot_bots`), shown masked. Never put tokens in git.
* **api.telegram.org is blocked from the server** (connection refused, checked 2026-11-10). Set a proxy in the settings
  (`socks5h://user:pass@host:port` or `http://host:port`) or a reverse-proxy *Bot API address* (e.g. a Cloudflare Worker that forwards
  `/bot…` and `/file/bot…` to api.telegram.org). "Test connection" checks it. Without one, nothing can be sent or received.
* **Linking students**: each contact has a personal link `https://t.me/<bot>?start=c<id>_<random>` (Telegram tab of the contact, copy
  button) – pressing Start links the chat. Students who just open the bot are asked to share their phone number (contact button); it is
  matched on the last 10 digits of mobile/phone/other phone/WhatsApp (only if exactly one contact matches). `/stop` = opt-out, `/start` again = opt-in.
* **Sending**: Contact detail view button "ارسال تلگرام" and the **Telegram tab** (conversation with media, link status, opt-out, quick reply);
  Contacts list > More > "ارسال تلگرام" for the selection; module page for bulk sends by tag, group (level), saved list, picklist value
  (level/university/major/…), missing information, or all linked contacts. Text, photo, file, video, audio (upload or a Vtiger Document) with caption,
  templates with merge fields (`$firstname$ $lastname$ $major$ $university$ $level$ $deeplink$` or any `$cf_…$` field), preview.
* **Scheduling**: "Schedule" with the (Jalali) date picker + time; **birthday messages** once a day at the configured hour (settings).
* **Queue**: Vtiger cron task "TelegramBot Worker" (`cron/modules/TelegramBot/Worker.service`) polls `getUpdates` (no webhook needed),
  expands due campaigns and sends with rate limiting (default 25 msg/s, ≥1 s per chat), retries 429 (retry_after) / network / 5xx with backoff
  (max attempts), marks 403 as blocked. The cron container only runs every 15 min, so the host cron runs that task every minute.
  "Birthdays" is a second task (every 15 min, sends once a day). Media is uploaded once per send and reused by file_id.
* Tables: `vtiger_telegrambot_{bots,settings,links,deeplinks,messages,campaigns,templates}`; files in `storage/TelegramBot/{in,out}`.
  Emoji are stored as `&#x…;` entities because the Vtiger DB connection is utf8 (3-byte).

## What gets imported (SamenStudent)
* **Contacts: 5,995** (9,536 source rows from 23 files / 36 sheets; 8 byte-identical duplicate sheets dropped, rows for the
  same person merged by passport number, then full name (3+ words), then phone). Names stay Persian/Arabic.
* **Every cell of every row is kept**: mapped to a field, and also written verbatim (file | sheet | row | column=value)
  into the contact's *Description*. Verified: 69,631 non-empty cells checked, 0 missing.
  `output/raw_archive.json` is a full copy of all sheets as read.
* **60 custom Contact fields** in 3 blocks (Student Profile / Admission & Documents Workflow / Payments & Fees).
  Picklists: **Level (PhD, MSc, BSc, ...)**, University, **Major** (253 values), Nationality, Gender, Student Status.
* **Tags** on every contact: `Level: PhD/MSc/...` and `Major: <major>` (click a tag to list that group).
* **Groups** (Settings > Groups): `SS Level - PhD / MSc / BSc / Other / Associate / Unknown`; each contact is *assigned* to the
  group of its level.
* **Saved list filters** (113): per level, per university, per major (>=10 students) and per source list.
* **Tasks: 2,310** – one per workflow cell that has a value (documents status, handed to carrier/translator/university,
  file sent to engineer/university, changes/issues, employment-study certificate, invoice, receipt).
  Status = Completed when the text says delivered/translated/issued, otherwise Not Started (due in 7 days).
* **Events: 906** – admission milestones, withdrawals (from the "withdrawn students" list), Persian-course enrolments,
  study-start dates.
  **The sheets contain no dates**, so all tasks/events are dated on the import day – adjust in Vtiger if needed.
  The mapping is in `data/build_dataset.py` (`TASK_DEFS`, `DONE_RE`).

## Things to know (data quality, see output/report.txt)
* 1,909 contacts have no passport and 2,349 no phone (the source files do not have them). 1,955 have no level – mostly the
  ~2,600 numbers from the two phone-only lists (`Unnamed +964...` contacts, grouped under "SS Level - Unknown").
* One phone number appears on 135 different students (placeholder/agent number); a few others repeat 8–43 times.
  27 numbers have an odd length and are kept as written (also in "Phone (as written in sheet)").
* Iraqi numbers are normalised to `+964...`; Iranian 10-digit `9xx...` to `+98...`.
* Same-digit passports with different letters (1 case) are kept as separate people.
* Different values for the same person across sheets: first (richest sheet) wins; the others are listed under
  "MERGE CONFLICTS" in Description.

## Re-running
`./run_all.sh import` is resumable: whatever is in `/opt/crm_students/ss/import_state.json` is skipped. A DB backup is taken
before every run (`/opt/crm_students/backups`). Failures never stop the run; see `/opt/crm_students/ss/import_errors.jsonl`.

## Tests
```bash
python3 -m unittest discover -s tests -v              # data rules: normalisation, merge keys, derived details (pip install python-calamine)
node modules/PersianCalendar/tests/convert.test.js    # Jalali conversion, every day 1950-2060
python3 i18n/build_langpack.py --strict               # every string translated, placeholders/HTML intact
bash tests/server_env.test.sh                         # script syntax, server.env loaders, no address in git
./run_all.sh telegram test                            # on the server: Telegram end-to-end against a mock Bot API
```
GitHub Actions (`.github/workflows/tests.yml`) runs all but the last on every push, plus PHP/Python syntax checks.
The UI is checked against a live instance with `tools/ui_sweep.mjs` / `tools/ui_audit.mjs` (above).

## Layout
```
run_all.sh                    orchestrator (PC)
server.env(.example)          the server's address (server.env is not in git)
data/build_dataset.py         Excel -> dataset (parsing, merge, normalisation, tasks/events)
data/fa_labels.py, location.py, enrich.py  Persian data labels; city/country; derived details
vtiger/ss_schema.py           custom field definitions
vtiger/make_schema.py         dataset -> schema.json (picklists, groups, filters)
vtiger/setup_vtiger.php       runs in the container: fields, groups, filters
vtiger/import_to_vtiger.py    REST importer (resumable)
vtiger/tag_contacts.php       Level/Major tags on contacts
vtiger/apply_details.py, persianize_existing.py   SQL for derived details / Persian labels of an older import
vtiger/sheet_to_contacts.py, import_contacts.php  simple contact sheet -> Organizations + Contacts
vtiger/merge_duplicates.php   merge duplicate contacts (passport, phone)
i18n/                         fa_ir language pack, install_persian.php, translate_workflows.php (workflows/templates in the DB)
modules/PersianCalendar       Jalali calendar extension
modules/SamenTheme            custom skin + brand (login, installer, first-login pages, footer, print)
modules/SamenWidgets          reminder bell + dashboard widgets (Chart.js)
modules/SamenAcademy          help on every page + academy (tools/lessons/*.py -> lessons, tools/academy_shots.mjs)
modules/TelegramBot           Telegram Bot API messaging (build.sh, tools/install_telegram.php, tests/)
tools/server_env.sh, .mjs     read server.env for the shell / node tools
tools/direct_proxy.py         SSH ProxyCommand that bypasses the VPN
tools/get_session.sh, sync_theme.sh, ui_shot.mjs, ui_audit.mjs, ui_sweep.mjs, contact_sheet.py   UI development and checks
deploy/                       docker-compose, Dockerfile (php-apache + MariaDB + cron), install.sh, auto_install.sh,
                              post_install.sh, set_public_url.sh
docs_import/                  student documents -> contacts + attached documents (OCR, forms, lists; see above)
tests/                        data-pipeline tests, documents-import rules, deploy script checks
output/                       generated dataset, students.csv, report.txt, raw_archive.json (never in git)
```
Secrets: DB and admin passwords are generated on the server in `<instance>/.env` (mode 600); Telegram tokens live
only in the CRM database.
