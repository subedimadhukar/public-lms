=== Public LMS – Online courses, quizzes and certificates ===
Contributors: wpinterface
Tags: lms, courses, e-learning, quiz, certificates
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
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

This is an early development release (0.1.0) that sets up the plugin's foundations. Teaching features arrive in the releases that follow.

== Installation ==

1. Upload the `public-lms` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate Public LMS.
3. Go to Courses in the admin menu to add your first course.

== Frequently Asked Questions ==

= Does it work with my theme? =

Public LMS is designed for block themes and registers its own templates, so any block theme can display courses. Classic theme support is planned.

= Is the free version limited? =

No. Everything a teacher needs to teach is free. Pro adds features for schools, marketplaces and businesses running at scale.

= What happens to my data if I delete the plugin? =

Courses, enrolments and orders are kept. To remove everything, add `define( 'PUBLICLMS_REMOVE_ALL_DATA', true );` to wp-config.php before deleting the plugin.

== Screenshots ==

1. Placeholder: the course catalogue.
2. Placeholder: a lesson in the distraction-free layout.
3. Placeholder: the student dashboard.

== Changelog ==

= 0.1.0 =
* Initial development scaffold: post types, taxonomies, roles and capabilities, database tables, REST namespace with a health route, and the template contract.

== Upgrade Notice ==

= 0.1.0 =
First development release.
