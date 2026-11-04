# Public LMS ↔ theme contracts

**Contract version:** 1.0.0 (phase 0, frozen at the phase 0 gate)
**Applies to:** Public LMS ≥ 0.1.0 and any block theme that styles it, CampusInterface first.
**Change rule:** a change to either contract is a pull request on both repositories, and bumps this version:
removing or renaming anything is a major bump; adding a slug, pattern, key or token is a minor bump; wording
fixes are a patch. The plugin lands contract changes two to three weeks before the theme consumes them.

There are two contracts, as the roadmap calls for:

- **A. Templates and patterns.** Which templates the plugin registers, which patterns each is built from,
  which binding keys patterns can use, and how a theme overrides them.
- **B. Tokens.** Which `theme.json` presets and custom properties the plugin's blocks read, and what the
  plugin falls back to when a theme does not define them.

The theme's side of these contracts is in `themes/campusinterface/docs/CONTRACTS.md`.

---

## A. Template and pattern contract

### A1. Templates the plugin registers

The plugin registers six block templates with `register_block_template()` (WordPress 6.7+). The template ID
is `publiclms//{slug}`; the slug is what a theme matches.

| Template ID | Slug | Title | Applies to | Built from patterns |
|---|---|---|---|---|
| `publiclms//single-course` | `single-course` | Single Course | A single `plms_course` | `publiclms/course-hero`, `publiclms/course-details`, `publiclms/course-curriculum`, `publiclms/course-instructor`, `publiclms/course-reviews`, `publiclms/course-enroll-card` |
| `publiclms//archive-course` | `archive-course` | Course Archive | The `plms_course` archive (`/courses/`) and the `plms_course_category`, `plms_course_tag` and `plms_level` term archives | `publiclms/course-archive-header`, `publiclms/course-grid` |
| `publiclms//single-lesson` | `single-lesson` | Single Lesson | A single `plms_lesson` | `publiclms/lesson-header`, `publiclms/lesson-content`, `publiclms/lesson-navigation`, `publiclms/lesson-sidebar` |
| `publiclms//single-quiz` | `single-quiz` | Single Quiz | A single `plms_quiz` | `publiclms/quiz-header`, `publiclms/quiz-runner` |
| `publiclms//page-dashboard` | `page-dashboard` | Student Dashboard | The page saved in option `publiclms_dashboard_page_id`, or any page whose editor picks this template | `publiclms/student-dashboard` |
| `publiclms//page-instructor` | `page-instructor` | Instructor Dashboard | The page saved in option `publiclms_instructor_page_id`, or any page whose editor picks this template | `publiclms/instructor-dashboard` |

No other templates are registered. In particular there is no `taxonomy-course-category` template: course term
archives use `archive-course` (see A4 and ADR-023 in `ARCHITECTURE.md`).

`page-dashboard` and `page-instructor` are registered with `post_types: [ "page" ]`, so they appear in the
page template picker.

### A2. How the slugs reach the template hierarchy

WordPress looks up `single-{post_type}` and `archive-{post_type}`, which for the plugin's post types are
`single-plms_course`, `archive-plms_course` and so on. The plugin inserts its friendly slug directly after
that candidate, so the order of precedence for a course is:

```
single-plms_course-{post-name}  →  single-plms_course  →  single-course  →  single  →  singular  →  index
```

| Request | Filter | Candidate inserted | Inserted after |
|---|---|---|---|
| Single `plms_course` | `single_template_hierarchy` | `single-course` | `single-plms_course` |
| Single `plms_lesson` | `single_template_hierarchy` | `single-lesson` | `single-plms_lesson` |
| Single `plms_quiz` | `single_template_hierarchy` | `single-quiz` | `single-plms_quiz` |
| `plms_course` archive | `archive_template_hierarchy` | `archive-course` | `archive-plms_course` |
| Course category, tag or level archive | `taxonomy_template_hierarchy` | `archive-course` | `taxonomy-{taxonomy}` |
| Configured dashboard page | `page_template_hierarchy` | `page-dashboard` | first, or after a template the editor picked |
| Configured instructor page | `page_template_hierarchy` | `page-instructor` | first, or after a template the editor picked |

