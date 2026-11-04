# Public LMS architecture

Status: phase 0, frozen at the phase 0 gate. Source: the "LMS plugin architecture and complete ecosystem"
section of the WPInterface strategy document (4 Nov 2026). Where that document and the phase 0 brief
disagree, the brief wins; each such case is an ADR below marked **Deviation**.

## 1. Shape of the system

Public LMS is a standalone, block-native LMS. Content lives in custom post types, learner activity in custom
tables, the admin is one React app on WordPress's own components, and the front end is blocks plus the
Interactivity API with no jQuery. It runs on any block theme, PHP 7.4+, WordPress 6.7+.

The theme sits above the plugin, never below it: it overrides plugin-registered templates and styles plugin
blocks through block supports and tokens (see `CONTRACTS.md`). Every read or write from the front end or the
admin passes the REST layer and the single access policy before it reaches a post type or a table.

```
 Theme (any block theme; CampusInterface first)
   │ overrides templates by slug, styles blocks via theme.json tokens
 ──┼──────────────────────────────────────────────────────────────────
   ▼
 Templates ── Blocks (render.php + Interactivity API) ── Admin app (React, DataViews)
        │                 │                                    │
        └──── Bindings ───┴────────── REST publiclms/v1 ───────┘
                                  │  permission_callback → Capabilities (authoring)
                                  │                      → AccessPolicy (learning)
                                  ▼
                      Services (enrolment, progress, grading, checkout)
                                  │
                      Repositories (one per table group)
                                  │
                 Post types + taxonomies      Custom tables (plms_*)
                                  │
                      Jobs on Action Scheduler (emails, PDFs, drip, aggregates)
```

## 2. Layers

| Layer | Technology | Phase 0 state | Notes |
|---|---|---|---|
| Core | PSR-4 under `includes/`, `Core\Plugin`, `Core\Container`, Composer autoload with a fallback loader | Done | The container builds services lazily; Pro rebinds services on `publiclms_register_services`. |
| Post types and taxonomies | `PostTypes\PostTypes`, `Taxonomies\Taxonomies` | Done | All `show_in_rest`; capability mapping in ADR-025. |
| Capabilities | `Capabilities\Capabilities`, `Capabilities\Roles` | Done | Five primitive capabilities, four meta capabilities, three roles. |
| Data | `Core\Installer` (dbDelta schema), `Repositories\*` | Schema done; interfaces and base repository only | Repository implementations arrive in phase 1. |
| Access | `Access\AccessPolicy`, `Access\AccessDecision` | Signatures and docs; permissive stub | Rule order documented in the class. |
| REST | `Rest\AbstractController`, `Rest\Api`, `Rest\HealthController` | Namespace and health route | Controllers per resource in phase 1. |
| Templates | `Templates\TemplateRegistrar` | Slug list and hierarchy mapping; no template files | Files under `templates/` in phase 1. |
| Licensing | `Licensing\LicenseProvider`, `NullLicenseProvider`, `LicenseStatus` | Done | Pro binds the EDD provider. |
| Admin | One React app on `@wordpress/components`, `@wordpress/data` stores per domain, DataViews | Not started | Phase 1. |
| Front end | Dynamic blocks with block supports; Interactivity API for player, quiz runner, curriculum, dashboard | Not started | Phase 1 and 2. |
| Bindings | `publiclms/course-field` source in PHP and JS | Keys frozen in `CONTRACTS.md` A7 | Phase 1. |
| Payments | `Gateway` interface; hosted or redirect flows only | Not started | Phase 2. |
| Jobs | Action Scheduler ^4.1 via Composer | Loaded when installed | Emails, PDFs, drip, aggregates, renewals, imports. |
| Certificates | Block-built template rendered to PDF with Dompdf, prefixed with PHP-Scoper | Not started | Phase 2. |

### Folder layout

