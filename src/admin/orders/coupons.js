/**
 * The Coupons screen: discount codes with their limits and uses.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Modal,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

import { boot } from '../boot';
import ConfirmDialog from '../components/confirm-dialog';
import { notifyError, notifySuccess } from '../components/notices';

const EMPTY = {
	code: '',
	active: true,
	type: 'percent',
	amount: 10,
	courses: [],
	starts: '',
	expires: '',
	usage_limit: 0,
	user_limit: 0,
};

/**
 * The add and edit form.
 *
 * @param {Object}     props
 * @param {Object}     props.coupon  Coupon, or EMPTY for a new one.
 * @param {() => void} props.onClose Close without saving.
 * @param {() => void} props.onSaved Called after saving.
 */
function CouponForm( { coupon, onClose, onSaved } ) {
	const [ values, setValues ] = useState( coupon );
	const [ busy, setBusy ] = useState( false );
	const courses = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'postType', 'plms_course', {
				per_page: 100,
				status: 'publish',
				_fields: 'id,title',
			} ) ?? [],
		[]
	);
	const set = ( key ) => ( next ) =>
		setValues( ( current ) => ( { ...current, [ key ]: next } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		try {
			await apiFetch( {
				path: coupon.id
					? `/publiclms/v1/coupons/${ coupon.id }`
					: '/publiclms/v1/coupons',
				method: coupon.id ? 'PATCH' : 'POST',
				data: {
					...values,
					amount: Number( values.amount ),
					usage_limit: Number( values.usage_limit ) || 0,
					user_limit: Number( values.user_limit ) || 0,
				},
			} );
			notifySuccess( __( 'Coupon saved.', 'public-lms' ) );
			onSaved();
		} catch ( error ) {
			notifyError( error );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Modal
			title={
				coupon.id
					? __( 'Edit coupon', 'public-lms' )
					: __( 'Add coupon', 'public-lms' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<form onSubmit={ save } className="publiclms-coupon-form">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Code', 'public-lms' ) }
					help={ __(
						'What buyers type at checkout. Letters in any script and numbers, no spaces; upper and lower case match.',
						'public-lms'
					) }
					value={ values.code }
					onChange={ set( 'code' ) }
					required
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Discount', 'public-lms' ) }
					value={ values.type }
					options={ [
						{
							value: 'percent',
							label: __( 'Percentage off', 'public-lms' ),
						},
						{
							value: 'fixed',
							label: sprintf(
								/* translators: %s: currency code. */
								__( 'Amount off, in %s', 'public-lms' ),
								boot.currency || '—'
							),
						},
					] }
					onChange={ set( 'type' ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min="0"
					step="any"
					label={
						values.type === 'percent'
							? __( 'Percentage', 'public-lms' )
							: __( 'Amount', 'public-lms' )
					}
					value={ String( values.amount ) }
					onChange={ set( 'amount' ) }
					required
				/>
				<fieldset className="publiclms-coupon-form__courses">
					<legend>{ __( 'Courses', 'public-lms' ) }</legend>
					<p className="description">
						{ __(
							'Leave all unticked to apply it to every course.',
							'public-lms'
						) }
					</p>
					{ courses.map( ( course ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ course.id }
							label={
								decodeEntities(
									course.title?.rendered || ''
								) || `#${ course.id }`
							}
							checked={ values.courses.includes( course.id ) }
							onChange={ ( checked ) =>
								set( 'courses' )(
									checked
										? [ ...values.courses, course.id ]
										: values.courses.filter(
												( id ) => id !== course.id
											)
								)
							}
						/>
					) ) }
				</fieldset>
				<div className="publiclms-coupon-form__row">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="date"
						label={ __( 'First day', 'public-lms' ) }
						value={ values.starts }
						onChange={ set( 'starts' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="date"
						label={ __( 'Last day', 'public-lms' ) }
						value={ values.expires }
						onChange={ set( 'expires' ) }
					/>
				</div>
				<div className="publiclms-coupon-form__row">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min="0"
						label={ __( 'Uses in all', 'public-lms' ) }
						help={ __( '0 for no limit.', 'public-lms' ) }
						value={ String( values.usage_limit ) }
						onChange={ set( 'usage_limit' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min="0"
						label={ __( 'Uses per buyer', 'public-lms' ) }
						help={ __( '0 for no limit.', 'public-lms' ) }
						value={ String( values.user_limit ) }
						onChange={ set( 'user_limit' ) }
					/>
				</div>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Active', 'public-lms' ) }
					checked={ !! values.active }
					onChange={ set( 'active' ) }
				/>
				<div className="publiclms-form__actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'public-lms' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Save coupon', 'public-lms' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function CouponsScreen() {
	const [ rows, setRows ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );

	const reload = useCallback( () => {
		apiFetch( { path: '/publiclms/v1/coupons' } )
			.then( setRows )
			.catch( ( error ) => {
				notifyError( error );
				setRows( [] );
			} );
	}, [] );
	useEffect( reload, [ reload ] );

	const remove = async () => {
		try {
			await apiFetch( {
				path: `/publiclms/v1/coupons/${ deleting.id }`,
				method: 'DELETE',
			} );
			notifySuccess( __( 'Coupon deleted.', 'public-lms' ) );
			setDeleting( null );
			reload();
		} catch ( error ) {
			notifyError( error );
		}
	};

	return (
		<div className="publiclms-screen publiclms-coupons">
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Coupons', 'public-lms' ) }
				</h2>
				<Button variant="primary" onClick={ () => setEditing( EMPTY ) }>
					{ __( 'Add coupon', 'public-lms' ) }
				</Button>
			</div>
			{ rows === null && <Spinner /> }
			{ rows?.length === 0 && (
				<p className="publiclms-builder__empty">
					{ __( 'No coupons yet.', 'public-lms' ) }
				</p>
			) }
			{ rows?.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th scope="col">{ __( 'Code', 'public-lms' ) }</th>
							<th scope="col">
								{ __( 'Discount', 'public-lms' ) }
							</th>
							<th scope="col">
								{ __( 'Courses', 'public-lms' ) }
							</th>
							<th scope="col">{ __( 'Dates', 'public-lms' ) }</th>
							<th scope="col">{ __( 'Uses', 'public-lms' ) }</th>
							<th scope="col">
								{ __( 'Status', 'public-lms' ) }
							</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Actions', 'public-lms' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.id }>
								<td>
									<code>{ row.code }</code>
								</td>
								<td>{ row.amount_formatted }</td>
								<td>
									{ row.courses.length
										? sprintf(
												/* translators: %d: number of courses. */
												__(
													'%d courses',
													'public-lms'
												),
												row.courses.length
											)
										: __( 'All courses', 'public-lms' ) }
								</td>
								<td>
									{ [ row.starts, row.expires ]
										.map( ( date ) => date || '…' )
										.join( ' – ' ) }
								</td>
								<td>
									{ row.usage_limit
										? `${ row.uses } / ${ row.usage_limit }`
										: row.uses }
								</td>
								<td>
									{ row.active
										? __( 'Active', 'public-lms' )
										: __( 'Paused', 'public-lms' ) }
								</td>
								<td>
									<Button
										size="compact"
										variant="secondary"
										onClick={ () => setEditing( row ) }
									>
										{ __( 'Edit', 'public-lms' ) }
									</Button>{ ' ' }
									<Button
										size="compact"
										variant="tertiary"
										isDestructive
										onClick={ () => setDeleting( row ) }
									>
										{ __( 'Delete', 'public-lms' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ editing && (
				<CouponForm
					coupon={ editing }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						reload();
					} }
				/>
			) }
			{ deleting && (
				<ConfirmDialog
					title={ __( 'Delete this coupon?', 'public-lms' ) }
					message={ sprintf(
						/* translators: %s: coupon code. */
						__(
							'Buyers can no longer use %s. Orders that used it keep it.',
							'public-lms'
						),
						deleting.code
					) }
					confirmLabel={ __( 'Delete', 'public-lms' ) }
					onConfirm={ remove }
					onCancel={ () => setDeleting( null ) }
				/>
			) }
		</div>
	);
}
