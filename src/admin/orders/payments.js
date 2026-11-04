/**
 * The Payments screen: store currency, checkout page, guest checkout and
 * the payment methods.
 *
 * Secret fields are write-only (ADR-047): the server says whether one is
 * set and whether it comes from wp-config.php, never its value. Typing a
 * new value replaces it; "Remove" deletes the stored one.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ExternalLink,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

import { notifyError, notifySuccess } from '../components/notices';

/**
 * Names currencies in the admin's language with the browser's Intl data.
 *
 * @param {string[]} codes ISO 4217 codes.
 * @return {Array<{value: string, label: string}>} Options.
 */
function currencyOptions( codes ) {
	let names = null;
	try {
		names = new window.Intl.DisplayNames(
			[ document.documentElement.lang || 'en' ],
			{ type: 'currency' }
		);
	} catch {
		names = null;
	}
	return codes
		.map( ( code ) => ( {
			value: code,
			label: names ? `${ code } — ${ names.of( code ) }` : code,
		} ) )
		.sort( ( a, b ) => a.label.localeCompare( b.label ) );
}

/**
 * One gateway field.
 *
 * @param {Object}                         props
 * @param {Object}                         props.field    Field definition.
 * @param {Object}                         props.state    { value, is_set, from_config }.
 * @param {string|boolean|undefined}       props.edit     Pending edit, or undefined.
 * @param {(next: string|boolean) => void} props.onChange Called with the new value.
 */
function GatewayField( { field, state, edit, onChange } ) {
	const fromConfig = !! state?.from_config;
	const configHelp = __(
		'Set in the site configuration (wp-config.php). Change it there.',
		'public-lms'
	);
	const value = edit === undefined ? state?.value : edit;

	if ( field.type === 'toggle' ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ field.label }
				help={ fromConfig ? configHelp : field.help }
				checked={ !! value }
				disabled={ fromConfig }
				onChange={ onChange }
			/>
		);
	}
	if ( field.type === 'secret' ) {
		let help = field.help;
		if ( fromConfig ) {
			help = configHelp;
		} else if ( state?.is_set && edit !== false ) {
			help = __( 'Saved. Type a new value to replace it.', 'public-lms' );
		}
		return (
			<div className="publiclms-payments__secret">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="password"
					autoComplete="new-password"
					label={ field.label }
					help={ help }
					disabled={ fromConfig }
					placeholder={
						fromConfig || ( state?.is_set && edit !== false )
							? '••••••••'
							: ''
					}
					value={ typeof edit === 'string' ? edit : '' }
					onChange={ onChange }
				/>
				{ ! fromConfig && state?.is_set && edit !== false && (
					<Button
						variant="link"
						isDestructive
						onClick={ () => onChange( false ) }
					>
						{ __( 'Remove', 'public-lms' ) }
					</Button>
				) }
			</div>
		);
	}
	if ( field.type === 'textarea' ) {
		return (
			<TextareaControl
				__nextHasNoMarginBottom
				label={ field.label }
				help={ fromConfig ? configHelp : field.help }
				disabled={ fromConfig }
				value={ value || '' }
				onChange={ onChange }
			/>
		);
	}
	if ( field.type === 'select' ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ field.label }
				help={ fromConfig ? configHelp : field.help }
				disabled={ fromConfig }
				value={ value || '' }
				options={ Object.entries( field.options ).map(
					( [ key, label ] ) => ( { value: key, label } )
				) }
				onChange={ onChange }
			/>
		);
	}
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ fromConfig ? configHelp : field.help }
			disabled={ fromConfig }
			value={ value || '' }
			onChange={ onChange }
		/>
	);
}