```
public-lms/
├─ public-lms.php  uninstall.php  readme.txt
├─ composer.json  package.json  phpcs.xml.dist  phpstan.neon  phpunit.xml.dist
├─ .wp-env.json  playground.json  .nvmrc  .editorconfig  .gitignore
├─ includes/   Core/ PostTypes/ Taxonomies/ Capabilities/ Repositories/ Rest/
│             Access/ Templates/ Licensing/
│             (phase 1+: Models/ Blocks/ Bindings/ Payments/ Emails/ Certificates/
│              Reports/ Jobs/ Admin/ Abilities/)
├─ src/        index.js (phase 1+: blocks/<name>/{block.json, edit.js, render.php, view.js},
│             admin/, store/, bindings/, interactivity/)
├─ patterns/   templates/      (phase 1: plugin-side defaults the theme mirrors 1:1)
├─ build/      (gitignored)    languages/   docs/
├─ tests/      phpunit/  phpstan/   (phase 1: e2e/)
└─ .github/workflows/  lint.yml  tests.yml  build.yml
```

### Hooks Pro and add-ons use

| Hook | Type | Purpose |
|---|---|---|
| `publiclms_register_services` | action (Container) | Rebind a service, e.g. `LicenseProvider` |
| `publiclms_loaded` | action (Plugin) | Everything is registered; add-ons start here |
| `publiclms_installed` | action (schema version) | After install or upgrade on a site |
| `publiclms_post_type_args`, `publiclms_taxonomy_args` | filters | Adjust registration |
| `publiclms_user_teaches_course` | filter | Co-instructors (Pro) |
| `publiclms_access_decision` | filter | Memberships and groups grant access (Pro) |
| `publiclms_rest_controllers` | filter | Add REST controllers |

The add-on registry (`publiclms()->addons()->register()`) lands in 1.1, as the roadmap says.

## 3. Data model

The rule (ADR-001): content that is authored, revised and templated is a post type; anything per learner, per
attempt or per order is a table with indexes.

### 3.1 Post types and taxonomies

| Key | Public URL | Capability profile | Purpose |
|---|---|---|---|
| `plms_course` | `/courses/{slug}/`, archive `/courses/` | content | The unit learners enrol in |
| `plms_section` | none | content | Groups items inside a course |
| `plms_lesson` | `/lessons/{slug}/` | content | Lesson content in the block editor |
| `plms_quiz` | `/quizzes/{slug}/` | content | Quiz settings and question list |
| `plms_question` | none | content | Question bank entry |
| `plms_assignment` | `/assignments/{slug}/` | content | Assignment brief |
| `plms_certificate` | none | manage | Block-built certificate template |
| `plms_membership` | none | orders | Access plan granting a set of courses |
| `plms_group` | none | manage | Learners enrolled together with seats |

Taxonomies, all on `plms_course`: `plms_course_category` (hierarchical, `/course-category/`),
`plms_course_tag` (flat, `/course-tag/`), `plms_level` (flat list with checkbox UI, `/course-level/`).
Relationships (lesson → section → course, quiz → course, question → quiz) are post meta with the `_plms_`
prefix, defined with the course builder in phase 1. Lessons, quizzes and assignments are excluded from
search so gated content never leaks through it.

### 3.2 Tables

All tables carry the site prefix (`wp_plms_enrollments`). Conventions: `BIGINT UNSIGNED` ids, datetimes in
UTC, money as `DECIMAL(19,4)` with an ISO 4217 currency code, JSON payloads in `LONGTEXT`, indexed `VARCHAR`
at most 191 characters (ADR-026).

