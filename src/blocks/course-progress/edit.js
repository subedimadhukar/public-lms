import { useBlockProps } from '@wordpress/block-editor';
import { __, sprintf } from '@wordpress/i18n';

const SAMPLE_PERCENT = 40;

export default function Edit( { attributes } ) {
	const isText = /\bis-style-text\b/.test( attributes.className || '' );
	const blockProps = useBlockProps( {
		style: { '--publiclms-progress': `${ SAMPLE_PERCENT }%` },
	} );
	return (
		<div { ...blockProps }>
			<div className="wp-block-publiclms-course-progress__track">
				{ ! isText && (
					<span className="wp-block-publiclms-course-progress__fill" />
				) }
			</div>
			<p className="wp-block-publiclms-course-progress__label">
				<span>
					{ sprintf(
						/* translators: %d: percentage. */
						__( '%d%% complete', 'public-lms' ),
						SAMPLE_PERCENT
					) }
				</span>
				<span className="wp-block-publiclms-course-progress__count">
					4 / 10
				</span>
			</p>
		</div>
	);
}
