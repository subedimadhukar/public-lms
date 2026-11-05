# Public LMS architecture

Status: phase 2 part A (teaching stack, 0.3.0). Phase 0 decisions stand; phase 1 decisions are ADR-028 to
ADR-039; phase 2 decisions are ADR-040 onwards. Source: the "LMS plugin architecture and complete ecosystem"
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
| Data | `Core\Installer` (dbDelta schema), `Repositories\*` | Schema done; interfaces and base repository only | Phase 1: enrolment, progress, event and course structure repositories with object caching. |
| Access | `Access\AccessPolicy`, `Access\AccessDecision`, `Access\ContentGate` | Phase 2: enrolment, publish state, preview, course and item prerequisites, drip by days or date (ADR-040) | Rule order documented in the class; the gate filters `the_content` for every template and API. |
| REST | `Rest\AbstractController`, `Rest\Api`, controllers per resource | Phase 1: courses, curriculum, sections, lessons, enrolments, progress, students | Matrix in §4. |
| Templates | `Templates\TemplateRegistrar`, `PatternRegistrar`, `ClassicTemplates` | Phase 1: four templates, twelve patterns, classic theme fallback | ADR-037. |
| Licensing | `Licensing\LicenseProvider`, `NullLicenseProvider`, `LicenseStatus` | Done | Pro binds the EDD provider. |
| Admin | One React app on `@wordpress/components`, `@wordpress/data` stores per domain, DataViews | Phase 1: "Public LMS" menu with Courses (DataViews list and course builder), Students (DataViews) and Settings | ADR-033, ADR-034. |
| Front end | Dynamic blocks with block supports; Interactivity API for player, quiz runner, curriculum, dashboard | Phase 2: video and quiz blocks join; one shared store module split into source files (`api.js`, `quiz.js`) | ADR-035. |
| Bindings | `publiclms/course-field` source in PHP and JS | Phase 1: every A7 key; `rating` is empty until reviews (phase 2) | ADR-010. |
| Payments | `Gateway` interface; hosted or redirect flows only | Not started | Phase 2. |
| Jobs | Action Scheduler ^4.1 via Composer | Loaded when installed | Emails, PDFs, drip, aggregates, renewals, imports. |
| Certificates | Block-built template rendered to PDF with a patched mPDF 8.3.1, prefixed with Strauss into `vendor-prefixed/`; a font per script from bundled Noto and DejaVu, the Font Library and a filter | Phase 2: one template, issue on completion, PDF download, dashboard tab, every script (ADR-044, ADR-052) | Multiple templates and the public verification page are Pro. |
| Privacy | `Privacy\PersonalData` | Phase 2: exporters and erasers for every learner table (ADR-044) | Orders are kept with personal details removed. |

### Folder layout

