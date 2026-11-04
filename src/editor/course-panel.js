/**
 * A document panel on lessons, quizzes and assignments that names the course
 * the item belongs to and links back to the course builder, so "Edit
 * content" in the builder is a round trip.
 */
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const ITEM_TYPES = [ 'plms_lesson', 'plms_quiz', 'plms_assignment' ];

function CoursePanel() {
	const { postType, info } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postType: editor.getCurrentPostType(),
			info: editor.getCurrentPostAttribute( 'publiclms' ),
		};
	}, [] );

	if ( ! ITEM_TYPES.includes( postType ) ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="publiclms-course"
			title={ __( 'Course', 'public-lms' ) }
		>
			{ info?.course_id ? (
				<>
					<p>{ decodeEntities( info.course_title ) }</p>
					{ info.builder_url && (
						<Button variant="secondary" href={ info.builder_url }>
							{ __( 'Back to the course builder', 'public-lms' ) }
						</Button>
					) }
				</>
			) : (
				<p>
					{ __(
						'Not in a course yet. Add it from the course builder.',
						'public-lms'
					) }
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'publiclms-course-panel', { render: CoursePanel } );
