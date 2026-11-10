# SamenCRM — سامانه مدیریت ارتباط با مشتری

Vtiger CRM 8 for SamenStudent (admission & support of international students), Persian/RTL, Shamsi calendar, Samen theme.

Everything is driven from your PC with `run_all.sh`. Server: `212.33.206.241`, installed in `/opt/vtiger` (Docker).

## Status: deployed 2026-11-10 – Vtiger 8.4.0 at http://212.33.206.241:9081/
Login: `admin` / password stored on the server in `/opt/vtiger/.env` (`VT_ADMIN_PASSWORD`, root-only):
`ssh hashemz_crm 'grep VT_ADMIN /opt/vtiger/.env'`. Change it after the first login (My Preferences).

## Ports
* external **9080** = SSH (NAT to the server's sshd on 22; `ssh hashemz_crm` in `~/.ssh/config`)
* **9081** = Vtiger web
* **9082** = Adminer (DB UI, optional), bound to `127.0.0.1` only. Use `ssh -L 9082:127.0.0.1:9082 hashemz_crm` and
  `docker compose --profile tools up -d adminer`.

## VPN note
With the PC's full-tunnel VPN (tun2) on, the server is unreachable (it drops foreign IPs). `run_all.sh` detects this and
sends SSH out of the LAN interface through `tools/direct_proxy.py` (SO_BINDTODEVICE). Routes are not changed.
For a manual shell: `ssh -o ProxyCommand='python3 tools/direct_proxy.py %h %p wlp5s0' hashemz_crm`.
The browser behind the VPN can't open :9081 either; open it with the VPN off, or add 212.33.206.241 to the VPN's bypass list.

## Server-side fixes baked in
* `deb.debian.org` is blocked from the server, so the image uses `http://mirror.iranserver.com` (`DEBIAN_MIRROR` build arg).
* Base image is `php:8.2-apache-bookworm` (trixie has no `libc-client-dev`, which the IMAP extension needs).
* The web installer is scripted (`deploy/scripts/auto_install.sh`), so no browser wizard is needed. Currency is IRR and the timezone Asia/Tehran.
* An older, never-run attempt that was in `/opt/vtiger` was moved to `/opt/vtiger.old-attempt-20261109` (safe to delete).

## Steps
```bash
cd /home/morteza/Desktop/projesct/hashemz/codes
./run_all.sh build                      # 1. Excel -> output/ (check output/report.txt and students.csv)
./run_all.sh install                    # 2. docker stack on the server + automated web installer
./run_all.sh import --limit 20          # 3a. optional test import of 20 contacts
./run_all.sh import                     # 3b. full import (resumable; re-running skips what exists)
./run_all.sh telegram                   # Telegram module (see below)
```
If you SSH as `morteza`: `SSH_TARGET=morteza@212.33.206.241` (needs passwordless sudo, or log in as root).
If the server cannot reach SourceForge, download `vtigercrm8.4.0.tar.gz` on your PC and run
`VTIGER_TARBALL=/path/vtigercrm8.4.0.tar.gz ./run_all.sh install`.
Server needs `python3` (for the importer).

## Persian UI, Shamsi calendar and the Samen theme  (`./run_all.sh persian`)
All three are standard Vtiger packages (Settings > Module Management can also import the zips by hand):

| package | what | source |
|---|---|---|
| `Persian_fa_ir.zip` (language) | full fa_ir pack: 4,047 strings + labels of the custom student fields/picklists; default language | `i18n/` (`fa/*.txt` = translations, `build_langpack.py` validates placeholders/HTML) |
| `PersianCalendar.zip` (extension) | Jalali dates everywhere in the UI, Jalali date picker on every date field, Shamsi FullCalendar (RTL, week starts Saturday). Dates stay Gregorian in the DB. | `modules/PersianCalendar/` (`tests/convert.test.js`) |
| `SamenTheme.zip` (extension) | **Vtiger 8 custom skin `samen`** (`layouts/v7/custom_skins/samen`): Vtiger's own LESS recompiled with the samencloud.ir palette (teal `#13606F`, brown `#7D4F2E`, copper `#D99058`), design tokens, Shabnam font, complete RTL (mirrored automatically + hand fixes for the app menu/sub-menus and dropdowns). Brand login page (SamenCRM), favicon, company logo, high-contrast navigation (app menu, sub-menus, rail, settings), responsive phone layout. | `modules/SamenTheme/` |

Both extensions have no records, so they are **not in the app menus**; each has an admin page in
**Settings > Extensions**: «پوسته SamenCRM» (status of skin / login page / logo, palette, *apply again*) and
«تقویم شمسی» (status, every user's language / week start / date format, *set all users to Persian*).
Old links such as `index.php?module=SamenTheme&view=List` redirect there.

* The login page preselects the `samen` skin (Vtiger keeps the choice in the session). Vtiger has no hook for the login
  page, so the installer adds one marked block to `layouts/v7/modules/Users/Login.tpl`; it is re-applied on update and removed
  when SamenTheme is disabled.
* Rebuild the skin after changing colours/CSS: `npm i -g less@4`, then `modules/SamenTheme/build.sh`
  (palette: `tools/source_less/samen/variables.less`; theme: `resources/theme.css`; RTL fixes: `resources/rtl.css`).
  After a Vtiger upgrade refresh `tools/source_less` and `tools/source_css` from the server and rebuild.
* `tools/sync_theme.sh` copies theme/calendar files straight to the server while developing;
  `tools/get_session.sh` + `tools/ui_shot.mjs` take headless screenshots (needs `ssh -D 19080 hashemz_crm`).
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
`tools/contact_sheet.py` turns the screenshots into review sheets. Needs `ssh -D 19080 hashemz_crm` and
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
`/opt/vtiger/backups/pre_telegram_*.sql.gz`, installs/updates the module and adds the host cron `/etc/cron.d/samencrm-telegram`;
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

## What gets imported
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
`./run_all.sh import` is resumable: whatever is in `/opt/vtiger/ss/import_state.json` is skipped. A DB backup is taken
before every run (`/opt/vtiger/backups`). Failures never stop the run; see `/opt/vtiger/ss/import_errors.jsonl`.

## Layout
```
run_all.sh                    orchestrator (PC)
data/build_dataset.py         Excel -> dataset (parsing, merge, normalisation, tasks/events)
vtiger/ss_schema.py           custom field definitions
vtiger/make_schema.py         dataset -> schema.json (picklists, groups, filters)
vtiger/setup_vtiger.php       runs in the container: fields, groups, filters
vtiger/import_to_vtiger.py    REST importer (resumable)
vtiger/tag_contacts.php       Level/Major tags on contacts
data/fa_labels.py, location.py, enrich.py  Persian data labels; city/country; derived details
i18n/                         fa_ir language pack + install_persian.php
modules/PersianCalendar       Jalali calendar extension
modules/SamenTheme            custom skin + brand (samencloud.ir)
modules/TelegramBot           Telegram Bot API messaging (build.sh, tools/install_telegram.php, tests/)
tools/direct_proxy.py         SSH ProxyCommand that bypasses the VPN
deploy/                       docker-compose, Dockerfile (php-apache + MariaDB + cron), install.sh, auto_install.sh, post_install.sh
output/                       generated dataset, students.csv, report.txt, raw_archive.json
```
Secrets: DB passwords are generated on the server in `/opt/vtiger/.env` (mode 600).
