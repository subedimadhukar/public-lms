/**
 * Certificate template editing (ADR-044): the `publiclms/certificate-field`
 * bindings source shows each field's name in the editor, and a document
 * panel previews the template as a PDF with sample values.
 */
import apiFetch from '@wordpress/api-fetch';
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const FIELDS = {
	learner_name: __( 'Learner name', 'public-lms' ),
	course_title: __( 'Course title', 'public-lms' ),
	completion_date: __( 'Completion date', 'public-lms' ),
	certificate_id: __( 'Certificate ID', 'public-lms' ),
	instructor: __( 'Instructor', 'public-lms' ),
	site_title: __( 'Site name', 'public-lms' ),
};

if ( typeof registerBlockBindingsSource === 'function' ) {
	registerBlockBindingsSource( {
		name: 'publiclms/certificate-field',
		label: __( 'Certificate field', 'public-lms' ),
		getValues( { bindings } ) {
			const values = {};
			for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
				const key = binding?.args?.key;
				values[ attribute ] = FIELDS[ key ] || key;
			}
			return values;
		},
		canUserEditValue: () => false,
		getFieldsList: () =>
			Object.entries( FIELDS ).map( ( [ key, label ] ) => ( {
				label,
				type: 'string',
				args: { key },
			} ) ),
	} );
}

function CertificatePanel() {
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	if ( postType !== 'plms_certificate' ) {
		return null;
	}

	const preview = async () => {
		setIsBusy( true );
		setError( '' );
		const win = window.open( '', '_blank' );
		try {
			const response = await apiFetch( {
				path: '/publiclms/v1/certificates/preview',
				parse: false,
			} );
			const blob = await response.blob();
			if ( win ) {
				win.location = window.URL.createObjectURL( blob );
			}
		} catch ( caught ) {
			win?.close();
			setError(
				caught.message ||
					__( 'The preview could not be made.', 'public-lms' )
			);
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<PluginDocumentSettingPanel
			name="publiclms-certificate"
			title={ __( 'Certificate', 'public-lms' ) }
		>
			<p>
				{ __(
					'Bind paragraphs and headings to certificate fields (Learner name, Course title, Completion date, Certificate ID, Instructor, Site name). Save, then preview the PDF with sample values.',
					'public-lms'
				) }
			</p>
			<p>
				{ __(
					'Keep the layout to text, separators and images: the PDF does not support columns side by side.',
					'public-lms'
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ preview }
				isBusy={ isBusy }
				disabled={ isBusy }
			>
				{ __( 'Preview PDF', 'public-lms' ) }
			</Button>
			{ error && <p role="alert">{ error }</p> }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'publiclms-certificate-panel', { render: CertificatePanel } );
