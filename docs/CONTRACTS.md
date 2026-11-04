# Public LMS ↔ theme contracts

**Contract version:** 1.3.0 (phase 2; additive changes only, listed under "Changes" below)
**Applies to:** Public LMS ≥ 0.1.0 and any block theme that styles it, CampusInterface first.
**Change rule:** a change to either contract is a pull request on both repositories, and bumps this version:
removing or renaming anything is a major bump; adding a slug, pattern, key or token is a minor bump; wording
fixes are a patch. The plugin lands contract changes two to three weeks before the theme consumes them.
**Changes before the gate:** 2026-11-04, template slugs switched from `single-course` and friends to the
post type keys and `plms-` page templates (ADR-023). Nothing had shipped, so the version stayed 1.0.0.

**Changes in 1.3.0 (phase 2 part A, minor):**
1. A1: `single-plms_quiz` is registered (quiz-header, quiz-runner, plus the lesson template's navigation and
   sidebar patterns, in the same columns as `single-plms_lesson`).
2. A6: patterns `publiclms/quiz-header` and `publiclms/quiz-runner` are registered. `quiz-runner` holds the
   gated quiz introduction (`publiclms/lesson-content`) and `publiclms/quiz` with `showFacts:false`, because
   the header shows the facts.
3. A7: four keys, resolved from the quiz on screen and empty elsewhere: `quiz_question_count` ("6
   questions"), `quiz_time_limit` ("Time limit: 15 minutes" or "No time limit"), `quiz_pass_mark` ("Pass mark:
   70%"), `quiz_attempts_left` ("Attempts left: 2 of 3" or "Unlimited attempts").
4. A6: `publiclms/lesson-content` lists the lesson's files under the content ("Lesson files", for learners
   who can open the lesson); block attribute `showAttachments` turns it off.
5. A6: `publiclms/student-dashboard` gains the tab `quiz-results` (a table: quiz, course, best score, result,
   attempts, last attempt). The block's default tabs are now in-progress, completed, quiz-results and
   certificates; a saved block with its own `tabs` keeps them.
6. Two new blocks for themes to style: `publiclms/video` (YouTube, Vimeo, Bunny Stream, self-hosted) and `publiclms/quiz`.
   They read only B1 to B4 tokens; the quiz uses `success` and `danger` for results and `3xl` for the score.
7. A6: curriculum items carry `is-type-lesson`, `is-type-quiz` or `is-type-assignment`, and dripped items
   show "Opens {date}"; section counts read "3 lessons, 1 quiz".
8. A1: `single-plms_assignment` is registered (Single Assignment: lesson-header, assignment-submission,
   lesson-navigation, lesson-sidebar), following the naming rule of ADR-023.
9. A6: pattern `publiclms/assignment-submission`: the brief (`publiclms/lesson-content`) and
   `publiclms/assignment` (hand-in form, status, grade and feedback). B1: the block uses `warning` for
   "Waiting for a grade", and `success` and `danger` for the grade.

**Changes in 1.2.0 (phase 1, minor), answering the theme's nine open items (theme `docs/CONTRACTS.md`,
"Open items for the plugin"):**
1. Course link on lessons and quizzes: already covered by the 1.1.0 keys `course_title` and `course_url`,
   which resolve from a lesson's (and from phase 2 a quiz's) context. No further change.
2. Related courses: added `"query":{"publiclmsRelated":true}` on `publiclms/course-query` (A5.5).
3. Archive filters: a `publiclms/course-filters` block is phase 2 (A6). Until it ships the plugin never
   injects anything into a theme's filter column; the theme keeps its own Search and Terms Lists.
4. Form fields: plugin block CSS now sets `background-color`, `color` and `border-color` of inputs, selects
   and textareas from `--publiclms-color-base` and `--publiclms-color-contrast`, at zero specificity (B5).
5. Font-size references: B2 now says `theme.json` and block style references must write `2-xl`, `3-xl`,
   `4-xl` (`var:preset|font-size|2-xl`). The plugin ships no `styles.blocks` defaults and no reference to
   these slugs, so nothing in the plugin needed fixing.
