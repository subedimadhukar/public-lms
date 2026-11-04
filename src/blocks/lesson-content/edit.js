import { RichText, useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<p className="wp-block-publiclms-lesson-content__placeholder">
				{ __(
					'The lesson content shows here for learners who can open it. Edit lessons from the course builder.',
					'public-lms'
				) }
			</p>
			<div className="publiclms-locked-notice">
				<RichText
					tagName="p"
					className="publiclms-locked-notice__message"
					aria-label={ __(
						'Message for learners who cannot open the lesson',
						'public-lms'
					) }
					placeholder={ __(
						'Locked message (leave empty to explain the reason automatically)',
						'public-lms'
					) }
					value={ attributes.lockedMessage }
					onChange={ ( value ) =>
						setAttributes( { lockedMessage: value } )
					}
					allowedFormats={ [] }
				/>
			</div>
		</div>
	);
}