| Table | Key columns | Indexes | Notes |
|---|---|---|---|
| `plms_enrollments` | user_id, course_id, status, source, source_id, group_id, enrolled_at, expires_at, completed_at | **UNIQUE (user_id, course_id)**; (course_id, status); group_id; expires_at | Hot path for every access check |
| `plms_progress` | user_id, course_id, item_id, item_type, status, started_at, completed_at | **UNIQUE (user_id, item_id)**; (user_id, course_id); (course_id, status) | Idempotent upsert per item |
| `plms_course_progress` | user_id, course_id, enrollment_id, items_completed, items_total, percent, last_item_id, completed_at | **UNIQUE (user_id, course_id)**; (course_id, completed_at) | Denormalised aggregate, stays hot |
| `plms_quiz_attempts` | user_id, quiz_id, course_id, attempt_number, status, score, max_score, percent, passed, question_ids, time_spent, started_at, submitted_at, graded_at, graded_by | (user_id, quiz_id); (quiz_id, status); (course_id, status); **submitted_at** | One row per attempt |
| `plms_quiz_answers` | attempt_id, question_id, answer, is_correct, points, max_points, feedback, graded_by | **UNIQUE (attempt_id, question_id)**; question_id | Graded on the server |
| `plms_orders` | order_key, user_id, status, currency, subtotal, discount_total, tax_total, total, gateway, gateway_order_id, coupon_code, billing_email | **UNIQUE order_key**; user_id; (status, created_at); gateway_order_id | Native checkout |
| `plms_order_items` | order_id, item_type, object_id, name, quantity, unit_price, subtotal, discount, tax, total | order_id; (item_type, object_id) | Course, membership or bundle lines |
| `plms_transactions` | order_id, gateway, type, status, amount, currency, gateway_transaction_id, payload | order_id; gateway_transaction_id; created_at | Payments, refunds, chargebacks; webhook audit |
| `plms_subscriptions` | user_id, order_id, plan_id, status, gateway, gateway_subscription_id, billing_period, billing_interval, amount, trial_ends_at, next_payment_at | user_id; (plan_id, status); (status, next_payment_at); gateway_subscription_id | Created by free, used by Pro |
| `plms_events` | event_type, user_id, course_id, object_type, object_id, payload | (event_type, created_at); user_id; (course_id, created_at) | Append-only; feeds reports, notices, webhooks |
| `plms_notifications` | user_id, event_id, channel, type, title, message, link, status, read_at, sent_at | (user_id, status); (channel, status); created_at | In-app and email queue |
| `plms_report_daily` | report_date, course_id (0 = site), enrollments, completions, active_learners, lessons_completed, quiz_attempts, quiz_passes, revenue, refunds | **UNIQUE (report_date, course_id)**; (course_id, report_date) | Dashboards read only aggregates |

Schema version: `Installer::DB_VERSION` (currently `1`), stored in option `publiclms_db_version`; the plugin
version in `publiclms_version`. `Installer::maybe_upgrade()` runs `dbDelta()` when either is out of date.

## 4. REST resources and permission matrix

Namespace `publiclms/v1` (ADR-006, ADR-018). Post types are also on `wp/v2` under their keys
(`/wp/v2/plms_course`). Collections use keyset pagination (`after`, `per_page` ≤ 100).

| Resource | Routes (phase) | Read | Write |
|---|---|---|---|
| `health` | `GET /health` (0) | anyone (status only); version and tables for `publiclms_manage_courses` | none |
| `courses/{id}/curriculum` | `GET`, `PUT` (1) | AccessPolicy `can_view_course` | `publiclms_edit_course` (meta) |
| `courses/{id}/enrollments` | `GET`, `POST`, `DELETE /{user_id}` (1) | `publiclms_view_course_reports` (meta) | `publiclms_enroll_student` (meta) |
| `enrollments/me` | `GET`, `POST` (free enrol) (1) | logged in | logged in + AccessPolicy `can_enroll` |
| `progress` | `GET /courses/{id}/progress/me`, `POST /items/{id}/complete` (1) | logged in + enrolled | logged in + AccessPolicy `can_access_item` |
| `quizzes/{id}/attempts` | `POST` start, `POST /{attempt}/answers`, `POST /{attempt}/submit` (2) | own attempts; others need `publiclms_view_course_reports` | AccessPolicy `can_attempt_quiz`; rate-limited |
| `assignments/{id}/submissions` | `GET`, `POST`, `PUT /{id}/grade` (2) | own; others need `publiclms_view_course_reports` | submit: `can_submit_assignment`; grade: `publiclms_edit_course` |
| `orders` | `GET`, `GET /{id}`, `POST /checkout`, `POST /{id}/refund` (2) | own orders; all need `publiclms_manage_orders` | checkout: logged in or guest with email; refund: `publiclms_manage_order` |
| `webhooks/{gateway}` | `POST` (2) | none | signature verified server-side; no cookie auth |
| `reports` | `GET /courses/{id}`, `GET /site` (2) | `publiclms_view_course_reports` (meta; site-wide needs `publiclms_manage_courses` too) | none |
| `certificates/{course_id}` | `GET` PDF (2) | AccessPolicy `can_view_certificate` | none |