6. Variation detection: the variation's `isActive` matches on `namespace` (it has since phase 1 commit
   9b29002); now stated in A5.5.
7. Pattern font sizes: plugin patterns use only B2 slugs (`sm`, `lg`); core's `small`, `medium` and `large`
   are gone. Zero-specificity fallbacks keep the sizes on themes without these presets (B5).
8. Pattern wrappers: patterns meant for columns and sidebars use `"layout":{"type":"default"}` (A5.7).
9. Phase 2 patterns: `publiclms/course-reviews`, `quiz-header`, `quiz-runner` and `instructor-dashboard`
   are not registered in phase 1, so a reference renders nothing; themes should leave those areas out or
   hide them until the plugin version that ships them (A6).

**Changes in 1.1.0 (phase 1, minor):**
- A7: four binding keys added: `course_title`, `course_url`, `section_title`, `updated`. An empty value now
  leaves the bound block's own text in place instead of printing an empty element.
- A1 and A6: phase notes. Phase 1 ships four templates and twelve patterns; `single-plms_quiz`,
  `plms-instructor` and the quiz, review and instructor-dashboard patterns arrive with their blocks in
  phase 2, so the plugin's `single-plms_course` leaves out `publiclms/course-reviews` until then.
- A6: `publiclms/lesson-content` uses the gated `publiclms/lesson-content` block rather than core Post
  Content; `publiclms/course-details` binds "last updated" to `updated`; `publiclms/lesson-header` binds the
  course link and section name. Patterns use helper classes (`publiclms-*`) instead of inline styles.
- B5: the alias selector also covers the patterns' helper classes, `:where([class*="publiclms-"])`, and the
  aliases load as the `publiclms-tokens` stylesheet with every plugin block and on every plugin page.
- Nothing was removed or renamed; slugs and keys from 1.0.0 are unchanged.

There are two contracts, as the roadmap calls for:

- **A. Templates and patterns.** Which templates the plugin registers, which patterns each is built from,
  which binding keys patterns can use, and how a theme overrides them.
- **B. Tokens.** Which `theme.json` presets and custom properties the plugin's blocks read, and what the
  plugin falls back to when a theme does not define them.

The theme's side of these contracts is in `themes/campusinterface/docs/CONTRACTS.md`.

---

## A. Template and pattern contract

### A1. Templates the plugin registers

The plugin registers seven block templates with `register_block_template()` (WordPress 6.7+); the seventh,
`single-plms_assignment`, was added in 1.3.0. The template ID
is `publiclms//{slug}`; the slug is what a theme matches. Slugs follow the post type keys, or carry the
`plms-` prefix for the two page templates, so they can never match another plugin's post types or an
ordinary page (ADR-023).

| Template ID | Slug | Title | Applies to | Built from patterns |
|---|---|---|---|---|
| `publiclms//single-plms_course` | `single-plms_course` | Single Course | A single `plms_course` | `publiclms/course-hero`, `publiclms/course-details`, `publiclms/course-curriculum`, `publiclms/course-instructor`, `publiclms/course-reviews`, `publiclms/course-enroll-card` |
| `publiclms//archive-plms_course` | `archive-plms_course` | Course Archive | The `plms_course` archive (`/courses/`) and the `plms_course_category`, `plms_course_tag` and `plms_level` term archives | `publiclms/course-archive-header`, `publiclms/course-grid` |
| `publiclms//single-plms_lesson` | `single-plms_lesson` | Single Lesson | A single `plms_lesson` | `publiclms/lesson-header`, `publiclms/lesson-content`, `publiclms/lesson-navigation`, `publiclms/lesson-sidebar` |
| `publiclms//single-plms_quiz` | `single-plms_quiz` | Single Quiz | A single `plms_quiz` | `publiclms/quiz-header`, `publiclms/quiz-runner`, `publiclms/lesson-navigation`, `publiclms/lesson-sidebar` |
| `publiclms//single-plms_assignment` | `single-plms_assignment` | Single Assignment | A single `plms_assignment` (1.3.0) | `publiclms/lesson-header`, `publiclms/assignment-submission`, `publiclms/lesson-navigation`, `publiclms/lesson-sidebar` |
| `publiclms//plms-dashboard` | `plms-dashboard` | Student Dashboard | The page saved in option `publiclms_dashboard_page_id`, or any page whose editor picks this template | `publiclms/student-dashboard` |
| `publiclms//plms-instructor` | `plms-instructor` | Instructor Dashboard | The page saved in option `publiclms_instructor_page_id`, or any page whose editor picks this template | `publiclms/instructor-dashboard` |

