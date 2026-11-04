/**
 * Editor helpers shared by the Public LMS blocks.
 */
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';

const COURSE_TYPES = [
	'plms_course',
	'plms_lesson',
	'plms_quiz',
	'plms_assignment',
];

/**
 * Returns the `publiclms` REST field of the course a block shows: its
 * courseId attribute, or the course or lesson in context.
 *
 * @param {Object} context  Block context (postId, postType).
 * @param {number} courseId courseId attribute.
 * @return {Object|null} `{ course_id, course_title, fields }`, or null outside a course.
 */
export function useCourseInfo( context, courseId = 0 ) {
	return useSelect(
		( select ) => {
			const core = select( coreStore );
			if ( courseId > 0 ) {
				return (
					core.getEntityRecord( 'postType', 'plms_course', courseId )
						?.publiclms ?? null
				);
			}
			if (
				context?.postId &&
				COURSE_TYPES.includes( context?.postType )
			) {
				return (
					core.getEntityRecord(
						'postType',
						context.postType,
						context.postId
					)?.publiclms ?? null
				);
			}
			return null;
		},
		[ courseId, context?.postId, context?.postType ]
	);
}
