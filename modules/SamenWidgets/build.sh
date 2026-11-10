#!/usr/bin/env bash
# Build dist/SamenWidgets.zip (vtlib extension package)
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p dist && rm -f dist/SamenWidgets.zip
zip -qr dist/SamenWidgets.zip manifest.xml modules languages layouts
unzip -l dist/SamenWidgets.zip | tail -1