No other templates are registered. There is no separate template for course term archives: they use
`archive-plms_course` (see A2).

**Phase availability.** Phase 1 registers `single-plms_course`, `archive-plms_course`, `single-plms_lesson`
and `plms-dashboard`. Phase 2 part A adds `single-plms_quiz` (1.3.0); `plms-instructor` follows with the
instructor dashboard in part B. The plugin
registers a slug only when its template file ships, so a theme may already ship all six.

`plms-dashboard` and `plms-instructor` are registered with `post_types: [ "page" ]`, so they appear in the
page template picker as "Student Dashboard" and "Instructor Dashboard".

### A2. How the slugs reach the template hierarchy

The four single and archive slugs are exactly the names WordPress looks up for the plugin's post types
(`single-{post_type}`, `archive-{post_type}`), so they need no help. They are also the names the Site Editor
creates when a site owner picks "Add template → Single item: Course", so the plugin's template, a theme's
override and the owner's own edits are always the same template. For a course the order of precedence is
WordPress's own:

```
single-plms_course-{post-name}  →  single-plms_course  →  single  →  singular  →  index
```

The plugin adds to the hierarchy in two places only:

| Request | Filter | Candidate added | Position |
|---|---|---|---|
| Course category, tag or level archive | `taxonomy_template_hierarchy` | `archive-plms_course` | After `taxonomy-{taxonomy}`, so a template for the taxonomy itself still wins |
| The page in `publiclms_dashboard_page_id` | `page_template_hierarchy` | `plms-dashboard` | First, or after a template the editor picked for that page |
| The page in `publiclms_instructor_page_id` | `page_template_hierarchy` | `plms-instructor` | First, or after a template the editor picked for that page |

Because the page template slugs do not start with `page-`, WordPress never applies them to a page just
because its slug is "dashboard" or "instructor".

### A3. Override rule

1. **Same slug wins.** A theme overrides a plugin template by shipping `templates/{slug}.html`, for example
   `templates/single-plms_course.html`. WordPress gives theme template files precedence over
   plugin-registered templates with the same slug; users can still edit either one in the Site Editor, and
   their edits win over both.
2. **Page templates stay pickable.** A theme that ships `templates/plms-dashboard.html` or
   `templates/plms-instructor.html` also lists it in `customTemplates` in its `theme.json`, with
   `"postTypes": [ "page" ]` and the titles above, so it remains available in the page template picker.
3. **Never the other way.** The plugin never overrides a theme template, and never registers a slug that is
   not in A1. Templates added in later versions follow the same naming rule: a post type key, or the
   `plms-` prefix.
4. **Partial override is fine.** A theme may override `single-plms_course` and leave the other five to the
   plugin.

### A4. What templates may reference

Plugin templates (the defaults) reference only:

- the template parts `header` and `footer`, by slug and without a `theme` attribute, so they resolve to the
  active theme's parts (`<!-- wp:template-part {"slug":"header","tagName":"header"} /-->`);
- core blocks, `publiclms/*` blocks and `publiclms/*` patterns.

Theme overrides may reference:

- any of the theme's own template parts (for CampusInterface: `header`, `footer`, and from phase 1
  `lesson-nav` and `sidebar`);
- core blocks, `publiclms/*` blocks, `publiclms/*` patterns and the theme's own patterns (`campusinterface/*`).