```
public-lms/
├─ public-lms.php  uninstall.php  readme.txt  README.md
├─ composer.json  package.json  webpack.config.js  playwright.config.js
├─ phpcs.xml.dist  phpstan.neon  phpunit.xml.dist  .wp-env.json  playground.json
├─ includes/   Core/ PostTypes/ Taxonomies/ Capabilities/ Repositories/ Services/ Courses/ Access/
│             Rest/ Admin/ Blocks/ Templates/ Frontend/ Cli/ Licensing/
│             (phase 2+: Payments/ Emails/ Certificates/ Reports/ Jobs/ Abilities/)
├─ src/        admin/ (React app)  store/ (data stores)  editor/ (bindings, query variation, course panel)
│             blocks/<name>/{block.json, index.js, edit.js, render.php, style.scss}
│             interactivity/learning.js (the shared store module)
├─ patterns/   templates/   assets/css/tokens.css
├─ build/      (gitignored)    languages/   docs/   bin/timing-check.sh   bin/prefix-vendor.sh
├─ vendor-prefixed/  mPDF under PublicLMS\Vendor (built by bin/prefix-vendor.sh, ADR-052)   tools/  (Strauss and its input)
├─ tests/      phpunit/  phpstan/  e2e/
└─ .github/workflows/  lint.yml  tests.yml (PHPUnit matrix, Playwright)  build.yml
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
| `publiclms_quiz_graded`, `publiclms_quiz_passed` | actions | After the server grades an attempt; the first pass (2) |
| `publiclms_assignment_submitted`, `publiclms_assignment_graded` | actions | Hand-ins and grades, for emails in part B (2) |
| `publiclms_certificate_issued` | action | After a certificate is issued (2) |
| `publiclms_dashboard_tabs`, `publiclms_dashboard_rows`, `publiclms_dashboard_panel` | filters, action | Add a student dashboard tab (certificates use them; Pro's orders will) (2) |
| `publiclms_rate_limit` | filter | Loosen or tighten a rate limit (quiz starts and submits, hand-ins) (2) |
| `publiclms_private_dir` | filter | Move private uploads outside the web root (2) |
| `publiclms_certificate_pdf_options`, `publiclms_certificate_css` | filters | Paper, fonts and styles of certificate PDFs (2) |
| `publiclms_reported_courses` | filter | Courses whose hand-ins a user sees; Pro adds co-taught courses (2) |

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
| `plms_coupon` | none | orders | Discount code; published = active, draft = paused (phase 2, ADR-050) |

Taxonomies, all on `plms_course`: `plms_course_category` (hierarchical, `/course-category/`),
`plms_course_tag` (flat, `/course-tag/`), `plms_level` (flat list with checkbox UI, `/course-level/`).
Curriculum relationships are `post_parent` and `menu_order` (ADR-028): a section's parent is its course, a
lesson's, quiz's or assignment's parent is its section. Question → quiz links arrive with quizzes in phase 2.
Course and item settings are registered post meta with the `_plms_` prefix (`PostTypes\Meta`):
`_plms_access_mode` (free, closed, paid), `_plms_price`, `_plms_sale_price`, `_plms_duration` (minutes) and,
on items, `_plms_preview`. Phase 2 adds `_plms_drip_days` and `_plms_drip_date` (sections and items),
`_plms_prerequisites` (course IDs on courses, item IDs on items), `_plms_attachments` (lessons) and
`_plms_certificate` (courses, `1` or `0`); see ADR-040. Lessons, quizzes and assignments are excluded from
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
| `plms_assignment_submissions` | user_id, assignment_id, course_id, attempt, status, content, files (JSON), grade, max_grade, passed, feedback, submitted_at, graded_at, graded_by | (user_id, assignment_id); (assignment_id, status); (course_id, status); submitted_at | Phase 2 (schema 2); files live in the private folder, never in the media library (ADR-043) |
| `plms_certificates` | certificate_key, user_id, course_id, template_id, learner_name, course_title, completed_at, issued_at, revoked_at, data (JSON) | **UNIQUE certificate_key**; **UNIQUE (user_id, course_id)**; course_id | Phase 2 (schema 3); what the certificate says, fixed at issue (ADR-044) |

Schema version: `Installer::DB_VERSION` (currently `3`), stored in option `publiclms_db_version`; the plugin
version in `publiclms_version`. `Installer::maybe_upgrade()` runs `dbDelta()` when either is out of date.

## 4. REST resources and permission matrix

Namespace `publiclms/v1` (ADR-006, ADR-018). Post types are also on `wp/v2` under their keys
(`/wp/v2/plms_course`); gated item content is filtered there too (ADR-031). Collections sorted by ID use
keyset pagination (`after`, `per_page` ≤ 100); collections with other sort orders use an opaque `cursor`
(ADR-030). Every collection sends `X-WP-Total`, and `X-PublicLMS-Next-Cursor` plus a `Link: rel="next"`
header while more rows exist.

| Resource | Routes (phase) | Read | Write |
|---|---|---|---|
| `health` | `GET /health` (0) | anyone (status only); version and tables for `publiclms_manage_courses` | none |
| `courses` | `GET /courses`, `POST /courses`, `GET`, `PATCH`, `DELETE /courses/{id}` (1) | published: anyone; `context=edit` or other statuses: `publiclms_edit_own_course`, limited to own courses without `publiclms_manage_courses`; single: AccessPolicy `can_view_course` | create: the course type's `create_posts`; publish: `publish_posts`; update: `publiclms_edit_course` (meta); delete: `delete_post` |
| `courses/{id}/curriculum` | `GET`, `PUT` (1) | AccessPolicy `can_view_course`; editors get every status, learners published items with `completed` and `locked` | `publiclms_edit_course` on the course and on any course a section or item is taken from; the body must list every current section and item (409 otherwise) |
| `sections` | `POST /sections`, `PATCH`, `DELETE /sections/{id}` (1) | via curriculum | `publiclms_edit_course` on the section's course |
| `lessons` | `GET`, `PATCH`, `DELETE /lessons/{id}`, `POST /lessons` (1) | `can_view_course` on its course; `content` only when `can_access_item` allows | `publiclms_edit_course` on its course, and on the target course of a move |
| `courses/{id}/enrollments` | `GET`, `POST`, `DELETE /{user_id}` (1) | `publiclms_view_course_reports` (meta) | `publiclms_enroll_student` (meta) |
| `enrollments/me` | `GET`, `POST` (free enrol) (1) | logged in | logged in + AccessPolicy `can_enroll` |
| `progress` | `GET /courses/{id}/progress/me`, `POST /progress` `{item_id, status}` (1) | logged in + enrolled (or can edit the course) | logged in + AccessPolicy `can_access_item` + active enrolment; lessons only from phase 2 (quizzes and assignments: 409) |
| `students` | `GET /students`, `GET /students/{id}` (1) | `publiclms_view_reports`; all courses with `publiclms_manage_courses`, otherwise the courses the user teaches; `course` filter needs `publiclms_view_course_reports` on it | none |
| `quizzes` | `POST`, `GET`, `PATCH`, `DELETE /quizzes/{id}` (2 ✓) | as lessons; editors get the question list, learners their own standing | as lessons, plus `edit_post` on any question added |
| `questions` | `GET`, `POST`, `GET`, `PATCH`, `DELETE /questions/{id}` (2 ✓) | `publiclms_edit_own_course`; own questions unless `publiclms_manage_courses`; single: `edit_post` | create: the question type's `create_posts`; edit: `edit_post`; delete: `delete_post` |
| `quizzes/{id}/attempts` | `GET`, `POST` start or resume, `GET /{attempt}`, `POST /{attempt}/answers`, `POST /{attempt}/submit` (2 ✓) | own attempts; others need `publiclms_view_course_reports` | AccessPolicy `can_attempt_quiz` (attempts limit); rate-limited (429 with `Retry-After`) |
| `assignments` | `POST`, `GET`, `PATCH`, `DELETE /assignments/{id}` (2 ✓) | as lessons, plus the learner's own hand-in | as lessons |
| `assignments/{id}/submissions` | `GET`, `POST` (multipart), `PUT /{submission}/grade` (2 ✓) | own; others need `publiclms_view_course_reports` | submit: `can_submit_assignment`, rate-limited; grade: `publiclms_edit_course` |
| `submissions` | `GET /submissions`, `GET /submissions/{id}`, `GET /submissions/{id}/files/{file}` (2 ✓) | list: `publiclms_view_reports`, scoped to taught courses; single and files: the learner or `publiclms_view_course_reports` | none |
| `courses/{id}/gradebook` | `GET` (JSON pages, or `format=csv`) (2 ✓) | `publiclms_view_course_reports` | none |
| `orders` | `GET`, `GET /counts`, `GET /{id}`, `POST /checkout`, `POST /quote`, `POST /{id}/complete`, `POST /{id}/cancel`, `POST /{id}/refund` (2 ✓) | own orders; all (and counts) need `publiclms_manage_orders` | checkout and quote: anyone (guests with name and email when guest checkout is on), rate-limited; complete, cancel, refund: `publiclms_manage_order` |
| `webhooks/{gateway}` | `POST` (2 ✓) | none | signature verified server-side against the raw body; no cookie auth; 400 when unsigned or wrongly signed |
| `coupons` | `GET`, `POST`, `GET`, `PATCH`, `DELETE /coupons/{id}` (2 ✓) | `publiclms_manage_orders` | `publiclms_manage_orders` |
| `settings/payments` | `GET`, `POST`, `POST /checkout-page` (2 ✓) | `publiclms_manage_orders`; secrets are never returned, only whether they are set and whether a constant sets them | `publiclms_manage_orders` (creating the page also needs `publish_pages`) |
| `reports` | `GET /courses/{id}`, `GET /site` (2) | `publiclms_view_course_reports` (meta; site-wide needs `publiclms_manage_courses` too) | none |
| `certificates` | `GET /certificates/me`, `GET /certificates/{course_id}` PDF (`user_id` for report viewers), `GET /certificates/preview` (2 ✓) | me: logged in; PDF: AccessPolicy `can_view_certificate`; preview: `publiclms_manage_courses` | none |

The phase 0 draft named the completion route `POST /items/{id}/complete`; phase 1 ships `POST /progress` with
`status` `completed` or `incomplete`, so undoing a completion needs no second route.

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

`edit_post` and `delete_post` on a section, lesson, quiz or assignment that belongs to a course resolve to
`publiclms_edit_own_course` for whoever teaches that course and to `publiclms_manage_courses` for everyone
else, whoever authored the item (ADR-025). Items outside any course keep WordPress's author mapping.

## 5. Block list

All blocks are dynamic (`render.php`), declare block supports (colour, typography, spacing, border, shadow),
and mark user-editable attributes with `"role": "content"`. Every learner block shares one Interactivity
API store, `publiclms/learning`, loaded once as the script module `@publiclms/learning` (ADR-035; the
phase 0 draft named one store per block).

| Block | Key attributes | Interactive | Phase |
|---|---|---|---|
| `publiclms/curriculum` | `courseId`, `showLockedState`, `collapsible`, `headingLevel` | yes (completion and lock state; sections are native `<details>`) | 1 ✓ |
| `publiclms/enroll-button` | `courseId`, `label` (content), `showPrice` | yes (in-place REST enrolment; no-JS form) | 1 ✓ |
| `publiclms/course-progress` | `courseId`; block styles `bar` (default), `ring`, `text` | yes (live value) | 1 ✓ |
| `publiclms/mark-complete` | `label` (content), `completedLabel` (content) | yes (the lesson player) | 1 ✓ |
| `publiclms/lesson-navigation` | `showTitles`, `previousLabel` (content), `nextLabel` (content) | yes (next link follows completion) | 1 ✓ |
| `publiclms/lesson-content` | `lockedMessage` (content), `showAttachments` | no (gated by the access policy; lists the lesson's files, phase 2) | 1 ✓ (added in phase 1) |
| `publiclms/course-meta` | `courseId`, `fields`, `showLabels`, `layout` | no | 1 ✓ (added in phase 1) |
| `publiclms/student-dashboard` | `tabs`, `defaultTab` | yes (WAI-ARIA tabs) | 1 ✓ (tabs: in progress, completed; phase 2 adds quiz results and certificates) |
| `publiclms/video` | `provider`, `src`, `mediaId`, `captions`, `title`, `caption`, `trackCompletion`, `requiredPercent` | yes (watch tracking, ADR-041) | 2 ✓ |
| `publiclms/quiz` | `quizId`, `showFacts` | yes (runner, ADR-042) | 2 ✓ |
| `publiclms/assignment` | `assignmentId` (limits live on the assignment, ADR-043) | yes (hand-in) | 2 ✓ |
| `publiclms/instructor-dashboard` | `tabs` | yes | 2 |
| `publiclms/course-reviews` | `courseId`, `perPage`, `showForm` | yes | 2 |
| `publiclms/course-qa` | `courseId`, `perPage` | yes | 2 |
| `publiclms/announcements` | `courseId`, `perPage` | no | 2 |
| `publiclms/certificate-button` | `courseId`, `label` (content) | no | 2 ✓ (in `course-enroll-card`) |
| `publiclms/checkout` | `showCoupon`, `gateways` | yes (coupon quote, order placement; no-JS form) | 2 ✓ |
| `publiclms/course-filters` | `taxonomies`, `showSearch` | yes (filters a `publiclms/course-query`) | 2 |
| `publiclms/login-form` | `redirect`, `showRegister` | no | 2 |
| `publiclms/instructor-card` | `userId`, `showBio`, `showCourses` | no | 2 |

Plus the Query Loop variation `publiclms/course-query` (titled "Course Grid" in the inserter, ADR-036) and the
bindings source `publiclms/course-field`.

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
- **Status.** Superseded: Strauss replaced PHP-Scoper (ADR-044), and mPDF replaced Dompdf (ADR-052).
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
- **Known limit on 6.7.0.** WordPress 6.7.0's template registry only accepts `[a-z0-9-]` in template names;
  6.7.1 widened it to allow underscores. The post-type slugs (ADR-023) contain underscores, so on 6.7.0
  exactly the course, archive and lesson templates fail to register and the theme's own templates apply.
  Core applies minor releases automatically by default, so CI tests the floor at the newest 6.7 point
  release (6.7.11 as of 4 Nov 2026) rather than 6.7.0. Raising the header to 6.7.1 is an open question.

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

### ADR-023 Template slugs follow the post type keys — **Deviation** (owner decision, 2026-11-04)
- **Context.** The phase 0 brief named the slugs `single-course`, `archive-course`, `single-lesson`,
  `single-quiz`, `page-dashboard` and `page-instructor`. WordPress looks up `single-plms_course` for the
  plugin's post types, so those slugs needed hierarchy aliases. Worse, they are also the names WordPress looks
  up for other plugins whose post types are literally `course`, `lesson` or `quiz` (Sensei LMS, LifterLMS),
  and `page-dashboard` matches any page with the slug "dashboard". Every theme in the Interface family would
  have needed guard code to stay off those pages, and the Site Editor's "Add template → Single item: Course"
  creates `single-plms_course`, which would have competed with `single-course`. The strategy document also
  listed a `taxonomy-course-category` template.
- **Decision.** The owner chose post-type-exact slugs before the phase 0 gate: `single-plms_course`,
  `archive-plms_course`, `single-plms_lesson`, `single-plms_quiz`, and `plms-dashboard` and `plms-instructor`
  for the two page templates (no `page-` prefix, so WordPress never matches them by page slug). The plugin
  adds only two hierarchy entries: course term archives fall back to `archive-plms_course`, and the dashboard
  templates apply to the pages chosen in settings. No separate taxonomy template.
- **Consequences.** No alias filters for singles and archives, no guard code in any theme, and no collision
  with other LMS plugins. Theme files are named `templates/single-plms_course.html`; site owners see the
  template titles ("Single Course"), not the slugs. Later templates follow the same rule (post type key or
  `plms-` prefix).

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

### ADR-028 Course structure in `post_parent` and `menu_order`
- **Context.** The phase 1 brief asked for the curriculum in a `plms_course_items` table or in ordered meta,
  recorded here. Sections and items are already posts.
- **Decision.** Neither: the structure is stored on the posts. A section's `post_parent` is its course; a
  lesson's, quiz's or assignment's `post_parent` is its section; `menu_order`, then ID, orders both.
  `Repositories\CourseStructureRepository` is the only writer. It reads a course's structure with two queries
  and caches it under `structure:{course_id}` in the `publiclms` group, cleared on `clean_post_cache` and
  `post_updated` for any course, section or item, so edits made in the block editor or wp-admin invalidate it
  too.
- **Why not a table or meta.** WordPress's exporter and importer carry `post_parent` and remap it to the new
  IDs; a table is not exported at all, and meta holding post IDs is copied with the old IDs. Posts need no
  sync code for trash, restore and delete, and core already caches them. Items are non-hierarchical post
  types, so a parent never changes their URLs.
- **Consequences.** An item belongs to exactly one section; lessons shared between courses (LearnDash's
  "shared steps") would need a join table later. Deleting a section in the builder trashes its items;
  deleting one from wp-admin leaves its items orphaned, outside any curriculum, until phase 2 adds an
  "Unassigned" bin. Progress totals count only published items in published sections, so a deleted lesson
  never pushes a learner past 100 percent.

### ADR-029 Enrolment and progress semantics
- **Decision.** One enrolment row per user and course, written with `INSERT … ON DUPLICATE KEY UPDATE`.
  Status is `active`, `pending`, `cancelled` or `expired`; completion is `completed_at`, not a status, so a
  learner who finishes keeps access. Unenrolling sets `cancelled` and keeps progress, so re-enrolling resumes
  it. Item progress is an upsert on (user, item) that never downgrades `completed` and keeps the first
  completion time; "mark incomplete" deletes the row. The course aggregate is recomputed from the course's
  current published items on every completion and sets `completed_at` the first time it reaches 100 percent
  (cleared if new lessons bring it below). `VALUES()` is used in the upserts rather than the MySQL 8 row
  alias, which MariaDB and MySQL 5.7 lack.
- **Caching.** `enrollments:user:{id}` (every row of a user, which serves all access checks in a request),
  `enrolled_count:{course}`, `progress_items:{user}:{course}` and `course_progress:user:{id}`, all cleared by
  the repository that writes them.
- **Percentages.** `ProgressRepository::percent()` rounds to the nearest whole number (1 of 6 is 17%), but
  reads 100 only when every item is done and at least 1 once any item is. The stored aggregate, the live
  summary, REST, the progress bar, the dashboard and the live-region message all use it.
- **Events.** Enrolment, item completion and course completion append to `plms_events` for phase 2 reports.

### ADR-030 Cursor pagination for sorted lists
- **Context.** ADR-006 and §4 call for keyset pagination. Keyset on the ID alone cannot serve the admin's
  lists, which sort by title, date, name or enrolment date.
- **Decision.** Lists sorted by ID keep `after`. Lists with other sort orders (`/courses`, `/students`) take
  an opaque `cursor`: base64url JSON of the last row's sort value and ID. The query continues with
  `(sort > v) OR (sort = v AND id > i)`, so pages are stable while rows are added. `X-WP-Total` is computed
  with a separate count query; there are no page numbers. The admin app keeps the cursors of the pages it
  has seen and offers "next" only when the server sent a cursor.
- **Consequences.** No `OFFSET` scans on large tables; a client cannot jump to page 40 directly.

### ADR-031 Gate item content at `the_content`, not only in blocks
- **Context.** Lessons are public post types on `wp/v2`, in feeds and in oEmbed, and a theme may render a
  lesson with core Post Content instead of `publiclms/lesson-content`.
- **Decision.** `Access\ContentGate` filters `the_content` at priority 0 for lessons, quizzes and
  assignments: when `AccessPolicy::can_access_item()` denies, the content is replaced by a notice before any
  block renders. Generated excerpts of locked items are emptied after `wp_trim_excerpt()`; hand-written
  excerpts stay public. Lesson pages and the dashboard page define `DONOTCACHEPAGE`.
- **Consequences.** No path leaks locked content, whatever the template. Raw content is still visible in
  `context=edit`, which needs `edit_post`.

### ADR-032 Services own the side effects of enrolment and progress
- **Decision.** `Services\EnrollmentService` and `Services\ProgressService` sit between REST (and the
  no-JavaScript `admin-post.php?action=publiclms_enroll` handler) and the repositories. They check the access
  policy, write the rows, refresh the course aggregate, append events and fire `publiclms_enrolled`,
  `publiclms_unenrolled`, `publiclms_item_completed` and `publiclms_course_completed`, each exactly once.
  `Courses\CourseFields` computes every learner-facing course value (price, duration, counts, enrol state)
  for REST, blocks and the bindings source, so they never disagree.
- **Consequences.** Progress needs an active enrolment even for free previews and for teachers previewing
  their own course; summaries are computed from the cached structure, so they reflect lessons added since
  the stored aggregate was written.

### ADR-033 Admin app shell: one menu, DataViews' `/wp` build, stores per domain
- **Decision.** A top-level "Public LMS" menu (`dashicons-welcome-learn-more`) holds Courses, Students,
  Categories, Levels and Settings; the course and item post types no longer appear in the menu (their edit
  screens stay, for the block editor and for Pro). One React app (`src/admin/`, `build/admin/index.js`) reads
  which screen it is on from `window.publiclmsAdmin`. Data lives in `@wordpress/data` stores per domain,
  `publiclms/courses` and `publiclms/students`, with thunk resolvers over `apiFetch`; lists are cached per
  query including the cursor (ADR-030). DataViews is imported from `@wordpress/dataviews/wp`, the build made
  for plugins: it inlines its own components and uses WordPress's data, i18n, date and hooks, so the list
  screens do not depend on the private component APIs of whichever WordPress version runs them (6.7 to
  7.1). Its stylesheet is inlined into `build/admin/style-index.css`, which depends on `wp-components`.
  Plugin settings sit behind `publiclms/v1/settings` (`publiclms_manage_courses`) rather than
  `/wp/v2/settings`, which needs `manage_options`; the uninstall data switch also needs `delete_plugins`.
  A document panel in the block editor links lessons back to their course builder, using a read-only
  `publiclms` field on the `wp/v2` routes.
- **Consequences.** The admin bundle is large (about 1.7 MB minified, mostly DataViews and its UI
  libraries) but loads only on the plugin's own screens. Styles come from the stylesheet and the admin
  colour scheme; the only inline style is the transform dnd-kit sets while a row moves, passed through CSS
  custom properties.

### ADR-034 Course builder drag and drop with dnd-kit
- **Context.** The builder reorders sections and moves lessons between sections. DataViews' grouping shows
  rows grouped by a field but has no reordering, and nothing in `@wordpress/components` sorts nested lists.
- **Decision.** `@dnd-kit/core` and `@dnd-kit/sortable`, bundled: one sortable list of sections, one per
  section for its items, with a collision filter so sections only meet sections and lessons meet lessons
  or empty sections. Keyboard sorting (Space, arrows, Space) with translated screen reader instructions
  and announcements; every row also has "Move up" and "Move down" in its menu. The new order shows at
  once and is saved with `PUT /courses/{id}/curriculum`; on failure the server's order comes back.
- **Consequences.** About 45 KB of the admin bundle. Nested drag (sections inside sections) is not needed
  and not supported.

### ADR-035 Learner blocks share one Interactivity store; derived state on both sides
- **Context.** The phase 0 draft named one store per block (`publiclms/<block>`). But marking a lesson
  complete must move the progress bar, tick the curriculum and change the next link, which live in other
  blocks; and WordPress processes directives on the server, so a `data-wp-text` that points at a getter
  only JavaScript knows renders empty.
- **Decision.** One store, `publiclms/learning`, built as one script module (`build/interactivity/learning.js`,
  registered as `@publiclms/learning`); every interactive block's `block.json` names it in
  `viewScriptModule`, so it loads once. `Blocks::seed_state()` seeds the REST URL, the `wp_rest` nonce, the
  course's progress, the enrolment flag, the next item and the translated strings (script modules cannot
  load translations). Every getter in the JS store has a PHP closure twin in `Blocks::derived_state()`, so
  server-rendered markup and hydrated markup agree (core evaluates closures during directive processing and
  never serializes them). Mark complete posts to `publiclms/v1/progress`; the response updates the shared
  progress and the next link without a reload; the result is announced in a polite live region.
- **Lesson HTML.** `publiclms/lesson-content` and `GET /lessons/{id}` pass the stored HTML through
  `wp_kses_post()` before `the_content` renders blocks, so script in lesson HTML never reaches learners
  (whoever wrote it) while embeds, which render from block markup, keep working.
- **Course progress looks** are block style variations (`is-style-bar`, `is-style-ring`, `is-style-text`),
  not an attribute: an attribute named `style` collides with WordPress's own block-supports `style`.
- **State colours.** Block CSS sits in `:where()` so themes win, except where a state paints text on a filled
  background: the completed lesson's next link. Core's global link colour, `a:where(:not(.wp-element-button))`
  and its `:hover` and `:focus` variants, outranks `:where()` and painted primary text on the primary fill
  (1:1 on CampusInterface). That rule now carries real specificity and is themed through the colour aliases;
  the e2e journey asserts at least 4.5:1 at rest, on hover and after a reload.
- **Accessibility.** The curriculum's sections are native `<details>`; the dashboard tabs follow the WAI-ARIA
  tabs pattern; the mark-complete button keeps its visible label as its name and gains a description while
  complete; progress is a `role="progressbar"` with live `aria-valuenow`; no motion without
  `prefers-reduced-motion: no-preference`.
- **Consequences.** Adding a learner block means adding its getters in both files. Directive attributes on
  WordPress 6.7 and 6.8 rely on closures too; 6.9 also tells the client which ones they are.

### ADR-036 Course grid is a Query Loop variation, not a wrapper block
- **Context.** The brief asked for `publiclms/course-grid` as a Query Loop variation, or a wrapper block if
  the variation could not carry course data. `CONTRACTS.md` A5 names the variation `publiclms/course-query`
  and the archive pattern `publiclms/course-grid`.
- **Decision.** The variation keeps the contract's name, `publiclms/course-query` (shown as "Course Grid" in
  the inserter), and the pattern keeps `publiclms/course-grid`. Course data on the cards comes from core
  blocks bound to `publiclms/course-field`, so no wrapper is needed. The variation adds one setting core
  lacks, `query.publiclmsHideEnrolled`, applied on the front end (`query_loop_block_query_vars`) and in the
  editor preview (`rest_plms_course_query`); level and category filtering use core's taxonomy controls.
- **Consequences.** No contract change; themes compose course cards from core blocks.

### ADR-037 Plugin templates from patterns, with a classic theme fallback in phase 1
- **Decision.** The plugin ships `templates/single-plms_course.html`, `archive-plms_course.html`,
  `single-plms_lesson.html` and `plms-dashboard.html`, each a header part, a `<main>` group of
  `publiclms/*` patterns and a footer part (CONTRACTS.md A1, A4). `Templates\PatternRegistrar` registers
  `patterns/*.php` from their file headers (WordPress has no automatic pattern folder for plugins), lazily
  through `filePath` on 6.8+. Static blocks in patterns carry `publiclms-*` helper classes instead of inline
  style objects, so saved markup always validates in the Site Editor (checked: every pattern and template
  parses with no invalid block) and themes restyle them through the token aliases. The lesson template puts
  the lesson before the curriculum sidebar in source order, so keyboard and screen reader users reach the
  lesson first. The fallback ADR-009 planned for phase 2 ships now: on a classic theme,
  `Templates\ClassicTemplates` routes plugin requests to `templates/classic.php`, which prints
  `get_header()`, the template's blocks without its top-level template parts, and `get_footer()`, unless the
  theme has its own `{slug}.php`. The created dashboard page uses the dashboard template through the
  `publiclms_dashboard_page_id` hierarchy rule, not a stored page template, so it also works on classic
  themes.
- **Consequences.** Verified on Twenty Twenty-Five (block) and Twenty Twenty-One (classic) without the
  CampusInterface theme. The course template leaves out `publiclms/course-reviews` until reviews exist.

### ADR-038 Fixtures, end-to-end tests and the alpha timing check
- **Decision.** `wp publiclms seed --courses=10` creates three instructors, up to six learners, ten distinct
  courses (two sections of three lessons each, real teaching copy, categories and levels), free enrolments
  with varied progress and a dashboard page; everything it creates carries `_plms_seed` post, term or user
  meta, so `wp publiclms reset --yes` removes exactly that, including the seeded users' enrolment, progress
  and event rows. It refuses to run on a production environment without `--force`. Seeded users share one
  password (`--password`, `PUBLICLMS_SEED_PASSWORD`, or `public-lms-demo`). Since phase 2 part B the logins
  are role-based (`learner`, `instructor`, then `learner.*` and `instructor.*`) and the display names span
  several scripts (Arabic, Devanagari, Latin, Japanese, Korean, Chinese, Cyrillic), so the audience the
  plugin serves is global from the first screen; one seeded course is paid, with a checkout page, the
  offline method and the coupon `WELCOME10` created when the site has none. Playwright
  (`@wordpress/e2e-test-utils-playwright`) covers the alpha journey in `tests/e2e/`: an instructor builds a
  course with two sections and three lessons in the admin app, a student enrols, completes a lesson
  (no navigation, progress 33 percent, next link ready) and sees it on the dashboard, whose tabs work from the
  keyboard. The same test records the lesson page's navigation timing; `bin/timing-check.sh` measures
  time to first byte with curl, logged out or with a learner's cookie.
- **Consequences.** CI runs the e2e job on WordPress latest. Learners (no `edit_posts`) land on the
  dashboard page after login unless the login link asked for somewhere else.

### ADR-039 Contract 1.2.0: the theme's phase 1 open items
- **Decision.** The nine items CampusInterface logged against the contract are settled additively
  (CONTRACTS.md 1.2.0, "Changes in 1.2.0"). Added now: `query.publiclmsRelated` on the course query variation
  (same-category courses first, then the newest others, via `post__in` so pagination and hide-enrolled keep
  working), the form-field colour rule, B2-only font-size slugs in patterns with alias fallbacks, and default
  layouts on column and sidebar patterns. Recorded as phase 2: the `publiclms/course-filters` block and the
  review, quiz and instructor-dashboard patterns; themes keep their own filters and hide those areas until
  then. Items 1 and 6 were already met by 1.1.0 keys and the namespace-based `isActive`.
- **Consequences.** On themes without the B2 presets (Twenty Twenty-Five) pattern text sizes come from the
  alias fallbacks rather than core's small and large presets.

### ADR-040 Drip and prerequisites in the access policy
- **Context.** Phase 1 left rules 6 and 7 of `AccessPolicy` as documented no-ops. The PRD puts drip "by
  days or date" and prerequisites in free; the brief names "course needs course; lesson needs lesson".
- **Decision.** Drip lives on sections and items: `_plms_drip_days` (days after the enrolment's
  `enrolled_at`) and `_plms_drip_date` (a UTC datetime). Every rule that applies to an item (its own and
  its section's) must pass, so the latest unlock time wins; the denial carries it as `available_at`.
  Prerequisites: a course lists courses (`_plms_prerequisites` on the course) that must have a completed
  enrolment (`completed_at` set) before the learner can enrol, and items of that course stay locked for
  learners enrolled by hand; an item lists lessons, quizzes or assignments of the same course that must be
  complete. Unpublished courses, items of other courses and self-references are ignored, so deleting a
  step never locks a course for good. `AccessDecision` gained `requires()` (the IDs still to complete),
  which `message()` names ("Complete “Lesson 3” first.") and `to_array()` returns with titles and URLs.
  Teachers and free previews pass before either rule (rules 1 and 3). REST: `drip_days`, `drip_date`
  (site timezone, like core's `date`) and `drip_date_gmt` on sections and items, `prerequisites` on items
  and courses, and `lock_reason` and `available_at` per item in the curriculum. The builder edits them in
  an "Access rules" dialog per section and item, and course prerequisites in course settings. Lesson,
  quiz and assignment routes share `Rest\AbstractItemController`.
- **Consequences.** Drip counts from the latest (re)enrolment, because `enrolled_at` restarts when a
  cancelled enrolment is reactivated (ADR-029). There is no "take items in order" switch; authors chain
  prerequisites instead. No job releases dripped items: they open when the next request finds the time
  has passed, and drip reminders (emails) are Pro. `AccessPolicy` takes the progress repository as an
  optional third constructor argument.

### ADR-041 Lesson video without provider scripts
- **Context.** The PRD wants YouTube, Vimeo, Bunny and self-hosted video with completion tracking, and
  forbids remote assets in free. Each provider's JavaScript SDK is a remote script.
- **Decision.** `publiclms/video` renders the provider's own iframe (youtube-nocookie.com, Vimeo with
  `dnt=1`, Bunny Stream's embed) or a native `<video>` with an optional WebVTT captions track, and reads
  progress from the players' postMessage events: YouTube's widget `listening`/`infoDelivery` messages,
  Vimeo's `timeupdate` event, Bunny's player.js `timeupdate`, or the element's `timeupdate`. The store
  counts each second actually played (a jump of more than two seconds adds nothing), and when the share
  reaches `requiredPercent` (default 90) on a lesson the learner is enrolled in, it posts the completion
  to `publiclms/v1/progress` like the mark-complete button. `Blocks\VideoSource` parses watch, share,
  embed and Shorts URLs and IDs. Lesson files (`_plms_attachments`, media library IDs) are listed by
  `publiclms/lesson-content` under the lesson for learners who can open it, and edited in a block editor
  panel or over `PATCH /lessons/{id}`.
- **Consequences.** Watch tracking is a learning aid, not proof: the server cannot verify playback, so it
  does not refuse a manual completion, and nothing gates the mark-complete button. YouTube's message
  protocol is the one its own iframe API uses but is not formally documented; if it changes, tracking
  stops silently and the lesson can still be completed by hand. Lesson files stay in the media library,
  so their URLs are public once known; private files are what assignment uploads are for (ADR-043).

### ADR-042 Quizzes: a reusable bank, attempts graded on the server
- **Context.** PRD §3 puts six question types, a question bank, timer, attempts, randomisation and a pass
  mark in free; ADR-016 requires server grading. The brief adds: never send correct answers to the browser,
  nonce in state, rate limit on submit, quizzes count toward progress.
- **Decision.** Questions are `plms_question` posts: the text is the post content (through
  `wp_kses_post`), and type, points, explanation and answer key sit together in the protected meta
  `_plms_question_data`, which is not registered for REST; the question post type is taken off `wp/v2`
  and out of wp-admin (`show_in_rest` and `show_ui` false), so the only way to a key is
  `publiclms/v1/questions`, which needs `edit_post` on the question. A quiz lists question IDs in order in
  `_plms_quiz_questions`, so one question serves many quizzes; settings live in `_plms_quiz_*` meta through
  `Quizzes\QuizSettings` (time limit, attempts, pass mark 70 by default, questions drawn per attempt,
  shuffle questions, shuffle answers, show results). Option and pair IDs are random strings, and prompt and
  match IDs are independent, so the learner's view (`QuestionBank::public_view()`) reveals no pairing.
  `Services\QuizService` starts or resumes an attempt (one in progress at a time), saves answers ungraded,
  and on submit grades every question with `Quizzes\Grader` (single, true/false and short answer all or
  nothing; multiple needs the exact set; fill blank and matching give partial credit; text compared without
  case or spacing). Results say right or wrong per question with the author's explanation, never the key.
  An attempt past its time limit plus 60 seconds is graded with the answers saved before the deadline. A
  passed attempt completes the quiz in progress; `POST /progress` refuses quizzes and assignments.
  `AccessPolicy::can_attempt_quiz()` adds the attempts limit (`attempts_limit`), which teachers skip.
  Starts are limited to 20 an hour per learner and quiz, submissions to 6 a minute per learner, answer
  saves to 120 a minute, through the reusable `Core\RateLimiter` (transients; `publiclms_rate_limit`
  filter). Hooks: `publiclms_quiz_graded`, `publiclms_quiz_passed`; events `quiz.started`, `quiz.graded`.
- **Front end.** `publiclms/quiz` renders the start panel on the server and the questions from the attempt
  response through `data-wp-each`: one fieldset per question, native radios, checkboxes, text inputs and
  selects, autosave, a timer that warns at five minutes and one and submits at zero, and focus moved to
  the result heading. Question HTML (already through `wp_kses_post`) is written with a `data-wp-watch`
  callback, because the Interactivity API has no HTML directive. The store module is split into
  `interactivity/api.js`, `quiz.js` and `learning.js`, still one script module. The template
  `single-plms_quiz` uses the new patterns `quiz-header` (course link, section, title and quiz facts through
  the new binding keys) and `quiz-runner` (the gated introduction and the runner).
- **Admin.** A quiz editor in the course builder (`?course={id}&quiz={id}`) holds the settings and the
  question list (new question, add from bank, reorder, remove), and a "Questions" screen lists the bank.
- **Consequences.** Quiz-to-question links are post meta holding IDs, so WordPress's XML exporter carries
  them with the old IDs (ADR-028's concern); course cloning and CSV import, both on the roadmap, remap
  them. Questions drawn per attempt are fixed at start in `question_ids`, so editing a quiz never changes
  an attempt in progress; editing a question's key does change how a later submission of an attempt in
  progress is graded. Pro's manually graded types use the attempt status `pending`.

### ADR-043 Assignments: private uploads, grading and a free gradebook
- **Context.** PRD §3 puts assignments with file upload and the gradebook in free (ADR-019). ARCHITECTURE
  §6 asks for uploads checked for type and size and stored outside web-executable paths; the brief adds
  that files are served only through a permission-checked endpoint, never by direct URL.
- **Decision.** Hand-ins are rows in the new `plms_assignment_submissions` table (schema 2): typed answer
  (through `wp_kses_post`), files as JSON, grade out of `max_grade`, pass flag, feedback; status `submitted`,
  `graded` or `replaced`. Settings sit on the assignment (`Assignments\AssignmentSettings`: points, pass
  grade, typed answers, files per hand-in, size, types, resubmit); the planned block attributes
  `allowedTypes` and `maxFileSize` were dropped because only the server can enforce limits. Without a pass
  grade, handing in completes the assignment; with one, a grade at or above it does. Before grading a learner
  may hand in again (the waiting hand-in becomes `replaced`); after a fail, again if allowed; after a pass,
  never. `Files\PrivateFiles` stores uploads in `{uploads}/publiclms-private/{random site token}/{Y}/{m}/`
  under random 32-character names with no extension, behind `.htaccess` and `web.config` deny rules and
  empty index files; `PUBLICLMS_PRIVATE_DIR` or `publiclms_private_dir` move it outside the web root. Each
  file is checked against the assignment's extensions (a subset of a fixed safe list with no HTML, SVG,
  scripts or executables), the size limit (capped by the server's), and its content with
  `wp_check_filetype_and_ext()`. Downloads go through `GET /submissions/{id}/files/{file}`, which checks
  the learner or the course's report viewers and streams the file through `Rest\FileResponse` (hooked to
  `rest_pre_serve_request`) with the stored type, an RFC 6266 file name, `nosniff` and `no-store`; the same
  class serves the gradebook CSV and certificate PDFs. Responses never contain storage paths. The
  gradebook (`Services\Gradebook`) shows learners by the course's quizzes (best graded attempt) and
  assignments (current hand-in), progress and the plain average of graded cells, paged by enrolment; the
  CSV holds the whole course and prefixes formula-like cells with an apostrophe. Admin: a settings dialog
  per assignment in the builder, a Submissions screen with a grading dialog, a Gradebook screen with CSV
  export. Template `single-plms_assignment` uses the new pattern `assignment-submission`. Hooks:
  `publiclms_assignment_submitted`, `publiclms_assignment_graded`; events `assignment.submitted`,
  `assignment.graded`. Hand-ins are limited to 10 an hour per learner and assignment.
- **Consequences.** On nginx the deny rules do nothing; the files stay unguessable and unexecutable but a
  leaked path would be readable, so the docs recommend `PUBLICLMS_PRIVATE_DIR` or an nginx `deny` rule for
  `publiclms-private`. Handing in needs JavaScript (multipart to REST). The gradebook reads each learner's
  progress summary, one cached query per learner per page. Replaced hand-ins keep their files until the
  learner's data is erased.

### ADR-044 Certificates with a prefixed Dompdf, and personal data tools (Dompdf superseded by ADR-052)
- **Context.** PRD §3: one block-built certificate template rendered to PDF in free; multiple templates
  and a public verification page are Pro. ADR-013 planned Dompdf behind PHP-Scoper. PRD §6 and ADR-001
  require GDPR export and erase for every learner table.
- **Decision (certificates).** Completing a course (`publiclms_course_completed`) issues one row in the new
  `plms_certificates` table (schema 3) with a random public key (`PLMS-XXXX-XXXX-XXXX`, no look-alike
  characters) and what the certificate says, fixed at issue; courses can switch certificates off
  (`_plms_certificate`). Learners who completed before certificates existed get theirs on first request.
  The template is one `plms_certificate` post (`publiclms_certificate_template`), created with a default of
  headings, paragraphs and a separator bound to the new `publiclms/certificate-field` source (learner name,
  course title, completion date, certificate ID, instructor, site name), and edited in the block editor
  with a "Preview PDF" panel. The PDF is rendered on download (no stored files): the template's blocks
  with the certificate bound in, a print stylesheet (A4 landscape, a double frame, DejaVu Serif and Sans,
  the theme's colour and font-size presets written as plain values), and Dompdf with remote loading, PHP
  and JavaScript off and a chroot of uploads and the plugin; media library images are read from disk.
  `AccessPolicy::can_view_certificate()` now allows the learner who completed the course and report
  viewers (with `user_id`). The dashboard gains a Certificates tab through new hooks
  (`publiclms_dashboard_tabs`, `publiclms_dashboard_rows`, `publiclms_dashboard_panel`), and
  `publiclms/certificate-button` joins the course enrol card. Hook: `publiclms_certificate_issued`.
- **Decision (prefixing).** Strauss instead of PHP-Scoper (the brief allows either): it rewrites only the
  listed packages and their dependencies, including namespace strings, and prefixes Composer's own class
  loader. Both Strauss and Dompdf live in `tools/` (Strauss needs PHP 8; the plugin's Composer platform is
  7.4), and `bin/prefix-vendor.sh` builds `vendor-prefixed/`, patches the two class names php-font-lib
  builds at run time, and drops the monospace fonts. `vendor-prefixed/` (9 MB, mostly fonts) is committed,
  so release zips, Playground and source checkouts get it without a build step; it is loaded only when a
  PDF is rendered.
- **Decision (privacy).** `Privacy\PersonalData` registers eight exporters (enrolments with course
  progress, step progress, quiz attempts with answers, hand-ins with file names, certificates, activity
  log, notifications, orders by user or billing email) and two erasers: learning records (rows and hand-in
  files) are deleted; orders and transactions are kept with name, email, IP, meta and gateway payloads
  removed, and the erasure report says so. Subscriptions of the user are deleted.
- **Consequences.** DejaVu covers Latin, Greek and Cyrillic but not Devanagari or other Indic scripts, so a
  Nepali learner name prints as boxes until a font is added through `publiclms_certificate_css` (open
  question for the owner). Dompdf ignores flexbox, so side-by-side columns in a template stack. Rendering
  takes about half a second per certificate; Pro's bulk downloads should queue them on Action Scheduler.

### ADR-045 Money: any ISO 4217 currency, minor units, formatted for the reader
- **Context.** The audience is global: no country or currency may be assumed. Phase 1 stored a currency
  option that defaulted to USD and formatted prices with an eight-symbol table, symbol first.
- **Decision.** `Commerce\Money` holds the active ISO 4217 codes (fund and precious-metal codes and
  withdrawn currencies left out) and their minor units: 0 (JPY, KRW, VND and others), 3 (KWD, BHD, JOD, OMR,
  TND, IQD, LYD), otherwise 2. The store currency (option `publiclms_currency`) has no default: until the
  owner picks one, prices show as plain numbers and checkout refuses with "no currency has been chosen".
  Amounts are rounded to the currency's minor units when priced, discounted and stored (DECIMAL(19,4)).
  `Money::format()` formats for the reader's locale (`determine_locale()`) with PHP's intl
  `NumberFormatter` (symbol position, separators, digits), dropping decimals for whole amounts as phase 1
  did ("$49", "49 €"). When intl is missing, or its ICU data lacks the locale (Alpine builds ship English
  only and silently fall back), a table takes over: `number_format_i18n()`, a symbol for common currencies
  or the code, and the symbol after the number for languages that write it there. Filters:
  `publiclms_format_price`, `publiclms_format_price_with_intl`. `CourseFields::format_price()` and the
  `price` binding use it; the settings sanitiser accepts any listed code and nothing else.
- **Consequences.** Gateways convert to their own minor units (ADR-048, ADR-049). Admin screens name
  currencies with the browser's `Intl.DisplayNames`, so the list needs no translations from the plugin.

### ADR-046 Orders, checkout and guest buyers
- **Context.** ADR-011 fixes native checkout with hosted or redirect flows; the orders tables have existed
  since schema 1. The free tier sells single courses (PRD §3).
- **Decision.** `Commerce\OrderService` owns every order change. Checkout prices the courses on the server
  (the sale price when lower; never a price from the request), checks each is published, paid and open to
  this buyer (already enrolled, prerequisites), applies a coupon, and creates the order (`pending`) with
  its lines. A zero total completes at once (`gateway` = `free`); otherwise the chosen gateway, which must
  be on, set up and take the currency, returns a redirect (or `on-hold` for Offline). Gateways report
  payment results (ADR-047); `apply()` records each payment or refund once by the provider's ID, checks
  amount and currency against the order (a mismatch puts it on hold with a note instead of enrolling), and
  moves the order with a compare-and-set on its status (`OrderRepository::transition()`), so a webhook and
  the buyer's return cannot both complete it. Completing enrols the buyer in each course with source
  `order` and the order ID; refunding in full cancels exactly those enrolments
  (`publiclms_revoke_access_on_refund`), partial refunds keep access. Statuses: pending, on-hold,
  completed (shown as "Paid"), failed, cancelled, refunded. History lines live in the order's meta (last
  100); events `order.created`, `order.completed`, `order.refunded`; hooks `publiclms_order_completed`,
  `publiclms_order_refunded`.
- **Decision (guests).** Guest checkout is on by default (`publiclms_guest_checkout`): the buyer gives a
  name (for certificates) and an email. The account is created only once the order is paid, with
  WordPress's own "set your password" email; an email that already has an account buys into it.
- **Decision (the page).** One page holds `publiclms/checkout` (option `publiclms_checkout_page_id`,
  created from Payments with the slug `course-checkout`, so it never takes WooCommerce's `/checkout/`). `?course=` sells, `?order={key}` shows the order to whoever holds the link (the
  key is 29 random characters), `?cancelled={key}` cancels a pending order the buyer abandoned at the
  provider. Provider returns are handled on `template_redirect` and redirected to the clean URL, so a
  reload never repeats a capture. Without JavaScript the form posts to `admin-post.php`
  (`publiclms_checkout`, nonce); errors come back through a five-minute transient, never the URL. The page
  sets `DONOTCACHEPAGE` and no-cache headers. Paid courses' `enroll_url` points here
  (`publiclms_purchase_url` lets the WooCommerce bridge point it at a product).
- **Decision (emails).** Plain-text emails for on-hold (with the offline instructions; the owner gets one
  too), completed (course links) and refunded, in the buyer's language (`switch_to_user_locale()`, or the
  language of the checkout page for guests). `publiclms_send_order_email` can stop any. The Community item
  moves notifications onto Action Scheduler later.
- **Consequences.** Checkout and quotes are rate-limited per user or address (10 and 30 per ten minutes,
  429 with Retry-After). The dashboard gains an Orders tab for learners who have orders. Abandoned pending
  orders stay pending until the buyer returns or a webhook says so; a clean-up job can come with
  notifications.

### ADR-047 Gateways: one interface, payment results, settings with configuration precedence
- **Decision.** `Commerce\Gateways\Gateway` defines what a gateway does: start a payment (redirect URL,
  provider reference, status), confirm on return, refund in full, verify and translate a webhook, and
  describe its settings. Gateways never touch orders: every method returns payment results
  (`paid`, `pending`, `failed`, `cancelled`, `refunded`) that `OrderService::apply()` applies. Free ships
  Offline (on-hold with the owner's instructions; marked paid by hand), Stripe and PayPal; add-ons append
  instances on `publiclms_gateways`. Settings live in `publiclms_gateway_{id}`; a field may name a
  constant, and a defined constant wins over the stored value and locks the field in the admin. Secret
  fields, and every text field set by a constant, are write-only over REST: responses carry `is_set` and
  `from_config`, never the value; a string replaces a stored secret, `false` removes it. HTTP goes through one helper with a 30-second timeout, no redirects and
  no logging of requests or answers. The webhook route is public; the gateway verifies the provider's
  signature on the raw body first.
- **Consequences.** The Payments screen (`publiclms_manage_orders`) shows each gateway's state, whether
  it takes the store currency, its mode and its webhook URL. Orders and Coupons are new screens for the
  same capability.

### ADR-048 Stripe: hosted Checkout Sessions on a pinned API version
- **Context.** ADR-011 names Stripe Payment Intents with a hosted or redirect flow. The owner's sandbox
  runs API version 2026-10-28.endive, which rejects `payment_method_types` ("payment methods are managed
  from your Dashboard").
- **Decision.** Each order becomes a Checkout Session in payment mode (Stripe creates the PaymentIntent),
  so card data never reaches the site and the account's Dashboard decides the payment methods: the request
  names none. Every request sends `Stripe-Version: 2026-10-28.endive`. Creating the session sends an
  `Idempotency-Key` made from the order key, so a retry never makes a second session; refunds send a
  fresh key per attempt (Stripe replays a stored failure for a repeated key, and it refuses to refund
  more than was paid, so a double click cannot refund twice). Lines carry the
  per-line totals after discount; the last line absorbs any rounding so the session total equals the
  order's. Amounts follow Stripe's rules: whole units for its zero-decimal list (JPY, KRW, VND, XOF…),
  ISK and UGX as two-decimal values ending in 00, three-decimal currencies in thousandths ending in 0
  (an order Stripe cannot represent exactly is refused with a clear message instead of being rounded).
  The session carries `client_reference_id` and metadata with the order key and number; `locale` is
  `auto`. On return (`session_id`) the site reads the session; webhooks (`checkout.session.completed`,
  `…async_payment_succeeded`, `…async_payment_failed`, `…expired`, `charge.refunded`, and `refund.created`
  or `refund.updated` where the account sends them) are verified with `Stripe-Signature`: HMAC-SHA256 over
  "{t}.{raw body}", any `v1` value, with a 300-second tolerance. `charge.refunded` lists the
  PaymentIntent's refunds, so refunds made in the Stripe Dashboard are recorded one by one; when they add
  up to the order total the order is refunded and its enrolments close. Settings: publishable key, secret
  key (a restricted key works), webhook signing secret, each overridable by
  `PUBLICLMS_STRIPE_PUBLISHABLE_KEY`, `PUBLICLMS_STRIPE_SECRET_KEY`, `PUBLICLMS_STRIPE_WEBHOOK_SECRET`;
  the mode (test or live) is read from the key's prefix. Filter: `publiclms_stripe_session_params`.
- **Consequences.** Stripe takes about 135 currencies and which ones depends on the account, so the
  gateway accepts any currency and Stripe's own error ("The currency … is not supported") reaches the
  buyer. Verified on the owner's sandbox (2026-11-05): a real session on the pinned version, with the
  account's own methods offered (card, Apple Pay, Bancontact, MB WAY, Pay by Bank, EPS); an expired
  session cancelling its order by webhook; a hosted payment with test card 4242 completing the order on
  return and by `checkout.session.completed` (applied once) and enrolling the buyer; and a refund from the
  Orders screen, with `refund.created`, `charge.refunded` and `refund.updated` all answered 200, the
  refund recorded once and the enrolment closed. The account does send `refund.*` events.

### ADR-049 PayPal: Orders v2, capture on return, webhooks verified by PayPal
- **Context.** ADR-011 names PayPal Orders v2. Many sites (and the owner's development machine) have no
  public HTTPS address, so PayPal cannot always reach a webhook.
- **Decision.** Each order becomes a PayPal order: intent `CAPTURE`, `custom_id` and `reference_id` = the
  order key, line items as `DIGITAL_GOODS` with a breakdown (`item_total`, `discount`), and an
  `experience_context` with `NO_SHIPPING`, `PAY_NOW`, the site name and the return and cancel URLs. The
  buyer is sent to the `payer-action` link. When they come back (`?token=` equal to the stored PayPal
  order ID) the site captures the order, so the return alone completes it without any webhook; a capture
  PayPal already made is read back (`ORDER_ALREADY_CAPTURED`), an order not yet approved stays pending, a
  `PENDING` capture waits with PayPal's reason in the history. Captures and refunds send a fresh
  `PayPal-Request-Id` per attempt, because PayPal replays a cached answer for a repeated ID (a capture
  tried before approval would otherwise keep failing after it); PayPal refuses a second capture or an
  over-refund itself. Refunds post an empty body to the capture's refund route (the whole amount).
  Webhooks need the webhook ID: the plugin sends PayPal's five transmission headers, the webhook ID and
  the event exactly as received (not re-encoded, which could change the signed text) to
  `/v1/notifications/verify-webhook-signature` and acts only on `SUCCESS`. Events used:
  `CHECKOUT.ORDER.APPROVED` (capture for a buyer who never came back), `PAYMENT.CAPTURE.COMPLETED`,
  `…PENDING`, `…DENIED`/`…DECLINED`, `…REFUNDED` (matched to the capture through its `up` link) and
  `…REVERSED`. The OAuth token is cached in a transient per client ID and mode until two minutes before
  it expires, and refreshed once on a 401. Currencies: PayPal's 24; HUF, JPY and TWD without decimals (an
  order PayPal cannot represent is refused with a clear message). Settings: sandbox switch (on by
  default), client ID, secret, webhook ID, each overridable by `PUBLICLMS_PAYPAL_SANDBOX`,
  `PUBLICLMS_PAYPAL_CLIENT_ID`, `PUBLICLMS_PAYPAL_CLIENT_SECRET`, `PUBLICLMS_PAYPAL_WEBHOOK_ID`. Filters:
  `publiclms_paypal_order_body`, `publiclms_paypal_currencies`.
- **Consequences.** Refunds made in the PayPal account reach the site only through the webhook. Verified
  on the sandbox: a token and a real order through the plugin, and a capture attempted before approval
  leaving the order pending. Still owed: the owner's approval of a sandbox order in a browser, and a
  live webhook once a public URL exists.

### ADR-050 Coupons as a post type, uses counted from orders
- **Decision.** A coupon is a `plms_coupon` post (orders capability profile, not public, not in
  `wp/v2`): the title is the code as typed, `_plms_coupon_code` the lookup form (trimmed, lower-cased with
  `mb_strtolower`, so codes in any script match without regard to case). Published coupons are active,
  drafts paused. Meta: type (`percent` or `fixed`, the latter in the store currency), amount, courses
  (empty for all), first and last day (site time, inclusive), uses in all and per buyer. Uses are counted
  from orders that are paid, on hold or pending, so an unpaid order holds its use; per-buyer limits match
  the user or the billing email. Percentages round per line to the currency's minor units; fixed amounts
  spread over eligible lines in proportion, with the last line taking the remainder, and never take a line
  below zero. A 100% coupon completes the order without a gateway. Managed under Public LMS → Coupons and
  `publiclms/v1/coupons`; quotes are rate-limited against guessing.
- **Consequences.** Two buyers racing for the last use of a limited coupon can both get it; the limit is a
  business rule, not a lock. Deleting a coupon leaves the code on the orders that used it.

### ADR-051 The WooCommerce bridge
- **Context.** ADR-011: a WooCommerce bridge is free, for sites that already sell with WooCommerce.
- **Decision.** Off by default; switched on in Payments when WooCommerce is active
  (`publiclms_woocommerce_bridge`). A product's General tab gets a "Public LMS courses" field
  (`_publiclms_courses` on the product, `_plms_wc_product` on each course). When an order reaches
  processing or completed, the bridge enrols the customer in the products' courses with source
  `woocommerce` and the WooCommerce order ID, once per order (order meta `_publiclms_enrolled`), skipping
  courses the customer already has from elsewhere; guest orders get an account the same way native guest
  orders do. Refunded or cancelled orders close exactly the enrolments they gave. Linked paid courses point
  their "Buy" buttons at the cart with the product added (`publiclms_purchase_url`), show WooCommerce's
  price (`publiclms_course_price_label`), and the native checkout refuses them
  (`publiclms_course_purchasable`). Orders are read only through WooCommerce's order objects, and the
  plugin declares compatibility with WooCommerce's order tables (HPOS).
  Saving a product that gives courses makes it "sold individually", so the cart has no quantity field and
  refuses a second copy.
- **Consequences.** Partial WooCommerce refunds keep access, like native ones. Verified against
  WooCommerce 11.2.1 on the dev site (2026-11-05), with both order storages (HPOS and posts): the product
  field links and unlinks; a linked course's Buy button adds the product to the cart and shows
  WooCommerce's price, and the native checkout refuses it; processing enrols once (completed adds
  nothing), refunded and cancelled close the access, a guest order gets its account only once paid, and
  access from elsewhere survives a WooCommerce refund; a real purchase in the browser (Buy button, cart,
  block checkout, cash on delivery) enrolled the buyer and noted it on the order. Public LMS is listed as
  compatible with the order tables.


### ADR-052 Certificates with a patched mPDF and fonts for every script (supersedes the Dompdf part of ADR-044)
- **Context.** The audience is global, so a certificate must print any learner's name. Dompdf (ADR-044) has
  no OpenType shaping or bidi: Arabic letters print unjoined, Hebrew and Arabic print left to right, and
  Indic conjuncts and vowel signs print wrong, whatever font is added. mPDF 8.3.1 shapes with each
  font's OpenType tables and runs the Unicode bidi algorithm, and supports PHP 7.4 to 8.5. The owner
  approved mPDF and six Noto Sans families (Arabic, Hebrew, Devanagari, Bengali, Tamil, Thai) within
  2 MB zipped, without a Dompdf fallback (fix plan O1–O3). The spike found that mPDF's font reader
  rejects five of the six current Noto builds, and the owner chose to patch mPDF and keep Noto.
- **Decision (engine).** `CertificateRenderer` renders the same block template and print stylesheet with
  mPDF 8.3.1, prefixed by Strauss under `PublicLMS\Vendor` like Dompdf was. Dompdf is removed.
  `bin/prefix-vendor.sh` runs Strauss (with a 1 GB memory limit, for mPDF's 27,000-line main class), then
  `bin/patch-mpdf.php`, then trims mPDF's fonts to DejaVu Sans and Serif, regular and bold, and drops its
  index collations. The patch script applies 12 changes, and each must match exactly once, so an mPDF
  update cannot drop one silently:
  - GSUB lookup type 5 format 3 (coverage-based context) is read and applied as 6.3 without context.
  - GDEF mark filtering sets are read at their real offset and stored as the marks to ignore. The
    "no set" check is strict, because set 0 == '' on PHP 7.
  - Nested contextual lookups and contextual `locl` rules no longer crash the reader (a Bengali
    TypeError on PHP 8).
  - Arabic joining forms that are one-to-many substitutions are inserted as several glyphs. Noto draws
    Farsi yeh and kaf as a dotless body plus a dots mark, which mPDF had read as one code point, leaving a
    blank.
  - WebP images on a server without GD are left out; before, that one unguarded GD call was a fatal
    error for the whole certificate.
  mPDF gets no network: its HTTP client refuses every request (`Certificates\Pdf\NoNetwork`). Its file
  reader opens only files under the uploads folder and the plugin (`Certificates\Pdf\LocalFiles`), as
  Dompdf's chroot did. Font caches live in a per-site, per-version folder in the temp dir, so a new
  version re-reads fonts with its patches. A4 landscape. The `<html>` element has `dir` but no `lang`,
  because mPDF would give the whole page that language's font, and a font for one script often lacks
  Latin. `publiclms_certificate_pdf_options` now filters mPDF's configuration array.
- **Decision (fonts).** `CertificateFonts` reads each font's character map (`FontCoverage`, which accepts
  TrueType outlines only: no CFF OpenType, collections or WOFF). For every script in the text, it picks
  the first font that has a glyph for each of that script's characters, in this order:
  1. the bundled fonts: the script's Noto Sans (`assets/fonts/noto/`, 12 unhinted TTFs and their OFL
     licences, 0.49 MB zipped), then DejaVu Sans, which also covers Latin, Greek, Cyrillic, Armenian and
     Georgian;
  2. the Font Library's faces stored as TTF, or OTF with TrueType outlines (`wp_font_face`; WOFF and WOFF2
     cannot be embedded);
  3. the `publiclms_certificate_fonts` filter, which receives the Font Library list and can append or
     prepend.
  mPDF tags each run of non-Latin text with a language (`autoScriptToLang`, with its Arabic detection of
  Persian, Urdu, Pashto and Sindhi). `Certificates\Pdf\LanguageToFont` answers each tag with the font
  chosen for that script, matching case-insensitively because mPDF lowercases tags. Chinese, Japanese and
  Korean with no site font fall back to mPDF's Adobe CJK fonts, which embed nothing and are drawn by the
  reader's PDF viewer: Han is drawn as Japanese when the text has kana, otherwise as Chinese.
- **Decision (reporting).** `CertificateHealth` adds a Site Health test and the Certificate screen's
  notices:
  - **Critical:** the library or mbstring is missing.
  - **Recommended:** GD is missing. Text still prints, but some images are left out: PNG with
    transparency, GIF and WebP.
  - **Recommended:** a script with no font, named (for example "Sinhala"), with how to add a font for it.
    The scan covers the names and titles certificates print, or will print: issued certificates, enrolled
    learners' display names, published course titles, the site name and the template.
  - **Noted, still good:** scripts left to the viewer's CJK fonts.
- **Consequences.**
  - Every seeded name renders correctly as a PDF: Arabic, Devanagari, Chinese, Japanese, Korean,
    Cyrillic and Latin.
  - The spike matrix renders correctly in regular and bold for 17 rows: Latin, Cyrillic, Greek, Arabic,
    Persian, Urdu, Hebrew, Devanagari, Bengali, Tamil, Thai, CJK and a mixed bidi sentence.
  - A certificate takes 0.02–0.25 s once fonts are cached, and the first use of a font builds its cache.
  - Fonts in the zip are 1.6 MB zipped (Noto 0.49 MB, DejaVu 1.12 MB).
  - mPDF is GPL-2.0-only, so a copy distributed with it is under GPL 2.0, which the plugin's
    "GPLv2 or later" allows. Noto is OFL 1.1, and DejaVu uses the Bitstream Vera licence; both licence
    files ship.
  - CJK names print in the viewer's regular weight, not bold, unless the site adds a font.
  - The patches are ours to maintain. A new mPDF release needs the patch script to apply cleanly and the
    spike matrix re-rendered.
