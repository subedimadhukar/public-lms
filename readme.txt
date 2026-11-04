=== Public LMS – Online courses, quizzes and certificates ===
Contributors: wpinterface
Tags: lms, courses, e-learning, quiz, certificates
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Block-native LMS for any block theme: courses, quizzes, assignments, gradebook, drip and certificates, with Stripe and PayPal checkout.

== Description ==

Public LMS is a learning management system built for the block editor and full-site-editing themes. Courses, lessons and quizzes are written in the block editor, the course pages are block templates any block theme can restyle, and learner progress lives in fast, indexed database tables.

= Everything you need to teach is free =

The free plugin is a complete LMS, not a demo. A teacher can build, sell and run a course with it and never pay us:

* Courses, sections and lessons in the block editor, with video, attachments and course cloning
* Quizzes with six question types, a question bank, timers, attempts, randomisation and pass marks
* Assignments with file uploads, and a gradebook
* Drip content by days or date, and prerequisites
* Certificates designed with blocks and delivered as PDF
* Student dashboard, progress tracking, course Q&A, reviews and announcements
* Native checkout with Stripe, PayPal and offline payments, coupons, and a WooCommerce bridge
* Course and student reports, email notifications, REST API, WP-CLI, multisite and RTL support

There is no locked code in the free plugin. Public LMS Pro, sold separately, adds what schools, marketplaces and businesses need at scale: subscriptions and memberships, bundles, multiple instructors with earnings, groups and seats, live classes, analytics and integrations.

= Built the WordPress way =

* Works with any block theme, and pairs with the free CampusInterface theme
* Plugin-registered block templates your theme can override slug for slug
* Front end built with blocks and the Interactivity API, with no jQuery
* Every access check goes through one access policy; quizzes are graded on the server

This is a development release (0.3.0). It covers building a course, free enrolment, lessons with video and files, drip and prerequisites, quizzes, assignments, the gradebook and certificates. Checkout, reviews, Q&A, announcements, emails, instructor registration and reports arrive in the releases that follow.

= What works in 0.2.0 =

* A "Public LMS" admin screen with course and student lists, and a course builder: add, rename, reorder (drag and drop or keyboard) and delete sections and lessons, then write each lesson in the block editor
* Free self-enrolment, enrolment by invitation, and manual enrolment by instructors
* A lesson player: mark a lesson complete and the progress bar, curriculum and next-lesson link update without reloading
* A student dashboard with courses in progress and completed
* Blocks: Curriculum, Enrol Button, Course Progress, Mark Complete, Lesson Navigation, Lesson Content, Course Details and Student Dashboard, plus a Course Grid query and a Course field source for core blocks
* Block templates for courses, the course catalogue, lessons and the dashboard, which any block theme can override; classic themes get the same layout
* A REST API under publiclms/v1 and `wp publiclms seed` sample data

== Installation ==

1. Upload the `public-lms` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate Public LMS.
3. Go to Public LMS → Courses in the admin menu and choose "Add course".
4. Go to Public LMS → Settings and choose "Create a dashboard page" so learners have a home for their courses.

== Frequently Asked Questions ==

= Does it work with my theme? =

Public LMS is designed for block themes and registers its own templates, so any block theme can display courses. Classic themes show the same course, lesson and dashboard layouts between the theme's header and footer.

= Is the free version limited? =

No. Everything a teacher needs to teach is free. Pro adds features for schools, marketplaces and businesses running at scale.

= What happens to my data if I delete the plugin? =

Courses, enrolments and orders are kept. To remove everything, add `define( 'PUBLICLMS_REMOVE_ALL_DATA', true );` to wp-config.php before deleting the plugin.

== Screenshots ==

1. Placeholder: the course catalogue.
2. Placeholder: a lesson in the distraction-free layout.
3. Placeholder: the student dashboard.

== Changelog ==

= 0.3.0 =
* Drip by days after enrolment or on a date, for sections and items; prerequisite courses and steps.
* Lesson Video block for YouTube, Vimeo, Bunny Stream and self-hosted video, completing the lesson once watched, with no provider scripts.
* Lesson files listed under the lesson for learners who can open it.
* Quizzes: six question types in a reusable question bank, time limit, attempts, random draw, shuffling and pass mark, graded on the server. Quiz runner block and Single Quiz template.
* Assignments with private file uploads, grading with feedback, a Submissions screen and a Gradebook with CSV export. Single Assignment template.
* Certificates designed in the block editor and downloaded as PDF; Certificates and Quiz results tabs on the student dashboard.
* Personal data export and erasure for every learner table.
* Seed data now includes a quiz and an assignment in every course.

= 0.2.0 =
* Internal alpha: course builder, free enrolment, lesson player with saved progress, student dashboard.
* Enrolment, progress, event and course structure repositories with object caching.
* REST API: courses, curriculum, sections, lessons, enrolments, progress, students and settings.
* Admin app with DataViews lists and a drag-and-drop course builder.
* Eight blocks, an Interactivity API store for the lesson player, the Course field bindings source and the Course Grid query.
* Templates and patterns for courses, the catalogue, lessons and the dashboard, with a classic theme fallback.
* WP-CLI: `wp publiclms seed` and `wp publiclms reset`.

= 0.1.0 =
* Initial development scaffold: post types, taxonomies, roles and capabilities, database tables, REST namespace with a health route, and the template contract.

== Upgrade Notice ==

= 0.3.0 =
Development release. Adds two database tables (schema 3). Not for production sites.

= 0.2.0 =
Internal alpha. Not for production sites.

= 0.1.0 =
First development release.