Templates reference patterns by slug (`<!-- wp:pattern {"slug":"publiclms/course-hero"} /-->`), because
templates cannot hold PHP and patterns can (for translation and asset URLs).

### A5. Pattern rules

1. Plugin patterns live in the plugin's `patterns/` folder, are registered from file headers, and use the
   `publiclms/` slug namespace and the `publiclms` pattern category.
2. A theme never registers a pattern in the `publiclms/` namespace and never re-registers a plugin pattern
   slug. To change a section, the theme overrides the template and composes its own `campusinterface/*`
   pattern in place of the plugin pattern.
3. Every attribute a user should edit inside a pattern carries `"role": "content"` in the plugin's
   `block.json`, because WordPress 7.0 opens patterns in content-only editing.
4. Course cards are ordinary Post Template, Post Title, Post Featured Image and Post Excerpt blocks inside a
   core Query Loop over `plms_course`, with course data from the bindings source in A7. There is no
   `publiclms/course-card` block.
5. From phase 1 the plugin registers a Query Loop variation, `publiclms/course-query`. Patterns that want
   its extra controls add `"namespace":"publiclms/course-query"` to the Query block's attributes; patterns
   without it still work as a plain query. The variation's `isActive` matches on `namespace` alone, so an
   inherited archive query and any theme grid that sets the namespace keep the controls in the editor.
   Query settings the plugin reads (all optional, inside `"query"`):

   | Setting | Since | Effect |
   |---|---|---|
   | `publiclmsHideEnrolled` | 1.0.0 | Leaves out courses the current learner is enrolled in |
   | `publiclmsRelated` | 1.2.0 | On a course (or a lesson of a course), leaves that course out and lists courses sharing its `plms_course_category` terms first, newest first, then fills the row with the newest other courses; `perPage` sets the row length. Ignored where no course is on screen |

   Level and category filtering use core's taxonomy controls (`taxQuery`).
6. **No guard code.** Template slugs never match another plugin's post types or an ordinary page, so a
   theme ships its overrides without filtering the template hierarchy.
7. **Patterns fit their slot (1.2.0).** Patterns meant for template columns and sidebars
   (`course-details`, `course-curriculum`, `course-instructor`, `course-enroll-card`, `lesson-content`,
   `lesson-navigation`, `lesson-sidebar`) wrap their content in a Group with `"layout":{"type":"default"}`,
   so they fill the column instead of centring at the content width. Full-width patterns (`course-hero`,
   `course-archive-header`, `course-grid`, `student-dashboard`) keep a constrained layout; `course-hero` and
   `lesson-header` justify it left.
8. **Font sizes (1.2.0).** Plugin patterns set font sizes only with the B2 slugs (`"fontSize":"sm"`,
   `"lg"` and so on), never core's `small`, `medium` or `large`.

### A6. Pattern inventory

