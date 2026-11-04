import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'public-lms' ) }>
					<ToggleControl
						label={ __( 'Show the coupon field', 'public-lms' ) }
						checked={ !! attributes.showCoupon }
						onChange={ ( value ) =>
							setAttributes( { showCoupon: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				icon="cart"
				label={ __( 'Checkout', 'public-lms' ) }
				instructions={ __(
					'Buyers reach this page from a paid course’s “Buy” button. It shows the order summary, a coupon field, the buyer’s details and the payment methods set up in Public LMS → Payments, and the order once it is placed.',
					'public-lms'
				) }
			/>
		</div>
	);
}
