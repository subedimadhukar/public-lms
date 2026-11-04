import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { useCourseInfo } from '../shared/use-course';

export default function Edit( { attributes, setAttributes, context } ) {
	const { courseId, label, showPrice } = attributes;
	const info = useCourseInfo( context, courseId );
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'public-lms' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show price', 'public-lms' ) }
						checked={ showPrice }
						onChange={ ( value ) =>
							setAttributes( { showPrice: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Course ID', 'public-lms' ) }
						help={ __(
							'Leave at 0 to use the course of the page or Query Loop.',
							'public-lms'
						) }
						value={ courseId }
						onChange={ ( value ) =>
							setAttributes( { courseId: Number( value ) || 0 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ showPrice && (
					<p className="wp-block-publiclms-enroll-button__price">
						{ info?.fields?.price || __( 'Free', 'public-lms' ) }
					</p>
				) }
				<RichText
					tagName="span"
					className="wp-block-publiclms-enroll-button__button wp-element-button"
					aria-label={ __( 'Enrol button text', 'public-lms' ) }
					placeholder={ __( 'Enrol for free', 'public-lms' ) }
					value={ label }
					onChange={ ( value ) => setAttributes( { label: value } ) }
					allowedFormats={ [] }
					withoutInteractiveFormatting
				/>
			</div>
		</>
	);
}