| Pattern slug | Used by | Contents |
|---|---|---|
| `publiclms/course-hero` | single-plms_course | Course title, excerpt, featured image (`fetchpriority="high"`), level and instructor via bindings, primary enrol button |
| `publiclms/course-details` | single-plms_course | Duration, lesson count, level, last updated: Paragraph blocks bound to `publiclms/course-field` (`duration`, `lesson_count`, `level`, `updated`) |
| `publiclms/course-curriculum` | single-plms_course, lesson-sidebar | `publiclms/curriculum` block (sections and items, lock and completion states) |
| `publiclms/course-instructor` | single-plms_course | Instructor avatar, name and bio |
| `publiclms/course-reviews` | single-plms_course | `publiclms/course-reviews` block (phase 2; not registered in phase 1) |
| `publiclms/course-enroll-card` | single-plms_course | Price and `publiclms/enroll-button`, `publiclms/course-progress`, "this course includes" (`publiclms/course-meta`); sticky |
| `publiclms/course-archive-header` | archive-plms_course | Archive title and description |
| `publiclms/course-grid` | archive-plms_course | Query Loop over `plms_course` (`inherit: true` on archives), Post Template grid of course cards, pagination, no-results message |
| `publiclms/lesson-header` | single-plms_lesson | Course link (Button bound to `course_title` and `course_url`), section name (`section_title`), lesson title, `publiclms/course-progress` |
| `publiclms/lesson-content` | single-plms_lesson | `publiclms/lesson-content` block (the gated lesson; attachments join in phase 2), `publiclms/mark-complete` |
| `publiclms/lesson-navigation` | single-plms_lesson | Previous and next item links (`publiclms/lesson-navigation` block) |
| `publiclms/lesson-sidebar` | single-plms_lesson | Collapsible curriculum for the current course |
| `publiclms/quiz-header` | single-plms_quiz | Course link, section name, quiz title, and question count, time limit, pass mark and attempts left (A7 quiz keys); course progress (1.3.0) |
| `publiclms/quiz-runner` | single-plms_quiz | The quiz introduction (`publiclms/lesson-content`) and `publiclms/quiz` (Interactivity API runner, server-graded) (1.3.0) |
| `publiclms/assignment-submission` | single-plms_assignment | The assignment brief (`publiclms/lesson-content`) and `publiclms/assignment`: hand-in form, status, grade and feedback (1.3.0) |
| `publiclms/student-dashboard` | plms-dashboard | `publiclms/student-dashboard` block; phase 1 tabs: in progress, completed; quiz results, certificates and orders join in phase 2 |
| `publiclms/instructor-dashboard` | plms-instructor | `publiclms/instructor-dashboard` block: own courses, learners, submissions, reports (phase 2) |

**Not registered yet (part B of phase 2):** `publiclms/course-reviews` and `publiclms/instructor-dashboard`. A template that references them renders nothing in their place until
the plugin version that ships them, so a theme should leave those areas out of its templates or hide them
for now. The same goes for the planned `publiclms/course-filters` block (level, price, duration filters for a
`publiclms/course-query`, see ARCHITECTURE §5): it arrives in phase 2, the plugin never injects into a theme's
own filter column, and themes keep their own filters until then.

### A7. Block bindings source

Source name: **`publiclms/course-field`** (registered in PHP and JS from phase 1). Arguments: `{ "key": "<key>" }`,
plus an optional `"courseId"` when the block is outside a course context. Inside a Query Loop or on a course,
lesson or quiz template the course is taken from context.

| Key | Value | Bind to |
|---|---|---|
| `price` | Formatted price, or "Free" | Paragraph `content`, Heading `content`, Button `text` |
| `regular_price` | Formatted price before a sale, or empty | Paragraph `content` |
| `duration` | Human duration, e.g. "6h 30m" | Paragraph `content` |
| `lesson_count` | e.g. "24 lessons" | Paragraph `content` |
| `level` | Name of the `plms_level` term | Paragraph `content` |
| `instructor` | Instructor display name | Paragraph `content`, Heading `content` |
| `instructor_url` | Instructor profile URL | Button `url` |
| `instructor_avatar` | Avatar image URL | Image `url` |
| `enrolled_count` | e.g. "1,204 learners" | Paragraph `content` |
| `rating` | Average rating, e.g. "4.8" | Paragraph `content` |
| `enroll_url` | Enrol, checkout or continue URL for the current user | Button `url` |
| `enroll_label` | "Enrol now", "Buy for $49" or "Continue learning" | Button `text` |
| `progress` | Current user's progress, e.g. "45%" | Paragraph `content` |
| `course_title` | Course title (1.1.0) | Paragraph `content`, Heading `content`, Button `text` |
| `course_url` | Course permalink (1.1.0) | Button `url` |
| `section_title` | Title of the section the lesson is in, or empty on a course (1.1.0) | Paragraph `content` |
| `updated` | Course last-modified date in the site's date format (1.1.0) | Paragraph `content` |
| `quiz_question_count` | Questions an attempt shows, e.g. "6 questions"; empty off quizzes (1.3.0) | Paragraph `content` |
| `quiz_time_limit` | "Time limit: 15 minutes" or "No time limit" (1.3.0) | Paragraph `content` |
| `quiz_pass_mark` | "Pass mark: 70%" (1.3.0) | Paragraph `content` |
| `quiz_attempts_left` | "Attempts left: 2 of 3" for the current user, or "Unlimited attempts" (1.3.0) | Paragraph `content` |

