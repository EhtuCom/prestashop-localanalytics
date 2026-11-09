#!/usr/bin/env bash
# Builds localanalytics.zip (the file to upload in Modules > Module Manager > Upload a module).
set -euo pipefail
cd "$(dirname "$0")"
rm -f localanalytics.zip
zip -qr localanalytics.zip localanalytics -x '*/config*.xml' '*.DS_Store'
echo "localanalytics.zip: $(unzip -l localanalytics.zip | tail -1)"