Capability holders by default:

| Capability | Administrator | Course Manager (`plms_manager`) | Instructor (`plms_instructor`) | Student (`plms_student`) |
|---|---|---|---|---|
| `publiclms_manage_courses` | ✓ | ✓ | | |
| `publiclms_edit_own_course` | ✓ | ✓ | ✓ | |
| `publiclms_view_reports` | ✓ | ✓ | ✓ (own courses) | |
| `publiclms_manage_orders` | ✓ | ✓ | | |
| `publiclms_enroll_students` | ✓ | ✓ | ✓ (own courses) | |
| `read`, `upload_files` | ✓ | ✓ | ✓ | `read` only |

Meta capabilities (resolved in `Capabilities::map_meta_cap()`):

| Meta capability | Argument | Teaches the course | Otherwise |
|---|---|---|---|
| `publiclms_edit_course` | course ID | `publiclms_edit_own_course` | WordPress's `edit_post` mapping (needs `publiclms_manage_courses` for others' courses) |
| `publiclms_view_course_reports` | course ID or none | `publiclms_view_reports` | `publiclms_view_reports` + `publiclms_manage_courses` |
| `publiclms_enroll_student` | course ID | `publiclms_enroll_students` | `publiclms_enroll_students` + `publiclms_manage_courses` |
| `publiclms_manage_order` | order ID | n/a | `publiclms_manage_orders` |

"Teaches" means post author, or co-instructor through the `publiclms_user_teaches_course` filter (Pro).

## 5. Block list (draft, frozen at the gate)

All blocks are dynamic (`render.php`), declare block supports, and mark user-editable attributes with
`"role": "content"`. Interactivity API stores are named `publiclms/<block>`.

| Block | Key attributes | Interactive | Phase |
|---|---|---|---|
| `publiclms/curriculum` | `courseId`, `showLockedState`, `collapsible`, `headingLevel` | yes (accordion) | 1 |
| `publiclms/enroll-button` | `courseId`, `label` (content), `showPrice` | yes | 1 |
| `publiclms/course-progress` | `courseId`, `style` (bar, ring, text) | no | 1 |
| `publiclms/mark-complete` | `label` (content), `completedLabel` (content) | yes | 1 |
| `publiclms/lesson-navigation` | `showTitles`, `previousLabel` (content), `nextLabel` (content) | no | 1 |
| `publiclms/student-dashboard` | `tabs`, `defaultTab` | yes (tabs) | 1 |
| `publiclms/video` | `provider`, `src`, `trackCompletion`, `requiredPercent` | yes | 1 |
| `publiclms/quiz` | `quizId` | yes (runner) | 2 |
| `publiclms/assignment` | `assignmentId`, `allowedTypes`, `maxFileSize` | yes | 2 |
| `publiclms/instructor-dashboard` | `tabs` | yes | 2 |
| `publiclms/course-reviews` | `courseId`, `perPage`, `showForm` | yes | 2 |
| `publiclms/course-qa` | `courseId`, `perPage` | yes | 2 |
| `publiclms/announcements` | `courseId`, `perPage` | no | 2 |
| `publiclms/certificate-button` | `courseId`, `label` (content) | no | 2 |
| `publiclms/checkout` | `showCoupon`, `gateways` | yes | 2 |
| `publiclms/course-filters` | `taxonomies`, `showSearch` | yes (filters a `publiclms/course-query`) | 2 |
| `publiclms/login-form` | `redirect`, `showRegister` | no | 2 |
| `publiclms/instructor-card` | `userId`, `showBio`, `showCourses` | no | 2 |

Plus the Query Loop variation `publiclms/course-query` and the bindings source `publiclms/course-field`.

