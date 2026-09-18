#!/usr/bin/env bash
#
# Promote a recorded coverage status from "install-ok" to "tested".
#
# This runs only after the job's tests have actually passed, so the summary can
# distinguish "we installed it" from "we exercised it". A job whose tests fail
# never reaches this step and keeps the weaker status, while the failing job
# itself turns the run red.
#
# Usage: mark-tested.sh <status key>

set -eu

status_key="$1"
status_file="${RUNNER_TEMP:-/tmp}/coverage-status/${status_key}.json"

if [ ! -f "$status_file" ]; then
  # The tests passed but no status was ever recorded, so the summary would have
  # nothing to promote and would report this leg as missing. That is a broken
  # workflow, not a coverage gap, and it must not pass quietly.
  echo "::error::No coverage status recorded for ${status_key}; the run cannot report what it covered"
  exit 1
fi

php -r '
  $path = $argv[1];
  $data = json_decode(file_get_contents($path), true);
  if (! is_array($data)) {
      fwrite(STDERR, "Unreadable coverage status at {$path}\n");
      exit(1);
  }
  $data["status"] = "tested";
  file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
' "$status_file"

echo "Recorded ${status_key} as tested."
