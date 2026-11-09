#!/usr/bin/env bash
# Build dist/SamenTheme.zip (vtlib extension package).
# Regenerates the custom skin when lessc 4 is available (npm i -g less@4, or LESSC=/path/to/lessc);
# otherwise the committed layouts/v7/custom_skins/samen/*/style.css are packaged as they are.
# After a Vtiger upgrade refresh tools/source_less and tools/source_css from the server (see README).
set -euo pipefail
cd "$(dirname "$0")"
if command -v "${LESSC:-lessc}" >/dev/null 2>&1; then python3 tools/build_skin.py; else echo "lessc not found – using committed skin css"; fi
mkdir -p dist && rm -f dist/SamenTheme.zip
zip -qr dist/SamenTheme.zip manifest.xml modules languages layouts
unzip -l dist/SamenTheme.zip | tail -1