## 6. Security, caching and scale

Every REST route has a permission callback and typed arguments with sanitise and validate callbacks;
state-changing front-end actions carry the REST nonce in Interactivity state. Lesson HTML passes through
`wp_kses_post`. Uploads (assignments) are MIME and size checked and stored outside web-executable paths.
Course structure and per-user progress summaries are cached in the object cache group `publiclms` and
invalidated on write; dashboard and lesson responses set `DONOTCACHEPAGE`. Indexes cover user and course,
course and status, and submission date; REST uses keyset pagination; dashboards read aggregates. A load
test at 10,000 students on wp-env runs before 1.0.

## 7. Architecture decision records

Each record: context, decision, consequences. Status is **Accepted** for all, as of 2026-11-04.

### ADR-001 Content in post types, learner data in custom tables
- **Context.** Incumbents that kept progress in usermeta or the comments table are the ones with
  slow-site complaints; all four big ones have moved to tables.
- **Decision.** Authored content is post types; per-learner, per-attempt and per-order data is the twelve
  `plms_*` tables in §3.2.
- **Consequences.** Block editor, revisions, REST, template hierarchy and SEO come free for content; reports
  and access checks hit indexed tables. GDPR export and erase must cover the tables explicitly (phase 2).

### ADR-002 Repository layer from day one, idempotent progress writes
- **Context.** Direct `$wpdb` calls spread across a plugin are what makes schema changes risky.
- **Decision.** Only repositories touch tables. `AbstractRepository` whitelists columns and formats;
  progress writes are upserts on the unique keys.
- **Consequences.** A repeated "mark complete" never duplicates rows; tests target repositories.

### ADR-003 Migrations through dbDelta with a schema version option
- **Decision.** `Installer::schema()` is the single source of the schema. Any change bumps
  `Installer::DB_VERSION`; `maybe_upgrade()` reruns `dbDelta()` when `publiclms_db_version` differs.
  Data migrations that dbDelta cannot express run as versioned Action Scheduler jobs.
- **Consequences.** dbDelta never drops columns; removals need an explicit migration.

### ADR-004 One access policy
- **Decision.** `AccessPolicy` answers every learner-facing access question for REST, blocks and templates,
  returning an `AccessDecision` with a reason and, for drip, an unlock time. Pro extends it through the
  `publiclms_access_decision` filter.
- **Consequences.** Drip, prerequisites and memberships change one class. In phase 0 the policy is
  permissive; it must be implemented before any real content is published.

### ADR-005 Capabilities, not roles
- **Decision.** Five primitive capabilities and four meta capabilities (§4) mapped in `map_meta_cap`. Roles
  are a starting set; code never checks a role. `Roles::install()` only adds missing capabilities.
- **Consequences.** Agencies compose roles freely; administrators receive every capability on activation.

### ADR-006 REST namespace with capability-mapped permission callbacks
- **Decision.** All plugin endpoints live under `publiclms/v1`, extend `Rest\AbstractController`, and never
  use `__return_true` for writes. The health route is public but discloses versions only to managers.
- **Consequences.** One place to audit permissions; the penetration test in phase 2 targets this matrix.

### ADR-007 React admin on WordPress components; lesson content in the block editor
- **Decision.** Course structure (sections, ordering, bulk actions) is a React builder on
  `@wordpress/components` and DataViews; lesson, quiz and assignment content opens in the block editor.
- **Consequences.** No custom editor to maintain; the builder ships in phase 1.

### ADR-008 Dynamic blocks and the Interactivity API on the front end
- **Decision.** Server-rendered dynamic blocks; the Interactivity API for the lesson player, quiz runner,
  curriculum accordion and dashboard tabs. No jQuery and no React shipped to visitors.
- **Consequences.** SEO and page caching behave; per-block CSS loads on demand.

### ADR-009 Plugin-registered block templates, theme overrides by slug
- **Decision.** The plugin registers the six templates in `CONTRACTS.md` A1 with `register_block_template()`
  and builds them from plugin patterns; a theme overrides by shipping the same slug. Classic themes get a
  `template_include` fallback that renders the same blocks between `get_header()` and `get_footer()`
  (phase 2).
