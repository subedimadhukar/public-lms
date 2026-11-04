import { RichText, useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<RichText
				tagName="span"
				className="wp-element-button"
				aria-label={ __( 'Button text', 'public-lms' ) }
				placeholder={ __( 'Download your certificate', 'public-lms' ) }
				value={ attributes.label }
				onChange={ ( value ) => setAttributes( { label: value } ) }
				allowedFormats={ [] }
				withoutInteractiveFormatting
			/>
		</div>
	);
}
