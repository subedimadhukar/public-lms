<?php
/**
 * Video URL parsing for the publiclms/video block.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what an author pastes (a watch URL, a share URL, an embed URL or an
 * ID) into the embed the block renders, for the four providers the PRD
 * names: YouTube, Vimeo, Bunny Stream and self-hosted files (ADR-041).
 *
 * Embeds use each provider's privacy-friendlier form: youtube-nocookie.com,
 * Vimeo with `dnt=1`. No provider script is loaded on the page; progress is
 * read from the players' postMessage events (PRD §5, no remote assets).
 */
final class VideoSource {

	/**
	 * Providers the block supports.
	 */
	const PROVIDERS = array( 'youtube', 'vimeo', 'bunny', 'self' );

	/**
	 * File extensions accepted for self-hosted video.
	 */
	const FILE_EXTENSIONS = array( 'mp4', 'm4v', 'webm', 'ogv', 'mov' );

	/**
	 * Parses a source into an embed description.
	 *
	 * @param string $provider One of PROVIDERS, or '' to detect it from the URL.
	 * @param string $src      URL or ID as entered.
	 * @return array{provider: string, id: string, url: string, origin: string}|null Null when the source is not usable.
	 */
	public static function parse( string $provider, string $src ): ?array {
		$src = trim( $src );
		if ( '' === $src ) {
			return null;
		}
		$provider = in_array( $provider, self::PROVIDERS, true ) ? $provider : self::detect( $src );

		switch ( $provider ) {
			case 'youtube':
				return self::youtube( $src );
			case 'vimeo':
				return self::vimeo( $src );
			case 'bunny':
				return self::bunny( $src );
			case 'self':
				return self::file( $src );
		}
		return null;
	}

	/**
	 * Guesses the provider from a URL.
	 *
	 * @param string $src URL or ID.
	 * @return string Provider, or '' when unknown.
	 */
	public static function detect( string $src ): string {
		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		if ( '' === $host ) {
			if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $src ) ) {
				return 'youtube';
			}
			return preg_match( '/^\d+$/', $src ) ? 'vimeo' : '';
		}
		if ( preg_match( '/(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be)$/', $host ) ) {
			return 'youtube';
		}
		if ( preg_match( '/(^|\.)vimeo\.com$/', $host ) ) {
			return 'vimeo';
		}
		if ( preg_match( '/(^|\.)mediadelivery\.net$/', $host ) ) {
			return 'bunny';
		}
		$extension = strtolower( pathinfo( (string) wp_parse_url( $src, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		return in_array( $extension, self::FILE_EXTENSIONS, true ) ? 'self' : '';
	}

	/**
	 * YouTube: watch, share, embed and Shorts URLs, or a bare 11-character ID.
	 *
	 * @param string $src URL or ID.
	 * @return array{provider: string, id: string, url: string, origin: string}|null
	 */
	private static function youtube( string $src ): ?array {
		$id = '';
		if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $src ) ) {
			$id = $src;
		} elseif ( preg_match( '#(?:youtu\.be/|/embed/|/shorts/|/live/|/v/)([A-Za-z0-9_-]{11})#', $src, $match ) ) {
			$id = $match[1];
		} else {
			$query = array();
			wp_parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $query );
			if ( isset( $query['v'] ) && is_string( $query['v'] ) && preg_match( '/^[A-Za-z0-9_-]{11}$/', $query['v'] ) ) {
				$id = $query['v'];
			}
		}
		if ( '' === $id ) {
			return null;
		}
		$origin = 'https://www.youtube-nocookie.com';
		return array(
			'provider' => 'youtube',
			'id'       => $id,
			'url'      => add_query_arg(
				array(
					'enablejsapi'    => 1,
					'rel'            => 0,
					'modestbranding' => 1,
					'origin'         => rawurlencode( self::site_origin() ),
				),
				$origin . '/embed/' . $id
			),
			'origin'   => $origin,
		);
	}

	/**
	 * Vimeo: vimeo.com/ID, vimeo.com/ID/HASH (unlisted), player.vimeo.com/video/ID?h=HASH, or an ID.
	 *
	 * @param string $src URL or ID.
	 * @return array{provider: string, id: string, url: string, origin: string}|null
	 */
	private static function vimeo( string $src ): ?array {
		$id   = '';
		$hash = '';
		if ( preg_match( '/^\d+$/', $src ) ) {
			$id = $src;
		} elseif ( preg_match( '#vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d+)(?:/([0-9a-f]{6,}))?#i', $src, $match ) ) {
			$id   = $match[1];
			$hash = $match[2] ?? '';
		}
		if ( '' === $id ) {
			return null;
		}
		$query = array();
		wp_parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $query );
		if ( '' === $hash && isset( $query['h'] ) && is_string( $query['h'] ) && preg_match( '/^[0-9a-f]+$/i', $query['h'] ) ) {
			$hash = $query['h'];
		}
		$args = array( 'dnt' => 1 );
		if ( '' !== $hash ) {
			$args['h'] = $hash;
		}
		$origin = 'https://player.vimeo.com';
		return array(
			'provider' => 'vimeo',
			'id'       => $id,
			'url'      => add_query_arg( $args, $origin . '/video/' . $id ),
			'origin'   => $origin,
		);
	}

	/**
	 * Bunny Stream: iframe.mediadelivery.net or player.mediadelivery.net, /embed/ or /play/ URLs.
	 *
	 * @param string $src URL.
	 * @return array{provider: string, id: string, url: string, origin: string}|null
	 */
	private static function bunny( string $src ): ?array {
		if ( ! preg_match( '#^https://(iframe|player)\.mediadelivery\.net/(?:embed|play)/(\d+)/([0-9a-f-]{36})#i', $src, $match ) ) {
			return null;
		}
		$origin = 'https://' . strtolower( $match[1] ) . '.mediadelivery.net';
		return array(
			'provider' => 'bunny',
			'id'       => $match[2] . '/' . strtolower( $match[3] ),
			'url'      => add_query_arg(
				array(
					'autoplay'   => 'false',
					'preload'    => 'true',
					'responsive' => 'true',
				),
				$origin . '/embed/' . $match[2] . '/' . strtolower( $match[3] )
			),
			'origin'   => $origin,
		);
	}

	/**
	 * Self-hosted: an http(s) URL to a video file.
	 *
	 * @param string $src URL.
	 * @return array{provider: string, id: string, url: string, origin: string}|null
	 */
	private static function file( string $src ): ?array {
		$url = esc_url_raw( $src, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'provider' => 'self',
			'id'       => '',
			'url'      => $url,
			'origin'   => '',
		);
	}

	/**
	 * Returns this site's origin (scheme and host), for YouTube's origin parameter.
	 *
	 * @return string
	 */
	private static function site_origin(): string {
		$parts = wp_parse_url( home_url() );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . $port;
	}
}