- **Consequences.** The plugin works on any block theme; CampusInterface adds polish, not function.

### ADR-010 Block bindings source for course data
- **Decision.** `publiclms/course-field` exposes the keys in `CONTRACTS.md` A7 so core Heading, Paragraph,
  Button and Image blocks show course data.
- **Consequences.** Theme patterns need no custom blocks for course cards.

### ADR-011 Native checkout first, WooCommerce as a bridge
- **Decision.** Free ships Offline, Stripe (Payment Intents) and PayPal (Orders v2) through a `Gateway`
  interface, hosted or redirect flows only, with server-side webhook verification; a WooCommerce bridge is
  also free.
- **Consequences.** No card data touches the site; two admin UIs are avoided for most users.

### ADR-012 Background jobs on Action Scheduler
- **Decision.** `woocommerce/action-scheduler` ^4.1 via Composer, loaded from `vendor/` when present.
  The strategy document names 4.1; Composer currently resolves 4.2.0.
- **Consequences.** Emails, PDFs, drip releases, aggregates and imports never block a request.

### ADR-013 Certificates rendered with Dompdf behind a prefixed namespace
- **Decision.** Certificate templates are built with blocks and bindings and rendered with Dompdf, scoped
  with PHP-Scoper to avoid clashes. TCPDF is deprecated; its successor needs PHP 8.2.
- **Consequences.** A build step for scoping arrives with certificates in phase 2.

### ADR-014 Pro is a separate GPL plugin that hooks into free
- **Decision.** The free plugin never contains an "is licensed" branch. Pro registers its services and
  features on `publiclms_register_services` and `publiclms_loaded`.
- **Consequences.** Meets the plugin directory's no-locked-code rule; free hooks must stay stable.

### ADR-015 Licensing behind `LicenseProvider`, EDD Software Licensing on the store — **Deviation**
- **Context.** The strategy document's layers table names Paddle licence keys; its decisions list and the
  phase 0 brief settle on EDD Software Licensing on the WPInterface store with Paddle as checkout.
- **Decision.** The interface and a `NullLicenseProvider` ship in free (bound by default, contacting no
  server); Pro binds an EDD Software Licensing provider. Paddle is only the checkout.
- **Consequences.** Freemius or another service can replace EDD without touching Pro features.

### ADR-016 Grade on the server
- **Decision.** Quiz answers post to REST; scores are computed in PHP; submissions are rate-limited per
  user and quiz; correct answers never reach the browser before submission.

### ADR-017 Abilities API registration
- **Decision.** Register `publiclms/enroll-user` and `publiclms/get-progress` as abilities. Deferred from
  "day one" to phase 2, when the services they call exist.

