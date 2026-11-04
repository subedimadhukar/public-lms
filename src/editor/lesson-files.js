/**
 * A document panel on lessons for the lesson's downloadable files
 * (`_plms_attachments`). They are listed under the lesson content for
 * learners who can open the lesson.
 */
import { MediaUploadCheck } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';
import { registerPlugin } from '@wordpress/plugins';

const KEY = '_plms_attachments';

function LessonFiles() {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const stored = postType === 'plms_lesson' ? meta?.[ KEY ] : null;
	const ids = useMemo( () => stored || [], [ stored ] );
	const files = useSelect(
		( select ) =>
			ids.map( ( id ) => ( {
				id,
				record: select( coreStore ).getMedia( id, {
					context: 'view',
				} ),
			} ) ),
		[ ids ]
	);

	if ( postType !== 'plms_lesson' ) {
		return null;
	}

	const update = ( next ) => setMeta( { ...meta, [ KEY ]: next } );

	return (
		<PluginDocumentSettingPanel
			name="publiclms-lesson-files"
			title={ __( 'Lesson files', 'public-lms' ) }
		>
			{ files.length === 0 && (
				<p>
					{ __(
						'Add worksheets, slides or other files learners can download under the lesson.',
						'public-lms'
					) }
				</p>
			) }
			{ files.length > 0 && (
				<ul className="publiclms-lesson-files">
					{ files.map( ( { id, record } ) => {
						const name =
							decodeEntities( record?.title?.rendered || '' ) ||
							sprintf(
								/* translators: %d: attachment ID. */
								__( 'File %d', 'public-lms' ),
								id
							);
						return (
							<li key={ id }>
								<span>{ name }</span>{ ' ' }
								<Button
									variant="link"
									isDestructive
									onClick={ () =>
										update(
											ids.filter(
												( other ) => other !== id
											)
										)
									}
									aria-label={ sprintf(
										/* translators: %s: file name. */
										__( 'Remove %s', 'public-lms' ),
										name
									) }
								>
									{ __( 'Remove', 'public-lms' ) }
								</Button>
							</li>
						);
					} ) }
				</ul>
			) }
			<MediaUploadCheck>
				<MediaUpload
					multiple
					value={ ids }
					title={ __( 'Lesson files', 'public-lms' ) }
					onSelect={ ( media ) =>
						update( [
							...new Set( [
								...ids,
								...( Array.isArray( media )
									? media
									: [ media ]
								).map( ( item ) => item.id ),
							] ),
						] )
					}
					render={ ( { open } ) => (
						<Button variant="secondary" onClick={ open }>
							{ __( 'Add files', 'public-lms' ) }
						</Button>
					) }
				/>
			</MediaUploadCheck>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'publiclms-lesson-files', { render: LessonFiles } );