Values are escaped by the source: text with `esc_html()` (core inserts bound rich text as HTML), URLs with
`esc_url_raw()` (core escapes attribute values itself, so a second encoding would break query strings). An
empty value returns nothing, so the block keeps its own text, such as a pattern's "—". Keys are only ever
added; removing or renaming one is a major bump.

---

## B. Token contract

The plugin's blocks style themselves from the theme's `theme.json` presets through CSS custom properties,
so a theme restyles them without plugin CSS overrides. Every value has a fallback for themes that lack the
preset, chosen to pass WCAG 2.2 AA on a white page.

### B1. Colour roles

Generated by WordPress from `settings.color.palette` as `--wp--preset--color--{slug}`.

| Slug | Role in plugin blocks | Text partner (AA 4.5:1 required) | Fallback |
|---|---|---|---|
| `base` | Page and card background | `contrast` | `#ffffff` |
| `contrast` | Body text, icons | on `base`, `surface`, `primary-soft`, `accent` | `#1e1e1e` |
| `primary` | Buttons, links, progress fill, focus ring | `base` on `primary`; `primary` on `base`, `surface` and `primary-soft` | `#3858e9` |
| `primary-soft` | Selected tab, active curriculum item, soft badges | `contrast` or `primary` | `#eff2fd` |
| `secondary` | Completion ticks, "completed" badges | `base` on `secondary`; `secondary` on `base` | `#0f766e` |
| `accent` | Highlight badges ("Bestseller", "New") | `contrast` | `#f0b849` |
| `surface` | Card and panel backgrounds, table stripes | `contrast` | `#f6f7f7` |
| `line` | Dividers and card borders (decorative only; form controls use `contrast` borders) | none | `#dcdcde` |
| `success` | Passed quiz, paid order | `success` on `base` and `surface` | `#007017` |
| `warning` | Pending review, expiring access | `warning` on `base` | `#996800` |
| `danger` | Failed quiz, payment failed, destructive buttons | `danger` on `base` and `surface` | `#cc1818` |

The pairs in the "Text partner" column are the ones the plugin uses. A theme palette (and each colour style
variation) must meet 4.5:1 on all of them; CampusInterface's three palettes are checked in its CI.

### B2. Font sizes

Generated from `settings.typography.fontSizes` as `--wp--preset--font-size--{slug}`. WordPress kebab-cases
slugs at letter-number boundaries, so `2xl` becomes `2-xl`.

| Slug | CSS custom property | Used by plugin for | Fallback |
|---|---|---|---|
| `xs` | `--wp--preset--font-size--xs` | Badges, meta labels | `0.75rem` |
| `sm` | `--wp--preset--font-size--sm` | Curriculum items, table cells, helper text | `0.875rem` |
| `base` | `--wp--preset--font-size--base` | Quiz answers, dashboard body | `1rem` |
| `lg` | `--wp--preset--font-size--lg` | Quiz question text, card titles | `1.125rem` |
| `xl` | `--wp--preset--font-size--xl` | Section headings in the curriculum | `1.25rem` |
| `2xl` | `--wp--preset--font-size--2-xl` | Dashboard headings, price on enrol card | `1.5rem` |
| `3xl` | `--wp--preset--font-size--3-xl` | Quiz result score | `2rem` |
| `4xl` | `--wp--preset--font-size--4-xl` | Not used by plugin blocks (theme hero only) | `2.75rem` |

Plugin blocks never set `font-family`; they inherit the theme's body and heading fonts.

