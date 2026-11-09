#!/usr/bin/env bash
# Build the vtlib package dist/PersianCalendar.zip (regenerates the RTL css first).
# Refresh tools/source_css/ from the server when Vtiger is upgraded (see README).
set -euo pipefail
cd "$(dirname "$0")"
R=layouts/v7/modules/PersianCalendar/resources
python3 tools/make_rtl_css.py tools/source_css/layouts_v7_lib_todc_css_bootstrap.min.css \
  tools/source_css/layouts_v7_lib_todc_css_todc-bootstrap.min.css tools/source_css/layouts_v7_skins_marketing_style.css > $R/rtl.generated.css
node tests/convert.test.js
mkdir -p dist && rm -f dist/PersianCalendar.zip
zip -qr dist/PersianCalendar.zip manifest.xml modules languages layouts
unzip -l dist/PersianCalendar.zip | tail -1
