# Frozen consumer comparison

`FrozenComparisonTest.php` compares the working package with source from commit
`5710dea91808f4b786a89892e7206d38d7045901`, after the controller/request and config
preservation fixes. This is a **bounded source baseline**, not a released-version
parity certificate or the final v4 release baseline. Later intentional behaviour
changes require an explicitly reviewed baseline update.

The test extracts that commit using `git archive` and runs the reference and
candidate serially in separate PHP processes. Both use the same installed vendor
dependencies and the same consumer driver and fixtures. A prepended production
loader selects the package implementation before package classes load; reflection
checks the provider, blueprint and autoloader paths. The baseline's source,
configuration and package stubs come from the pinned archive, not candidate code.
The temporary package loader is suspended while the fixture root remaps Composer;
guards check Composer's fixture paths and actual loaded fixture-class locations.
Nothing is downloaded, installed or rewritten in the reference checkout. Full git
history is fetched in matrix CI; a missing baseline object is a failure locally.

Covered observations:

- Class generation, nested controller/request generation and model/factory generation.
- Those commands' arguments/options, their exact exit codes and console output.
- Every file under the fixture's `src` directory, including generated PHP contents
  and pre-existing fixture files (so overwrites and deletions remain visible).
- Provider, Artisan command, listener and subscriber discovery inventories, in
  returned order, with nonempty known-member assertions on both captures.

Only the temporary application root in console output, CRLF line endings, and
filesystem separators in relative file keys are normalized. PHP namespace
backslashes, messages, whitespace, command options, inventory order and file
contents otherwise remain significant. File-map keys are sorted; inventory arrays
are not. The processes report their real package roots and matching PHP/Laravel
versions separately from compared observations.

An intentional mutation is also captured from a temporary copy of the candidate:
the class folder is changed to `UnexpectedClasses`. The test requires the changed
artifact and different observations, proving the comparison does not accidentally
run the reference twice. Neither the reference archive nor real checkout is edited.

This does not yet cover interactive prompts, every generator/option combination,
callback lifetimes, published custom stubs, actual runtime registrations, policy or
factory resolution, migrations, cache replay, configuration writes or Composer
utilities. It runs against each matrix job's installed framework version; it does
not compare different dependency versions. Discovery inventories alone are not
proof of event execution, registration counts or cache compatibility.

Run serially like the rest of the suite:

```sh
vendor/bin/pest tests/Consumer/FrozenComparisonTest.php --no-coverage
```

Changing the pin is not an automatic snapshot update. Review the old/new consumer
observations and explain the separately approved behaviour change first. Extend the
driver with concrete consumer contracts as extraction work reaches them; keep the
reference implementation in its own process and never calculate expected paths
with the candidate resolver.

The subscriber compatibility correction retains the frozen source and adds an explicit expected delta for the two InvoiceEventSubscriber handlers that #118 excluded. Candidate inventories are not filtered or normalized: the comparison requires both restored entries at their exact positions and continues comparing every other observation unchanged.
