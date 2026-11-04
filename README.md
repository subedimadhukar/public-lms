# Public LMS

A block-native learning management system for WordPress: courses, lessons, quizzes, assignments, gradebook,
drip, prerequisites and certificates, with native Stripe, PayPal and offline checkout, all in the free plugin.
Pro (a separate plugin) adds what schools, marketplaces and businesses need at scale.

Status: **phase 1 internal alpha** (0.2.0). A course can be built in the admin, enrolled in for free and taken
lesson by lesson with progress saved: repositories with object caching, the `publiclms/v1` REST API, the
"Public LMS" admin app (DataViews lists and a drag-and-drop course builder), eight dynamic blocks with an
Interactivity API lesson player, the `publiclms/course-field` bindings source, plugin templates and patterns
(with a classic theme fallback), and `wp publiclms seed`. Quizzes, assignments, checkout and certificates
arrive in phase 2.

## Try the alpha

```bash
composer install && npm install && npm run build
npm run env:start
npx wp-env run cli wp publiclms seed --courses=10
```

Then open the course catalogue at `/courses/`, the admin app under **Public LMS**, and log in as a seeded
learner (`sita.gurung`) or instructor (`anita.shrestha`) with the seed password (`public-lms-demo` unless you
set `PUBLICLMS_SEED_PASSWORD` or `--password`). `wp publiclms reset --yes` removes everything the seed made.

- Product requirements: [docs/PRD.md](docs/PRD.md)
- Architecture and decision records: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Plugin and theme contracts: [docs/CONTRACTS.md](docs/CONTRACTS.md)
- Standards, branches and releases: [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md)

Requires WordPress 6.7+ and PHP 7.4+. Licensed GPL-2.0-or-later. © WPInterface, https://wpinterface.com
