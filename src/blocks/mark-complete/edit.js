import { RichText, useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { label, completedLabel } = attributes;
	return (
		<div { ...useBlockProps() }>
			<span className="wp-block-publiclms-mark-complete__button wp-element-button">
				<span
					className="wp-block-publiclms-mark-complete__icon"
					aria-hidden="true"
				/>
				<RichText
					tagName="span"
					aria-label={ __( 'Button text', 'public-lms' ) }
					placeholder={ __( 'Mark as complete', 'public-lms' ) }
					value={ label }
					onChange={ ( value ) => setAttributes( { label: value } ) }
					allowedFormats={ [] }
					withoutInteractiveFormatting
				/>
			</span>
			<span className="wp-block-publiclms-mark-complete__status">
				{ __( 'After completion:', 'public-lms' ) }{ ' ' }
				<RichText
					tagName="span"
					aria-label={ __(
						'Button text after completion',
						'public-lms'
					) }
					placeholder={ __( 'Completed', 'public-lms' ) }
					value={ completedLabel }
					onChange={ ( value ) =>
						setAttributes( { completedLabel: value } )
					}
					allowedFormats={ [] }
					withoutInteractiveFormatting
				/>
			</span>
		</div>
	);
}
