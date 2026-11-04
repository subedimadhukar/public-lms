/**
 * Editor placeholder for publiclms/assignment. Limits (points, files, size,
 * types) are set on the assignment in the course builder, where the server
 * enforces them.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Assignment', 'public-lms' ) }>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						label={ __( 'Assignment ID', 'public-lms' ) }
						help={ __(
							'Leave at 0 to use the assignment this page shows.',
							'public-lms'
						) }
						value={ attributes.assignmentId }
						onChange={ ( value ) =>
							setAttributes( {
								assignmentId: Number( value ) || 0,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<div className="wp-block-publiclms-assignment__form">
					<p>
						{ __(
							'Learners hand in their answer and files here, then see their grade and your feedback. Set points, file limits and the pass grade in the course builder.',
							'public-lms'
						) }
					</p>
					<span className="wp-element-button">
						{ __( 'Hand in', 'public-lms' ) }
					</span>
				</div>
			</div>
		</>
	);
}
