/**
 * Editor side of the `publiclms/course-field` bindings source (CONTRACTS.md A7).
 *
 * Values come from the read-only `publiclms` field the plugin adds to the
 * wp/v2 course and lesson routes, which is computed by the same PHP class as
 * the front end. In a template without a course, the key's name is shown.
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

const KEYS = {
	price: __( 'Course price', 'public-lms' ),
	regular_price: __( 'Course regular price', 'public-lms' ),
	duration: __( 'Course duration', 'public-lms' ),
	lesson_count: __( 'Lesson count', 'public-lms' ),
	level: __( 'Course level', 'public-lms' ),
	instructor: __( 'Instructor name', 'public-lms' ),
	instructor_url: __( 'Instructor URL', 'public-lms' ),
	instructor_avatar: __( 'Instructor avatar', 'public-lms' ),
	enrolled_count: __( 'Learner count', 'public-lms' ),
	rating: __( 'Course rating', 'public-lms' ),
	enroll_url: __( 'Enrol URL', 'public-lms' ),
	enroll_label: __( 'Enrol button text', 'public-lms' ),
	progress: __( 'My progress', 'public-lms' ),
};

const COURSE_TYPES = [
	'plms_course',
	'plms_lesson',
	'plms_quiz',
	'plms_assignment',
];

if ( typeof registerBlockBindingsSource === 'function' ) {
	registerBlockBindingsSource( {
		name: 'publiclms/course-field',
		label: __( 'Course field', 'public-lms' ),
		usesContext: [ 'postId', 'postType' ],
		getValues( { select, context, bindings } ) {
			const values = {};
			for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
				const key = binding?.args?.key;
				const courseId = Number( binding?.args?.courseId ) || 0;
				let record = null;
				if ( courseId > 0 ) {
					record = select( coreStore ).getEntityRecord(
						'postType',
						'plms_course',
						courseId
					);
				} else if (
					context?.postId &&
					COURSE_TYPES.includes( context?.postType )
				) {
					record = select( coreStore ).getEntityRecord(
						'postType',
						context.postType,
						context.postId
					);
				}
				const value = record?.publiclms?.fields?.[ key ];
				values[ attribute ] = value || KEYS[ key ] || key;
			}
			return values;
		},
		canUserEditValue: () => false,
		getFieldsList() {
			return Object.entries( KEYS ).map( ( [ key, label ] ) => ( {
				label,
				type: 'string',
				args: { key },
			} ) );
		},
	} );
}
