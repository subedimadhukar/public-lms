# Public LMS product requirements

**Status:** draft for sign-off at the phase 0 gate · **Owner:** WPInterface product owner · **Date:** 2026-11-04
**Source:** WPInterface strategy document, "Product strategy and monetization" and "LMS plugin architecture and
complete ecosystem". Decisions taken since the document are applied here and listed in §8.

## 1. Problem and position

The leading WordPress LMS plugins (Tutor LMS, LearnPress, LearnDash, LifterLMS, Masteriyo, MasterStudy)
paywall at least three of assignments, gradebook, drip, prerequisites and certificates; each renders its own
React or shortcode shell inside the theme; three had multiple CVEs in 2026; none ships real block-theme
templates. LearnDash is now only sold as a $350 bundle after three reprices in October 2026.

Public LMS takes the open position: **a block-native LMS that gives the whole teaching stack away and charges
for commerce and scale**, paired with the CampusInterface block theme.

The promise, in one sentence a buyer can repeat: **everything a teacher needs to teach is free; what a school,
marketplace or business needs to run at scale is Pro.**

## 2. Users

| Persona | Needs | Tier |
|---|---|---|
| Solo teacher or coach | Build a course, sell it with Stripe or PayPal, track learners, issue certificates | Free |
| Training company or tutoring centre | Several instructors, cohorts, live classes, reports by course and learner | Pro |
| School or college | Groups and seats, gradebook, bulk enrolment, many courses | Pro |
| Agency building LMS sites | Composable roles, white label, staging licences, a theme that restyles everything | Pro Agency |
| Business doing staff training | Groups with leaders, LTI or SCORM, compliance reports | Pro plus add-ons |

## 3. Feature map by domain and tier

Copied from the strategy document's "The ecosystem, by domain and tier" table, with the approved changes in
§8 applied (gradebook moved to Free; REST namespace renamed).

| Domain | Free | Pro | Add-on |
|---|---|---|---|
| Course authoring | Course, sections, lessons in the block editor; video (YouTube, Vimeo, self-hosted, Bunny); attachments; drip by days or date; prerequisites; course cloning; CSV import and export | Course bundles; co-instructors; AI course outline and quiz draft; content gating by membership | |
| Assessment | Quiz builder with 6 question types (single, multiple, true/false, fill blank, short answer, matching); question bank; timer, attempts, randomisation, pass mark; assignments with file upload; **gradebook** | Essay and image questions with manual grading; weighted grades; H5P activities | SCORM 1.2 and 2004 player; xAPI statements to an LRS |
| Learners | Free, paid and manual enrolment; student dashboard block; per-lesson progress; one block-built certificate template rendered to PDF; course Q&A; reviews; announcements; email notifications for the core events | Multiple certificate templates with public verification page; notes and bookmarks; drip and expiry reminders; PWA with offline lesson text | |
| Instructors | Instructor role and profile; instructor registration with approval | Multi-instructor marketplace; front-end course builder; earnings, commission and withdrawal | |
| Commerce | Native checkout with Offline, Stripe and PayPal; coupons; order list; WooCommerce bridge | Subscriptions and memberships with access plans; invoices and tax fields; Paddle and Lemon Squeezy; abandoned-cart email | Nepal and South Asia pack: eSewa, Khalti, Razorpay, Paystack |
| Groups and B2B | | Groups with seats, group leaders, bulk CSV enrolment, group reports | LTI 1.3 tool provider |
| Live and community | Public course comments | Zoom, Google Meet and Jitsi lessons; cohorts with start dates | |
| Reporting | Course and student progress reports; quiz results | Analytics dashboard with enrolments, completions, revenue and scores over time; cohort view; CSV export in the background | |
| Engagement | Hooks for GamiPress and myCred | Points, badges and leaderboards; Mailchimp, FluentCRM, Kit and Brevo sync; webhooks with HMAC signatures for Zapier and Make | |
| Platform | REST API `publiclms/v1`; 15 or more blocks; plugin-registered templates; block bindings source; WP-CLI; multisite; RTL; translation ready; GDPR export and erase | White label; priority support; staging licences | |

Free tier rule for future features: if a solo teacher needs it to teach one course well, it is free. If it
exists because there are many instructors, many learners in groups, recurring revenue, or another system to
integrate with, it is Pro or an add-on.

## 4. Packaging and pricing

| Tier | Price per year | Sites | Lifetime | Includes |
|---|---|---|---|---|
| Free | $0 | unlimited | | Everything in the Free column |
| Pro Starter | $99 | 1 | $249 | Every Pro feature |
| Pro | $199 | 5 | $499 | Same; staging sites not counted |
| Agency | $399 | 25 | $999 | Same, plus white label and priority support |
| Add-ons | $49 to $99 | per licence | | Nepal and South Asia payments; SCORM and xAPI; LTI 1.3 |

All Pro features are in every tier; tiers differ by site count. Renewals at 20 percent off; 14-day refund;
no intro price that doubles. Education bundle (Pro Starter plus CampusInterface Pro) at $129 per year.
Sold on the WPInterface store: EDD Software Licensing for keys and updates, Paddle as merchant of record.
Pro is a separate GPL plugin that hooks into free.

## 5. Non-goals

- **No locked code in free.** No upsell nags on the front end, no crippled free features, no "is licensed"
  branches (directory rule and ADR-014).
