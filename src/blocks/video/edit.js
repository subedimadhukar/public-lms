/**
 * Editor for publiclms/video: the source, captions and completion tracking
 * in the sidebar, and the server-rendered embed as the preview, so the
 * editor shows exactly what learners get.
 */
import {
	BlockControls,
	InspectorControls,
	MediaReplaceFlow,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

const PROVIDERS = [
	{ value: '', label: __( 'Detect from the URL', 'public-lms' ) },
	{ value: 'youtube', label: 'YouTube' },
	{ value: 'vimeo', label: 'Vimeo' },
	{ value: 'bunny', label: 'Bunny Stream' },
	{ value: 'self', label: __( 'Self-hosted file', 'public-lms' ) },
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		provider,
		src,
		mediaId,
		captions,
		title,
		caption,
		trackCompletion,
		requiredPercent,
	} = attributes;
	const blockProps = useBlockProps();
	const hasSource = !! src || mediaId > 0;

	const sourceControls = (
		<>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Provider', 'public-lms' ) }
				value={ provider }
				options={ PROVIDERS }
				onChange={ ( value ) => setAttributes( { provider: value } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Video URL', 'public-lms' ) }
				help={ __(
					'Paste a YouTube, Vimeo or Bunny Stream link, or the URL of a video file.',
					'public-lms'
				) }
				type="url"
				value={ src }
				onChange={ ( value ) =>
					setAttributes( { src: value.trim(), mediaId: 0 } )
				}
			/>
		</>
	);

	return (
		<>
			<BlockControls group="other">
				<MediaReplaceFlow
					mediaId={ mediaId }
					mediaURL={ src }
					allowedTypes={ [ 'video' ] }
					accept="video/*"
					name={ __( 'Choose video file', 'public-lms' ) }
					onSelect={ ( media ) =>
						setAttributes( {
							mediaId: media.id,
							src: media.url,
							provider: 'self',
						} )
					}
					onSelectURL={ ( url ) =>
						setAttributes( { src: url, mediaId: 0 } )
					}
				/>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Video', 'public-lms' ) }>
					{ sourceControls }
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Accessible title', 'public-lms' ) }
						help={ __(
							'Names the player for screen readers, for example the lesson topic.',
							'public-lms'
						) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
					/>
					{ ( provider === 'self' || mediaId > 0 ) && (
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Captions file (WebVTT URL)',
								'public-lms'
							) }
							type="url"
							value={ captions }
							onChange={ ( value ) =>
								setAttributes( { captions: value.trim() } )
							}
						/>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Completion', 'public-lms' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Complete the lesson when the video is watched',
							'public-lms'
						) }
						help={ __(
							'Counts the seconds a learner actually watches; skipping ahead does not count.',
							'public-lms'
						) }
						checked={ trackCompletion }
						onChange={ ( value ) =>
							setAttributes( { trackCompletion: value } )
						}
					/>
					{ trackCompletion && (
						<RangeControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Share to watch (%)', 'public-lms' ) }
							min={ 10 }
							max={ 100 }
							step={ 5 }
							value={ requiredPercent }
							onChange={ ( value ) =>
								setAttributes( {
									requiredPercent: Number( value ) || 90,
								} )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<figure { ...blockProps }>
				{ hasSource ? (
					<Disabled>
						<ServerSideRender
							block="publiclms/video"
							attributes={ {
								provider,
								src,
								mediaId,
								title,
								trackCompletion: false,
								requiredPercent,
							} }
						/>
					</Disabled>
				) : (
					<Placeholder
						icon="video-alt3"
						label={ __( 'Lesson video', 'public-lms' ) }
						instructions={ __(
							'Paste a YouTube, Vimeo or Bunny Stream link, or choose a video file from the toolbar.',
							'public-lms'
						) }
					>
						{ sourceControls }
					</Placeholder>
				) }
				<RichText
					tagName="figcaption"
					className="wp-block-publiclms-video__caption"
					aria-label={ __( 'Video caption', 'public-lms' ) }
					placeholder={ __( 'Add a caption', 'public-lms' ) }
					value={ caption }
					onChange={ ( value ) =>
						setAttributes( { caption: value } )
					}
				/>
			</figure>
		</>
	);
}
