#!/usr/bin/env bash
# Build the vtlib package dist/TelegramBot.zip
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p dist && rm -f dist/TelegramBot.zip
zip -qr dist/TelegramBot.zip manifest.xml modules languages layouts cron
unzip -l dist/TelegramBot.zip | tail -1