export default function PaymentsScreen() {
	const [ settings, setSettings ] = useState( null );
	const [ edits, setEdits ] = useState( {} );
	const [ gatewayEdits, setGatewayEdits ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const pages = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'postType', 'page', {
				per_page: 100,
				status: 'publish',
				_fields: 'id,title',
			} ) ?? [],
		[]
	);

	useEffect( () => {
		apiFetch( { path: '/publiclms/v1/settings/payments' } )
			.then( setSettings )
			.catch( notifyError );
	}, [] );

	const currencies = useMemo(
		() => currencyOptions( settings?.currencies || [] ),
		[ settings?.currencies ]
	);

	if ( ! settings ) {
		return (
			<div className="publiclms-screen publiclms-screen--loading">
				<Spinner />
			</div>
		);
	}

	const value = ( key ) => ( key in edits ? edits[ key ] : settings[ key ] );
	const set = ( key ) => ( next ) =>
		setEdits( ( current ) => ( { ...current, [ key ]: next } ) );
	const setGateway = ( id, key ) => ( next ) =>
		setGatewayEdits( ( current ) => ( {
			...current,
			[ id ]: { ...( current[ id ] || {} ), [ key ]: next },
		} ) );
	const dirty =
		Object.keys( edits ).length > 0 ||
		Object.keys( gatewayEdits ).length > 0;

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		try {
			setSettings(
				await apiFetch( {
					path: '/publiclms/v1/settings/payments',
					method: 'POST',
					data: { ...edits, gateways: gatewayEdits },
				} )
			);
			setEdits( {} );
			setGatewayEdits( {} );
			notifySuccess( __( 'Payment settings saved.', 'public-lms' ) );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setBusy( false );
		}
	};

	const createPage = async () => {
		setBusy( true );
		try {
			setSettings(
				await apiFetch( {
					path: '/publiclms/v1/settings/payments/checkout-page',
					method: 'POST',
				} )
			);
			notifySuccess( __( 'Checkout page created.', 'public-lms' ) );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form className="publiclms-screen publiclms-payments" onSubmit={ save }>
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Payments', 'public-lms' ) }
				</h2>
			</div>
			{ ! settings.currency && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Choose the currency your courses are priced in. Nothing can be sold until you do.',
						'public-lms'
					) }
				</Notice>
			) }
			<Panel>
				<PanelBody title={ __( 'Store', 'public-lms' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Currency', 'public-lms' ) }
						help={ __(
							'Course prices and coupons are in this currency. Prices are shown in each visitor’s language and number format.',
							'public-lms'
						) }
						value={ value( 'currency' ) || '' }
						options={ [
							{
								value: '',
								label: __(
									'— Select a currency —',
									'public-lms'
								),
								disabled: true,
							},
							...currencies,
						] }
						onChange={ set( 'currency' ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Checkout page', 'public-lms' ) }
						help={ __(
							'The page with the Checkout block. “Buy” buttons on paid courses lead here.',
							'public-lms'
						) }
						value={ String( value( 'checkout_page_id' ) ) }
						options={ [
							{
								value: '0',
								label: __( '— Select a page —', 'public-lms' ),
							},
							...pages.map( ( page ) => ( {
								value: String( page.id ),
								label:
									decodeEntities(
										page.title?.rendered || ''
									) || `#${ page.id }`,
							} ) ),
						] }
						onChange={ ( next ) =>
							set( 'checkout_page_id' )( Number( next ) )
						}
					/>
					{ settings.checkout_page_url ? (
						<p>
							<ExternalLink href={ settings.checkout_page_url }>
								{ __( 'Open the checkout page', 'public-lms' ) }
							</ExternalLink>
						</p>
					) : (
						<Button
							variant="secondary"
							onClick={ createPage }
							disabled={ busy }
						>
							{ __( 'Create a checkout page', 'public-lms' ) }
						</Button>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Let buyers check out without an account',
							'public-lms'
						) }
						help={ __(
							'They give their name and email; an account is created once they have paid, and they set its password from an email.',
							'public-lms'
						) }
						checked={ !! value( 'guest_checkout' ) }
						onChange={ set( 'guest_checkout' ) }
					/>
				</PanelBody>
				{ settings.gateways.map( ( gateway ) => (
					<PanelBody
						key={ gateway.id }
						title={
							gateway.enabled
								? sprintf(
										/* translators: %s: payment method name. */
										__( '%s (on)', 'public-lms' ),
										gateway.title
									)
								: gateway.title
						}
						initialOpen={ gateway.enabled }
					>
						{ gateway.enabled && ! gateway.configured && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Switched on, but some settings are missing, so buyers do not see it yet.',
									'public-lms'
								) }
							</Notice>
						) }
						{ settings.currency && ! gateway.supports_currency && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: %s: currency code. */
									__(
										'This payment method does not take %s, so buyers do not see it.',
										'public-lms'
									),
									settings.currency
								) }
							</Notice>
						) }
						{ gateway.mode && (
							<p>
								{ gateway.mode === 'test'
									? __(
											'Test mode: no real money moves.',
											'public-lms'
										)
									: __(
											'Live mode: real payments.',
											'public-lms'
										) }
							</p>
						) }
						{ Object.entries( gateway.fields ).map(
							( [ key, field ] ) => (
								<GatewayField
									key={ key }
									field={ field }
									state={ gateway.settings[ key ] }
									edit={ gatewayEdits[ gateway.id ]?.[ key ] }
									onChange={ setGateway( gateway.id, key ) }
								/>
							)
						) }
						{ gateway.webhook_url && (
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								readOnly
								label={ __( 'Webhook URL', 'public-lms' ) }
								help={ __(
									'Add this address in the provider’s dashboard so it can tell the site about payments and refunds.',
									'public-lms'
								) }
								value={ gateway.webhook_url }
								onChange={ () => {} }
								onFocus={ ( event ) => event.target.select() }
							/>
						) }
					</PanelBody>
				) ) }
			</Panel>
			<div className="publiclms-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy || ! dirty }
				>
					{ __( 'Save payment settings', 'public-lms' ) }
				</Button>
			</div>
		</form>
	);
}
