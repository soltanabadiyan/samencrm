# SamenStudent – Vtiger CRM rollout kit

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
| `SamenTheme.zip` (extension) | **Vtiger 8 custom skin `samen`** (`layouts/v7/custom_skins/samen`): Vtiger's own LESS recompiled with the samencloud.ir palette (teal `#13606F`, brown `#7D4F2E`, copper `#D99058`), design tokens, Shabnam font, complete RTL (mirrored automatically + hand fixes for the app menu/sub-menus and dropdowns). Brand login page, favicon and company logo. | `modules/SamenTheme/` |

* The login page preselects the `samen` skin (Vtiger keeps the choice in the session). Vtiger has no hook for the login
  page, so the installer adds one marked block to `layouts/v7/modules/Users/Login.tpl`; it is re-applied on update and removed
  when SamenTheme is disabled.
* Rebuild the skin after changing colours/CSS: `npm i -g less@4`, then `modules/SamenTheme/build.sh`
  (palette: `tools/source_less/samen/variables.less`; theme: `resources/theme.css`; RTL fixes: `resources/rtl.css`).
  After a Vtiger upgrade refresh `tools/source_less` and `tools/source_css` from the server and rebuild.
* `tools/sync_theme.sh` copies theme/calendar files straight to the server while developing;
  `tools/get_session.sh` + `tools/ui_shot.mjs` take headless screenshots (needs `ssh -D 19080 hashemz_crm`).
* Data labels are Persian too (groups `مقطع - …`, filters, tags, task/event subjects, list names) – see `data/fa_labels.py`.

## City / country  (`./run_all.sh locations`)
`data/location.py` fills the standard address fields from what the sheets contain – nothing is guessed:
* **Mailing (home)**: country from the nationality column, else the phone country code (+964 Iraq, +93 Afghanistan, …;
  Iranian +98 numbers are ignored), else the visa city; city = visa city (only 18 students have one).
  "Country Source" (منبع تعیین کشور) says which rule was used. Result: 3,402 Iraq, 193 Afghanistan, 2 other, 2,398 unknown
  (no phone or only an Iranian number).
* **Other (study)**: city of the university + ایران (4,204 students; the rest have no university in the sheets).
Only empty fields are filled, so manual edits in the CRM are kept.

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
data/fa_labels.py, location.py Persian data labels; city/country rules
i18n/                         fa_ir language pack + install_persian.php
modules/PersianCalendar       Jalali calendar extension
modules/SamenTheme            custom skin + brand (samencloud.ir)
tools/direct_proxy.py         SSH ProxyCommand that bypasses the VPN
deploy/                       docker-compose, Dockerfile (php-apache + MariaDB + cron), install.sh, auto_install.sh, post_install.sh
output/                       generated dataset, students.csv, report.txt, raw_archive.json
```
Secrets: DB passwords are generated on the server in `/opt/vtiger/.env` (mode 600).
