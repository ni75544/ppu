#!/usr/bin/env bash
# Offline PHP lint for the corrected recovery candidate. Container-run (php 8.4).
set +u
cd /var/www/html/modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24a-recovery/corrected/candidate || exit 2
status=0
while IFS= read -r f; do
  out=$(php -l "$f" 2>&1)
  echo "$out"
  case "$out" in
    "No syntax errors detected"*) ;;
    *) status=1 ;;
  esac
done < <(find . \( -name '*.php' -o -name '*.install' \) | sort)
echo "LINT_STATUS=$status"
exit "$status"
