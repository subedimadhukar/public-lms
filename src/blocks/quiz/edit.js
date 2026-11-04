/**
 * Editor placeholder for publiclms/quiz. Questions and settings are edited
 * in the course builder; this block only runs the quiz on the page.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes, context } ) {
	const onQuiz = context?.postType === 'plms_quiz';
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Quiz', 'public-lms' ) }>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						label={ __( 'Quiz ID', 'public-lms' ) }
						help={ __(
							'Leave at 0 to run the quiz this page shows.',
							'public-lms'
						) }
						value={ attributes.quizId }
						onChange={ ( value ) =>
							setAttributes( { quizId: Number( value ) || 0 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<div className="wp-block-publiclms-quiz__intro">
					<p>
						{ onQuiz || attributes.quizId
							? __(
									'Learners start the quiz here, answer the questions and see their server-graded result. Edit questions and settings in the course builder.',
									'public-lms'
								)
							: __(
									'Shows the quiz on quiz pages, or the quiz chosen in the block settings.',
									'public-lms'
								) }
					</p>
					<span className="wp-block-publiclms-quiz__start wp-element-button">
						{ __( 'Start quiz', 'public-lms' ) }
					</span>
				</div>
			</div>
		</>
	);
}
