#!/usr/bin/env bash
# Build the vtlib package dist/PersianCalendar.zip (runs the conversion tests first).
set -euo pipefail
cd "$(dirname "$0")"
node tests/convert.test.js
mkdir -p dist && rm -f dist/PersianCalendar.zip
zip -qr dist/PersianCalendar.zip manifest.xml modules languages layouts
unzip -l dist/PersianCalendar.zip | tail -1
