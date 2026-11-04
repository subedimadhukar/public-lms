/**
 * Cursor pagination for DataViews.
 *
 * The REST lists return a cursor for the next page instead of page numbers
 * (ADR-030). This hook remembers the cursor of every page reached so far, so
 * DataViews can page back and forth, and offers one page beyond the last one
 * reached while the server says more rows exist.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';

/**
 * @param {Object}                    options
 * @param {Object}                    options.baseQuery Query without the cursor; a change resets the cursors.
 * @param {number}                    options.page      Current DataViews page, 1-based.
 * @param {number}                    options.perPage   Rows per page.
 * @param {(query: Object) => Object} options.useList   Hook taking the full query, returning { list, isLoading }.
 * @return {Object} { items, isLoading, paginationInfo, total }
 */
export default function useCursorPages( {
	baseQuery,
	page,
	perPage,
	useList,
} ) {
	const baseKey = JSON.stringify( baseQuery );
	const [ cursors, setCursors ] = useState( {
		key: baseKey,
		pages: { 1: '' },
	} );

	const pages = useMemo(
		() => ( cursors.key === baseKey ? cursors.pages : { 1: '' } ),
		[ cursors, baseKey ]
	);
	const cursor = pages[ page ] ?? '';
	const query = useMemo(
		() => ( { ...baseQuery, cursor } ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ baseKey, cursor ]
	);
	const { list, isLoading } = useList( query );

	useEffect( () => {
		if ( cursors.key !== baseKey ) {
			setCursors( { key: baseKey, pages: { 1: '' } } );
			return;
		}
		if ( list?.nextCursor && pages[ page + 1 ] === undefined ) {
			setCursors( {
				key: baseKey,
				pages: { ...pages, [ page + 1 ]: list.nextCursor },
			} );
		}
	}, [ list, baseKey, cursors.key, page, pages ] );

	const total = list?.total ?? 0;
	const reachable = Object.keys( pages ).length;
	const totalPages = Math.max(
		1,
		Math.min( Math.ceil( total / perPage ) || 1, reachable )
	);

	return {
		items: list?.items ?? [],
		isLoading,
		total,
		paginationInfo: { totalItems: total, totalPages },
	};
}