- **No page-builder widgets.** No Elementor, Divi or WPBakery integrations; the front end is blocks.
- **No jQuery and no React on the front end.** Visitors get server-rendered HTML and the Interactivity API.
- **No bundled theme dependency.** The plugin must work on Twenty Twenty-Five, Ipsum, Ollie and Kadence;
  CampusInterface is recommended, never required.
- **No video hosting or streaming.** Lessons embed YouTube, Vimeo, Bunny or self-hosted files.
- **No card data on the site.** Hosted or redirect payment flows only.
- **No SCORM, xAPI or LTI in core.** These are paid add-ons for the few sites that need them.
- **No mobile apps** before 2.0; the REST API and PWA (Pro) cover mobile.
- **No ThemeForest or Envato distribution.**
- **No phoning home** from the free plugin: no telemetry, no licence checks, no remote assets.

## 6. Quality bar

- PHP 7.4 to 8.4 and WordPress 6.7 to 7.2; tested up to the current stable (7.1.4 today).
- WPCS clean, PHPStan level 6 clean, PHPUnit and Playwright green in CI on every pull request.
- WCAG 2.2 AA for every plugin block and template; keyboard-only learner journey.
- Core Web Vitals on the demo course archive and single course: LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1.
- 10,000-student benchmark and an external security review before 1.0 is promoted.
- Every string translatable (`public-lms`); RTL checked; GDPR export and erase for all learner tables.

## 7. Phase 1 scope (weeks 7 to 17)

Goal: a course can be built, sold for free and taken, end to end, on the CampusInterface scaffold theme.

**Status (2026-11-04): plugin side done to the internal alpha (0.2.0).** ✓ marks what shipped; notes say
where it differs from the plan. The gate's "10 real courses imported from a competitor export" is met with
`wp publiclms seed --courses=10` (ten realistic courses); importers stay in phase 2.

**Plugin**
- ✓ Post types, taxonomies, tables and migrations, capabilities and roles (scaffolded in phase 0).
- ✓ Repository implementations for enrolments and progress, with object caching; plus course structure
  (ADR-028) and the event log.
- ✓ REST for courses (curriculum), sections, lessons, enrolments and progress, per the matrix in
  `ARCHITECTURE.md` §4; plus students and settings.
- ✓ Admin app shell with DataViews lists; course builder v1 (sections and lessons, drag and drop with
  keyboard support).
- ✓ Lesson player with mark complete and next lesson (Interactivity API); free enrolment; student
  dashboard v1 (tabs: in progress, completed).
- ✓ Course grid as the `publiclms/course-query` Query Loop variation (inserter title "Course Grid").
- ✓ Plugin-registered templates and patterns per `CONTRACTS.md` (four templates, twelve patterns; contract
  1.1.0); bindings source `publiclms/course-field`; classic theme fallback (planned for phase 2, done now).
- ✓ AccessPolicy: enrolment and preview rules; drip and prerequisites are documented no-ops.
- ✓ Object cache for structure and progress. Action Scheduler is bundled through Composer; nothing in phase 1
  schedules jobs yet.
- Moved to phase 2: the `publiclms/video` block with completion tracking.

**Theme (CampusInterface)**
- Generator scaffold modernised to theme.json v3, tokens, fonts, 8 templates, header and footer parts
  (done in phase 0), growing to 20 patterns and 2 colour and 2 type variations, plus the course, lesson and
  dashboard template overrides once the plugin's templates land.

**Quality**
- ✓ PHPUnit on repositories, the access policy, REST permissions, block rendering, templates (92 tests).
- ✓ Playwright on build, enrol, complete a lesson, see progress on the dashboard (one journey, plus keyboard
  checks of the dashboard tabs); a timing check on the lesson page (`bin/timing-check.sh`).
- ✓ PHPCS and PHPStan level 6 clean; ESLint and Stylelint clean. Theme Check belongs to the theme session.
- Matrix: CI runs PHPUnit on WordPress 6.7 and latest with PHP 7.4 and 8.3, and Playwright on latest.
  Locally verified on WordPress 7.1.4 with PHP 8.x; 6.7 and PHP 7.4 rely on CI.

**Gate:** internal alpha with 10 real courses imported from a competitor export; a learner can enrol and
complete; the theme runs the alpha with no plugin CSS overrides. Plugin side met (see status above); the
theme half is the theme session's.

**Out of phase 1:** quizzes, assignments, checkout, certificates, reports, email, importers (phase 2).

## 8. Decisions applied since the strategy document

| Decision | Effect on this PRD |
|---|---|
| Name is "Public LMS" (slug `public-lms`), not CourseInterface | Prefixes per ADR-018 |
| Pedagogy free, commerce and scale paid | Gradebook moved to Free (ADR-019) |
| Pricing $99 / $199 / $399 for 1 / 5 / 25 sites | §4 |
| EDD Software Licensing behind `LicenseProvider`, Paddle checkout | ADR-015 |
| Theme Pro is a companion plugin with a pattern cloud shared across the theme family | Out of this PRD; see the theme's docs |
| Solo owner with Claude Code implementing; no hires | Phase durations to be re-planned before the start date |
| Template slugs follow the post type keys (`single-plms_course`, `plms-dashboard`), not the brief's `single-course` | ADR-023; no guard code in themes |

## 9. Open questions for the owner

1. Instructors publish their own courses without review in free (ADR-025). Keep, or default to review?
2. Name clearance: the wordpress.org slug `public-lms` and the domain are being checked in another session.
3. Start date for phase 1, once phase durations are re-planned for the solo model.
