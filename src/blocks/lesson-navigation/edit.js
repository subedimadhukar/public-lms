import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { showTitles, previousLabel, nextLabel } = attributes;
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'public-lms' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show lesson titles', 'public-lms' ) }
						checked={ showTitles }
						onChange={ ( value ) =>
							setAttributes( { showTitles: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<span className="wp-block-publiclms-lesson-navigation__link is-previous">
					<RichText
						tagName="span"
						className="wp-block-publiclms-lesson-navigation__label"
						aria-label={ __( 'Previous link text', 'public-lms' ) }
						placeholder={ __( 'Previous', 'public-lms' ) }
						value={ previousLabel }
						onChange={ ( value ) =>
							setAttributes( { previousLabel: value } )
						}
						allowedFormats={ [] }
						withoutInteractiveFormatting
					/>
					{ showTitles && (
						<span className="wp-block-publiclms-lesson-navigation__title">
							{ __( 'Previous lesson title', 'public-lms' ) }
						</span>
					) }
				</span>
				<span className="wp-block-publiclms-lesson-navigation__link is-next">
					<RichText
						tagName="span"
						className="wp-block-publiclms-lesson-navigation__label"
						aria-label={ __( 'Next link text', 'public-lms' ) }
						placeholder={ __( 'Next', 'public-lms' ) }
						value={ nextLabel }
						onChange={ ( value ) =>
							setAttributes( { nextLabel: value } )
						}
						allowedFormats={ [] }
						withoutInteractiveFormatting
					/>
					{ showTitles && (
						<span className="wp-block-publiclms-lesson-navigation__title">
							{ __( 'Next lesson title', 'public-lms' ) }
						</span>
					) }
				</span>
			</div>
		</>
	);
}