For pages, the plugin also **removes** `page-dashboard` and `page-instructor` from the hierarchy of any page
that merely has the slug `dashboard` or `instructor` and is not the configured page, so an unrelated
"Dashboard" page never gets the student dashboard.

> **Known overlap (ADR-023).** `single-course`, `single-lesson`, `single-quiz` and `archive-course` are also
> the names WordPress looks up for plugins whose post types are literally `course`, `lesson` or `quiz`
> (Sensei LMS, and LifterLMS for `course` and `lesson`). The theme guard in A5 rule 6 keeps a theme's copies
> of these templates off those plugins' pages. If the guard proves fragile, the fallback is to rename the
> slugs to `single-plms_course` and the like, which needs no alias and no guard; that is a major contract bump.

### A3. Override rule

1. **Same slug wins.** A theme overrides a plugin template by shipping `templates/{slug}.html`, for example
   `templates/single-course.html`. WordPress gives theme template files precedence over plugin-registered
   templates with the same slug; users can still edit either one in the Site Editor, and their edits win
   over both.
2. **Never the other way.** The plugin never overrides a theme template, and never registers a slug that is
   not in A1.
3. **Partial override is fine.** A theme may override `single-course` and leave the other five to the plugin.

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
   core Query Loop over `plms_course`, with course data from the bindings source in A6. There is no
   `publiclms/course-card` block.
5. From phase 1 the plugin registers a Query Loop variation, `publiclms/course-query`. Patterns that want
   its extra controls (filter by level, hide enrolled courses) add `"namespace":"publiclms/course-query"` to
   the Query block's attributes; patterns without it still work as a plain query.
6. **Theme guard.** A theme that ships any of the A1 slugs as files must remove `single-course`,
   `single-lesson`, `single-quiz`, `archive-course`, `page-dashboard` and `page-instructor` from the
   template hierarchy when `post_type_exists( 'plms_course' )` is false, so its copies never render for
   another LMS's `course` post type while Public LMS is inactive.

### A6. Pattern inventory

| Pattern slug | Used by | Contents |
|---|---|---|
| `publiclms/course-hero` | single-course | Course title, excerpt, featured image (`fetchpriority="high"`), level and instructor via bindings, primary enrol button |
| `publiclms/course-details` | single-course | Duration, lesson count, level, last updated: Paragraph blocks bound to `publiclms/course-field` |
| `publiclms/course-curriculum` | single-course, lesson-sidebar | `publiclms/curriculum` block (sections and items, lock and completion states) |
| `publiclms/course-instructor` | single-course | Instructor avatar, name and bio |
| `publiclms/course-reviews` | single-course | `publiclms/course-reviews` block |
| `publiclms/course-enroll-card` | single-course | Price, `publiclms/enroll-button`, "this course includes" list; sticky on wide screens |
| `publiclms/course-archive-header` | archive-course | Archive title and description |
| `publiclms/course-grid` | archive-course | Query Loop over `plms_course` (`inherit: true` on archives), Post Template grid of course cards, pagination, no-results message |
| `publiclms/lesson-header` | single-lesson | Course link, section name, lesson title, `publiclms/course-progress` |
| `publiclms/lesson-content` | single-lesson | Post Content, attachments, `publiclms/mark-complete` |
| `publiclms/lesson-navigation` | single-lesson | Previous and next item links (`publiclms/lesson-navigation` block) |
| `publiclms/lesson-sidebar` | single-lesson | Collapsible curriculum for the current course |
| `publiclms/quiz-header` | single-quiz | Quiz title, attempts left, time limit, pass mark |
| `publiclms/quiz-runner` | single-quiz | `publiclms/quiz` block (Interactivity API runner, server-graded) |
| `publiclms/student-dashboard` | page-dashboard | `publiclms/student-dashboard` block with tabs: courses, progress, quiz results, certificates, orders |
| `publiclms/instructor-dashboard` | page-instructor | `publiclms/instructor-dashboard` block: own courses, learners, submissions, reports |

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

Values are escaped by the source. Keys are only ever added; removing or renaming one is a major bump.

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
wrappers so they re-resolve wherever a theme scopes presets:

```css
:where([class*="wp-block-publiclms-"]) {
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

### B6. What the plugin does not read

Font families, layout widths (`contentSize`, `wideSize`; blocks inherit layout from their container),
gradients, duotones and the theme's element styles. These stay entirely the theme's business.