### ADR-018 Names and prefixes — **Deviation**
- **Context.** The strategy document proposes "CourseInterface" with `lms_`, `lms/v1` and `lms/` prefixes.
- **Decision.** Per the phase 0 brief: display name "Public LMS"; slug and text domain `public-lms`;
  function, option and hook prefix `publiclms_`; post types, taxonomies, meta and tables `plms_`; namespace
  `PublicLMS\`; blocks `publiclms/`; REST `publiclms/v1`; bindings `publiclms/course-field`; constants
  `PUBLICLMS_`. Capabilities follow the function prefix (`publiclms_manage_courses`).
- **Consequences.** A rename before 1.0 is find-and-replace; after submission the slug is permanent.

### ADR-019 Free tier: pedagogy free, including the gradebook — **Deviation**
- **Context.** The document's feature table lists the gradebook under Pro; its pricing table and the
  approved boundary ("pedagogy free, commerce and scale paid") put it in free.
- **Decision.** Gradebook is free. Weighted grades and manual grading of essay and image questions stay Pro.

### ADR-020 Version floors and test matrix — **Deviation**
- **Decision.** `Requires at least: 6.7`, `Requires PHP: 7.4`, tested up to 7.1. CI runs PHPUnit on PHP 7.4
  and 8.3 against WordPress 6.7 and latest (the brief), not the document's 7.4–8.4 and 6.7/7.1/7.2 matrix.
  Add 7.2 to the matrix when it ships on 8 Dec 2026. Node 24 LTS for tooling (Node 20 is end of life and
  `@wordpress/scripts` 37 requires 22 or 24).

### ADR-021 Uninstall keeps data unless the owner opts in
- **Context.** The document is silent. Deleting a plugin by accident must not destroy grades and orders.
- **Decision.** `uninstall.php` removes data only when `PUBLICLMS_REMOVE_ALL_DATA` is true or option
  `publiclms_remove_data_on_uninstall` is `yes`; then it deletes content, drops tables, removes roles and
  capabilities and options, on every site of a network.

### ADR-022 Autoloader fallback without vendor/
- **Context.** Playground `git:directory` installs and fresh clones have no `vendor/`.
- **Decision.** The main file uses Composer's autoloader when present and otherwise a minimal PSR-4 loader
  for `PublicLMS\`. Action Scheduler loads only when installed; nothing in phase 0 depends on it.
- **Consequences.** Release zips always include `vendor/` (production dependencies only).

### ADR-023 Friendly template slugs, aliased into the hierarchy — **Deviation**
- **Context.** The brief fixes the slugs `single-course`, `archive-course`, `single-lesson`, `single-quiz`,
  `page-dashboard`, `page-instructor`. WordPress looks up `single-plms_course` for the plugin's post types,
  so the friendly slugs need an alias. They also match other plugins' post types literally named `course`,
  `lesson` or `quiz` (Sensei LMS; LifterLMS for two of them) and any page with the slug `dashboard`. The
  document also listed a `taxonomy-course-category` template, which the brief does not.
- **Decision.** `TemplateRegistrar` inserts each friendly slug directly after the post-type candidate, sends
  course term archives to `archive-course`, and applies the two page templates only to their configured
  pages or where an editor picked them. Themes that ship these slugs add the guard in `CONTRACTS.md` A5.6.
- **Consequences.** Readable slugs for theme authors. Running Public LMS beside Sensei or LifterLMS can show
  the plugin's course template on their courses when the theme has none of its own. If that matters in
  practice, switch to post-type-exact slugs (`single-plms_course`) in a major contract bump.

### ADR-024 Health route
- **Decision.** `GET /publiclms/v1/health` returns `{"status":"ok"}` to anyone and adds version, schema
  version and per-table checks for users with `publiclms_manage_courses`. Used by the Playground
  integration build and uptime checks.

### ADR-025 Post type capability profiles
- **Context.** The document fixes capabilities, not how each post type maps to them.
- **Decision.** Three profiles: **content** (course, section, lesson, quiz, question, assignment): create,
  publish and edit own with `publiclms_edit_own_course`, others' with `publiclms_manage_courses`;
  **manage** (certificate, group): everything needs `publiclms_manage_courses`; **orders** (membership):
  everything needs `publiclms_manage_orders`. Taxonomy terms are managed with `publiclms_manage_courses` and
  assigned with `publiclms_edit_own_course`. `delete_with_user` is off for every type.
- **Consequences.** Instructors publish their own courses without review; a review step for marketplaces is
  a Pro feature (filter `publiclms_post_type_args`). Lesson ownership will follow the parent course's
  teacher, not the lesson author, once relationships exist (phase 1).

### ADR-026 Storage conventions
- **Decision.** UTC datetimes; `DECIMAL(19,4)` money with ISO 4217 currency; JSON in `LONGTEXT`; indexed
  `VARCHAR` ≤ 191 characters; enum-like columns are `VARCHAR(20)` with values documented in code, not MySQL
  `ENUM`.

### ADR-027 Static analysis and standards
- **Decision.** WPCS 3.4 (`WordPress-Extra` and `WordPress-Docs`), PHPCompatibilityWP with `testVersion 7.4-`
  (added beyond the brief to enforce the PHP floor), PHPStan level 6 with `szepeviktor/phpstan-wordpress`,
  ESLint through `@wordpress/scripts`. PSR-4 file names are allowed in place of `class-*.php`.
