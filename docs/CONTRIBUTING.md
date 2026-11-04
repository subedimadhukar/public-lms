# Contributing to Public LMS

## Setup

Requirements: PHP 7.4+ (8.3 recommended for tooling), Composer 2, Node 24 (see `.nvmrc`), Docker for wp-env.

```bash
composer install
nvm use && npm install
npm run env:start          # wp-env on http://localhost:8888 (admin / password); maps ../../themes/campusinterface
npm run test:php           # PHPUnit inside wp-env
```

```bash
npm run build                                   # blocks, admin app, editor script, store module
npx wp-env run cli wp publiclms seed --courses=10   # sample courses, instructors and learners
npx wp-env run cli wp publiclms reset --yes         # remove everything the seed made
WP_BASE_URL=http://localhost:8889 npm run test:e2e  # Playwright against the wp-env tests site
bin/timing-check.sh http://localhost:8888/lessons/<slug>/  # time to first byte, median and slowest
```

Seeded users share the password `public-lms-demo` unless you pass `--password` or set
`PUBLICLMS_SEED_PASSWORD`. The e2e spec activates the plugin and Twenty Twenty-Five on the tests site and
creates its own users. Blocks must be built before `npm run test:php`; the block render tests skip
otherwise.

In CI, or without the sibling theme checkout, create `.wp-env.override.json` with `{ "themes": [] }`.
The override file is gitignored; use it locally to pin `core` or `phpVersion` too, for example
`{ "core": "https://wordpress.org/wordpress-6.7.zip", "phpVersion": "7.4" }` to test the floor.

`@wordpress/env` 12 still starts a separate tests environment by default but warns that this is deprecated.
`npm run test:php` relies on its `tests-cli` container; when wp-env drops it, move the tests config to its own
file passed with `--config`.

## Coding standards

| Area | Rule | Check |
|---|---|---|
| PHP style | WordPress Coding Standards 3.4 (`WordPress-Extra`, `WordPress-Docs`) | `composer lint`, auto-fix `composer lint:fix` |
| PHP floor | PHP 7.4 syntax only: no enums, `readonly`, `match`, union or `mixed` types, constructor promotion, named arguments, nullsafe operator, `str_contains()` and friends | PHPCompatibilityWP in `composer lint`; CI runs tests on 7.4 |
| Static analysis | PHPStan level 6 with WordPress stubs; no baseline | `composer analyse` |
| JS and CSS | `@wordpress/scripts` ESLint and Stylelint configs | `npm run lint:js`, `npm run lint:css` |
| Files | PSR-4 under `includes/` (`PublicLMS\Foo\Bar` → `includes/Foo/Bar.php`); one class per file | PHPCS |
| Prefixes | Functions, options, hooks, capabilities: `publiclms_`. Post types, taxonomies, meta keys (`_plms_`), tables: `plms_` (post type keys ≤ 20 characters). Constants: `PUBLICLMS_`. Blocks: `publiclms/`. REST: `publiclms/v1`. | PHPCS `PrefixAllGlobals` |
| Security | Sanitise on input, validate in REST args, escape late on output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). `$wpdb->prepare()` for every query, `%i` for identifiers. Capability or `AccessPolicy` check in every REST `permission_callback`. | PHPCS `WordPress.Security`, `WordPress.DB` |
| Data access | Only repositories touch `plms_*` tables. Datetimes in UTC. | Review |
| i18n | Every user-facing string translatable with the `public-lms` text domain; no `__()` before `init` | PHPCS `WordPress.WP.I18n`; `wp i18n make-pot` in release |
| Front end | No jQuery. Interactivity API for interaction. Blocks are dynamic with block supports and `"role": "content"` on editable attributes. | Review |
| Accessibility | WCAG 2.2 AA; keyboard path for every learner action | axe in Playwright (phase 1) |
| Tests | New repository, service or policy code ships with PHPUnit tests; REST routes with permission tests | CI |

Every `phpcs:ignore` names the sniff and gives a reason on the same line.

## Branches and commits

- `main` is always releasable; CI must be green to merge. No direct pushes.
- Work on short-lived branches: `feat/<topic>`, `fix/<topic>`, `chore/<topic>`, `docs/<topic>`.
- Conventional Commits: `feat:`, `fix:`, `perf:`, `refactor:`, `test:`, `docs:`, `build:`, `ci:`, `chore:`.
  `feat!:` or a `BREAKING CHANGE:` footer for anything that breaks a public hook, REST route or contract.
- Squash-merge pull requests; the PR title becomes the commit message.
- A change to `docs/CONTRACTS.md` is a pull request on both this repository and the theme, linked to each
  other, and bumps the contract version.

## Releases

**Cadence.** A minor release every four weeks from `main` (the release train). Patch releases for security and
regressions any time. The theme follows within a week when a contract changed, otherwise on its own cadence.

**Versioning.** Semantic versioning. Pre-1.0, minors may change internal APIs; public hooks, REST routes and
the contracts follow the contract change rule regardless.

**Checklist** (release branch `release/x.y.z`, cut one week before the train date):

1. Freeze: only fixes land on the release branch.
2. Bump the version in `public-lms.php` (header and `PUBLICLMS_VERSION`), `package.json` and `readme.txt`
   (`Stable tag`); bump `Installer::DB_VERSION` if the schema changed.
3. Update `Tested up to`, the changelog and the upgrade notice in `readme.txt`.
4. Regenerate the POT file: `wp i18n make-pot . languages/public-lms.pot --slug=public-lms --domain=public-lms --exclude=node_modules,vendor,build,tests`.
5. CI green, including the upgrade test from the previous release.
6. Tag `vx.y.z` on `main` after merging; the build workflow attaches `public-lms.zip`.
7. Deploy to wordpress.org SVN (from phase 2, once the plugin is approved) and publish release notes.
8. Record the CampusInterface version the release was tested with in the release notes.

## Definition of done

Code reviewed, CI green, docs and changelog updated, strings translatable, accessibility checked for UI,
and no new PHPStan or PHPCS ignores without a reason.
