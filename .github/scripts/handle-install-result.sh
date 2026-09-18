#!/usr/bin/env bash
#
# Decide what a captured composer install result means for the job, and record
# it for the run summary.
#
# Usage: handle-install-result.sh <label> <status key> <log file> <exit file>
#
# Three outcomes:
#   success            - install worked; the job carries on and its tests decide.
#   advisory-blocked   - every solver problem was a security advisory. The
#                        resolution is blocked by composer default advisory
#                        policy, so CI has nothing to test against: recorded as
#                        COVERAGE UNAVAILABLE and the job does NOT fail.
#   unexpected-failure - anything else. The job fails.
#
# Nothing here weakens security policy: an advisory-blocked run is reported as
# untested, never as passed.

set -eu

label="$1"
status_key="$2"
log_file="$3"
exit_file="$4"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

exit_code="$(cat "$exit_file" 2>/dev/null || echo 1)"

set +e
classification="$(php "${script_dir}/classify-install-failure.php" "$log_file" "$exit_code" 2>/tmp/classify-reason)"
classifier_exit=$?
set -e

reason="$(cat /tmp/classify-reason 2>/dev/null || echo 'no reason captured')"

if [ "$classifier_exit" -ne 0 ]; then
  echo "::error::${label}: the install classifier itself failed (exit ${classifier_exit}); treating as a real failure"
  classification='unexpected-failure'
  reason="classifier error: ${reason}"
fi

echo "classification: ${classification}"
echo "reason: ${reason}"

status_dir="${RUNNER_TEMP:-/tmp}/coverage-status"
mkdir -p "$status_dir"

write_status() {
  php -r '
    $path = $argv[1];
    file_put_contents($path, json_encode([
        "key" => $argv[2],
        "label" => $argv[3],
        "status" => $argv[4],
        "classification" => $argv[5],
        "composer_exit" => (int) $argv[6],
        "reason" => $argv[7],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
  ' "${status_dir}/${status_key}.json" "$status_key" "$label" "$1" "$classification" "$exit_code" "$reason"
}

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "status=${classification}" >> "$GITHUB_OUTPUT"
fi

case "$classification" in
  success)
    # Not "tested" yet - the tests still have to run. The job's final step
    # promotes this once they pass.
    write_status 'install-ok'
    ;;

  advisory-blocked)
    write_status 'coverage-unavailable'
    {
      echo "### ${label}"
      echo
      echo "> **COVERAGE UNAVAILABLE — not tested.** Every release matching this job's constraint is currently blocked by a security advisory, so composer could not install anything to test against."
      echo "> This is non-blocking because this CI resolution is blocked by composer default advisory policy, so there is nothing to test against. It is a gap in what CI can cover, not a result about this package. A lockfile-pinned install, or one configured with a different advisory policy, may still resolve these releases."
      echo "> It is explicitly **not** a pass. Nothing was exercised."
      echo
      echo "> Classifier: \`${reason}\`"
      echo
    } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
    echo "::warning::${label}: COVERAGE UNAVAILABLE - all candidate releases are blocked by security advisories; nothing was tested"
    ;;

  *)
    write_status 'failed'
    {
      echo "### ${label}"
      echo
      echo "> **Install failed — blocking.** composer exited ${exit_code} and the failure was not positively identified as a security-advisory block."
      echo "> Classifier: \`${reason}\`"
      echo "> Read the install step log. A solver conflict, a missing extension, a network error or a broken script all land here, and all of them are real failures."
      echo
    } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
    echo "::error::${label}: install failed and was not an advisory block (${reason})"
    exit 1
    ;;
esac
