# Public LMS

A block-native learning management system for WordPress: courses, lessons, quizzes, assignments, gradebook,
drip, prerequisites and certificates, with native Stripe, PayPal and offline checkout, all in the free plugin.
Pro (a separate plugin) adds what schools, marketplaces and businesses need at scale.

Status: **phase 2, part A: the teaching stack** (0.3.0). On top of the phase 1 alpha (course builder, free
enrolment, lesson player, student dashboard, templates and patterns), the free teaching stack is in: drip by
days or date and prerequisites, a lesson video block (YouTube, Vimeo, Bunny Stream, self-hosted) that completes
the lesson once watched, lesson files, quizzes with six question types from a reusable bank graded on the
server, assignments with private file uploads and grading, a gradebook with CSV export, a block-built
certificate rendered to PDF, and personal data export and erasure. Community features, instructors, checkout,
importers and beta hardening are part B of phase 2.

## Try the alpha

```bash
composer install && npm install && npm run build
npm run env:start
npx wp-env run cli wp publiclms seed --courses=10
```

Then open the course catalogue at `/courses/`, the admin app under **Public LMS** (Courses, Questions,
Submissions, Gradebook, Certificate), and log in as a seeded learner (`sita.gurung`, who has finished a course
and has its certificate) or instructor (`anita.shrestha`) with the seed password (`public-lms-demo` unless you
set `PUBLICLMS_SEED_PASSWORD` or `--password`). Every seeded course has a quiz and an assignment.
`wp publiclms reset --yes` removes everything the seed made.

- Product requirements: [docs/PRD.md](docs/PRD.md)
- Architecture and decision records: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Plugin and theme contracts: [docs/CONTRACTS.md](docs/CONTRACTS.md)
- Standards, branches and releases: [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md)

Requires WordPress 6.7+ and PHP 7.4+. Licensed GPL-2.0-or-later. © WPInterface, https://wpinterface.com
