#!/usr/bin/env bash
#
# Report the versions a job actually resolved, not the ones its matrix label
# implies. A matrix entry of "11.*" can resolve to 11.x-dev, and --prefer-lowest
# routinely resolves well above the declared floor, so the resolved set is the
# only trustworthy record of what was tested.
#
# Usage: report-resolved-versions.sh <job label> [declared framework floor]
#
# When a declared floor is supplied, the resolved framework version is compared
# against it and the relationship is reported exactly: above, equal or below.
# The comparison never fails the job; it exists so the gap is visible.
#
# Parsing is done with php rather than jq so this runs identically on the
# Windows legs of the test matrix, where only php and composer are guaranteed.

set -eu

label="${1:-resolved versions}"
declared_floor="${2:-}"

packages="laravel/framework illuminate/contracts orchestra/testbench orchestra/testbench-core pestphp/pest pestphp/pest-plugin-laravel nesbot/carbon"

# A failure here means the report is describing nothing. Say so loudly instead
# of rendering an empty table that reads like a clean result.
if ! installed_json="$(composer show --format=json --no-interaction 2>/dev/null)"; then
  printf '::error::%s could not read composer show --format=json; no resolved versions were recorded\n' "$label"
  {
    echo "### ${label}"
    echo
    echo '> **Could not read the installed package list.** `composer show --format=json` failed, so nothing below is verified.'
    echo
  } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
  exit 1
fi

if [ -z "$installed_json" ]; then
  printf '::error::%s got an empty package list from composer show\n' "$label"
  exit 1
fi

resolved_version() {
  printf '%s' "$installed_json" | php -r '
    $name = $argv[1];
    $data = json_decode(stream_get_contents(STDIN), true);
    if (! is_array($data)) {
        fwrite(STDERR, "unparseable composer show output\n");
        exit(2);
    }
    foreach ($data["installed"] ?? [] as $package) {
        if (($package["name"] ?? null) === $name) {
            echo $package["version"] ?? "";
            return;
        }
    }
  ' "$1"
}

php_version="$(php -r 'echo PHP_VERSION;')"
framework_version="$(resolved_version laravel/framework)"

notes=""

add_note() {
  notes="${notes}${1}"$'\n'
}

if [ -z "$framework_version" ]; then
  add_note 'laravel/framework is not installed directly; the package only declares illuminate/contracts.'
fi

# A dev branch is not a released version. The package sets minimum-stability:dev,
# so a major whose releases are all blocked (for example by a security advisory)
# can resolve to its dev branch while the job label still reads "11.*".
case "$framework_version" in
  *dev*)
    add_note "Resolved laravel/framework ${framework_version} is a DEV BRANCH, not a released version. This job does not demonstrate released-version compatibility."
    printf '::warning::%s resolved laravel/framework %s, a dev branch rather than a released version\n' "$label" "$framework_version"
    ;;
esac

if [ -n "$declared_floor" ] && [ -n "$framework_version" ]; then
  case "$framework_version" in
    *dev*)
      add_note "Declared floor ${declared_floor} was NOT exercised: the resolution is a dev branch."
      ;;
    *)
      normalized="${framework_version#v}"

      # Three distinct outcomes. Collapsing "below" into "matches" would report a
      # constraint violation as floor coverage.
      if php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$normalized" "$declared_floor"; then
        add_note "Declared floor ${declared_floor} was NOT exercised: the solver selected ${framework_version}, which is ABOVE it. Treat this job as lowest-installable coverage only."
        printf '::notice::%s resolved laravel/framework %s, above the declared floor %s\n' "$label" "$framework_version" "$declared_floor"
      elif php -r 'exit(version_compare($argv[1], $argv[2], "<") ? 0 : 1);' "$normalized" "$declared_floor"; then
        add_note "Resolved ${framework_version} is BELOW the declared floor ${declared_floor}. The installed version does not satisfy what this package claims to require."
        printf '::warning::%s resolved laravel/framework %s, BELOW the declared floor %s\n' "$label" "$framework_version" "$declared_floor"
      else
        add_note "Resolved ${framework_version} is exactly the declared floor ${declared_floor}."
      fi
      ;;
  esac
fi

{
  echo "### ${label}"
  echo
  echo "| Package | Resolved |"
  echo "| --- | --- |"
  echo "| php | ${php_version} |"
  for package in $packages; do
    version="$(resolved_version "$package")"
    [ -n "$version" ] && echo "| ${package} | ${version} |"
  done
  echo

  if [ -n "$notes" ]; then
    printf '%s' "$notes" | while IFS= read -r note; do
      [ -n "$note" ] && printf '> %s\n\n' "$note"
    done
  fi
} >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"

# Keep the full set in the log too, so a failing run is diagnosable from the
# raw output alone without expanding the summary.
composer show --direct --no-interaction
