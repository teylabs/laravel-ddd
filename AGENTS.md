# Working on Laravel-DDD

Laravel-DDD (`tey/laravel-ddd`) is a Laravel package (PHP 8.3+, Laravel 12 and 13), rebuilt on `tey/mod` for 4.0. Read `README.md`, `UPGRADING.md` and the issue you're working on before changing code. This checkout is a package, not an application: it has no root `artisan`, frontend build or application database to set up.

## Gates

Install development dependencies with `composer install`, then run these before opening or updating a pull request:

- `composer test`: the full Pest suite through Orchestra Testbench.
- `composer analyse`: PHPStan for `src`, `config` and `database`, using `phpstan.neon.dist` and its baseline.
- `vendor/bin/pint --test`: check PHP style without rewriting files. Both `composer format` and `composer lint` apply Pint fixes; `lint` is not a check-only script.
- `php .github/scripts/check-llms-links.php`: check every `llms.txt` file target against the checkout, without network requests.

Composer enforces any additional PHP and extension requirements of the selected development dependencies. Install the SQLite PHP extension and the `sqlite3` CLI on your PATH for migration/schema tests; no database server is needed. Tests generate files in isolated application fixtures; keep writes out of the installed Testbench skeleton and other files under `vendor`.

CI runs the Ubuntu/Windows matrix (PHP 8.3 and 8.4, Laravel 12 and 13, lowest/stable dependencies), plus the docs link check. The push workflows run PHPStan on PHP/configuration/dependency changes and auto-fix Pint style on PHP changes, so check the final head after any style commit. Compatibility CI runs on its schedule, manual dispatch and changes to its workflow/scripts; it distinguishes tested versions from coverage unavailable because of Composer advisories. Its next-major dev canary is non-blocking. Every applicable check must be green on the PR's final commit; unavailable coverage is not a passing test.

## Compatibility contract

**4.0 keeps 3.x behaviour unless `UPGRADING.md` says otherwise.** Preserve `ddd:*` commands, configured folders and namespaces, `config/ddd.php`, published stubs, generator hooks and discovery behaviour. Internal use of Mod is not permission to expose `mod:*` commands or replace the application's configuration. Moving an application to plain Mod is an explicit, optional upgrade path.

For an intentional user-visible difference, document it in `UPGRADING.md` and test it. Do not silently adopt Mod defaults where Laravel-DDD has an existing contract. Use the installed source and a release tag's documentation when investigating published versions; `main` may contain unreleased changes.

## Conventions

- Follow the surrounding PHP code: naming, structure, types and comment density. Reuse Laravel generators and Mod's public APIs rather than duplicating their internals.
- Keep package code under `Tey\LaravelDDD` in `src`, defaults in `config/ddd.php`, package stubs in `stubs`, and regressions in the existing Pest suites under `tests`.
- Test changed observable behaviour with inputs that fail without the change. Preserve the test harness's isolation and existing compatibility assertions; do not weaken checks or the PHPStan baseline to manufacture a pass.
- Add notable changes under `## Unreleased` in `CHANGELOG.md` (`Added`, `Changed` or `Fixed`), ending with the issue number, e.g. `(#153)`. Create the heading if needed.
- Update the README and upgrade guide when their contracts change. Keep `resources/boost/` accurate when application-facing agent guidance changes, and keep `llms.txt` in sync with documentation paths.
- Prose uses Canadian spelling ("behaviour", "colour", "honour"; "-ize" is fine): plain, short sentences, no marketing. Preserve existing API identifiers and file names.

## Git and pull requests

- Branch from the latest remote base; never commit to `main`. During the 4.0 rebuild, stack 4.0 work on `origin/v4/rebuild-on-mod` (draft PR #152), not `main`, and rebase onto that branch before pushing. After 4.0 merges, use `origin/main` unless the issue specifies another base.
- Commit as `Jasper Tey <jasper@teylabs.com>`, with imperative, sentence-case subjects (e.g. "Add AGENTS.md and llms.txt").
- **No attribution or trailers:** no `Co-authored-by`, agent thread IDs, "Generated with" lines or agent-session links in commits, PR titles or PR bodies. Inspect `git log origin/main..HEAD --format=%B` before pushing.
- Open pull requests as **drafts**, targeting the branch the work is stacked on. Title them like a commit subject; the body explains the change, verification, any coverage gaps and `Closes #N`.
- Never merge, push to `main`, tag or release. Maintainers do that.
- This is a public repository: never include secrets, local absolute paths, private app names or internal notes.