**Writing the slugs in `theme.json` (1.2.0).** WordPress kebab-cases the slug only when it generates the
custom property and the class (`has-2-xl-font-size`). A preset reference in `theme.json` or in a block's
style attribute is not converted, so it must already use the kebab-cased form:
`var:preset|font-size|2-xl`, `3-xl` and `4-xl` (not `2xl`, which resolves to the undefined
`--wp--preset--font-size--2xl`). The same applies to any `styles.blocks["publiclms/*"]` value; the plugin
itself ships no such defaults.

### B3. Spacing

Generated by `settings.spacing.spacingScale` (7 steps, ×1.5, medium 1.5rem) as `--wp--preset--spacing--{slug}`.

| Slug | Size in CampusInterface | Used by plugin for | Fallback |
|---|---|---|---|
| `20` | 0.44rem | Icon gaps, badge padding | `0.44rem` |
| `30` | 0.67rem | Curriculum row padding | `0.67rem` |
| `40` | 1rem | Card padding (small screens), list gaps | `1rem` |
| `50` | 1.5rem | Card padding, block gap inside dashboards | `1.5rem` |
| `60` | 2.25rem | Gap between dashboard panels | `2.25rem` |
| `70` | 3.38rem | Not used by plugin blocks | `3.38rem` |
| `80` | 5.06rem | Not used by plugin blocks | `5.06rem` |

### B4. Custom properties

Generated from `settings.custom` as `--wp--custom--{path}`.

| Property | Used by plugin for | Fallback |
|---|---|---|
| `--wp--custom--radius--sm` | Badges, inputs, progress bar | `4px` |
| `--wp--custom--radius--md` | Cards, quiz answer options, buttons inside blocks | `8px` |
| `--wp--custom--radius--lg` | Enrol card, dashboard panels | `16px` |
| `--wp--custom--radius--pill` | Pills and avatar chips | `9999px` |

Optional: `--wp--preset--shadow--card` (from `settings.shadow.presets`, slug `card`) for raised cards;
fallback `none`.

### B5. How the plugin applies fallbacks

Plugin block CSS never reads `--wp--preset--*` directly. It reads its own aliases, declared on the block
wrappers and the patterns' helper classes so they re-resolve wherever a theme scopes presets. They ship in
`assets/css/tokens.css` as the `publiclms-tokens` style, a dependency of every plugin block and enqueued on
course, lesson, archive and dashboard pages:

```css
:where([class*="publiclms-"]) {
	--publiclms-color-primary: var(--wp--preset--color--primary, #3858e9);
	--publiclms-font-size-sm: var(--wp--preset--font-size--sm, 0.875rem);
	--publiclms-space-50: var(--wp--preset--spacing--50, 1.5rem);
	--publiclms-radius-md: var(--wp--custom--radius--md, 8px);
	/* …one alias per token in B1 to B4 */
}
```

Alias names: `--publiclms-color-{slug}`, `--publiclms-font-size-{slug}` (with `2xl` written `2-xl`),
`--publiclms-space-{slug}`, `--publiclms-radius-{sm|md|lg|pill}`, `--publiclms-shadow-card`. Themes may set
an alias directly to restyle plugin blocks only, without touching the global preset. `:where()` keeps the
aliases at zero specificity, so any theme rule wins.

Plugin blocks also declare block supports (colour, typography, spacing, border), so a theme can style them
in `styles.blocks["publiclms/<block>"]` in `theme.json`.

Two more zero-specificity rules ship with the aliases (1.2.0):

- **Form fields.** `:where([class*="wp-block-publiclms-"]) :where(input, select, textarea)` takes
  `background-color: var(--publiclms-color-base)`, `color: var(--publiclms-color-contrast)` and
  `border-color: var(--publiclms-color-contrast)`, so fields stay readable on dark palettes.
- **Font-size fallbacks.** `.has-xs-font-size` to `.has-2-xl-font-size` inside plugin markup fall back to the
  `--publiclms-font-size-*` aliases. WordPress prints those classes only for themes that define the B2
  presets, and its rule wins wherever it exists.

### B6. What the plugin does not read

Font families, layout widths (`contentSize`, `wideSize`; blocks inherit layout from their container),
gradients, duotones and the theme's element styles. These stay entirely the theme's business.
