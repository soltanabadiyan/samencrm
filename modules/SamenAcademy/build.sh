#!/usr/bin/env bash
# Build dist/SamenAcademy.zip (vtlib extension package). Screenshots: tools/academy_shots.mjs (see README).
set -euo pipefail
cd "$(dirname "$0")"
python3 -c "import json,glob; [json.load(open(f)) for f in glob.glob('layouts/v7/modules/SamenAcademy/resources/**/*.json', recursive=True)]" && echo "json ok"
mkdir -p dist && rm -f dist/SamenAcademy.zip
zip -qr dist/SamenAcademy.zip manifest.xml modules languages layouts
unzip -l dist/SamenAcademy.zip | tail -1
