<?php
/**
 * Assignment settings.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Assignments;

use PublicLMS\Files\PrivateFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes an assignment's settings (post meta on `plms_assignment`, ADR-043).
 *
 * | Key           | Meta                          | Default                     | Meaning                                             |
 * |---------------|-------------------------------|-----------------------------|-----------------------------------------------------|
 * | points        | _plms_assignment_points       | 10                          | Highest grade                                       |
 * | pass_points   | _plms_assignment_pass_points  | 0                           | Grade needed to complete; 0 completes on submitting |
 * | allow_text    | _plms_assignment_allow_text   | true                        | Learners may type an answer                         |
 * | max_files     | _plms_assignment_max_files    | 3                           | Files per submission; 0 for text only               |
 * | max_file_size | _plms_assignment_max_size     | 10                          | Megabytes per file, capped by the server's limit    |
 * | file_types    | _plms_assignment_file_types   | pdf, doc, docx, odt, txt,   | Extensions accepted, from PrivateFiles::SAFE_TYPES  |
 * |               |                               | jpg, jpeg, png, zip         |                                                     |
 * | resubmit      | _plms_assignment_resubmit     | true                        | Hand in again before grading or after a fail        |
 */
final class AssignmentSettings {

	/**
	 * Setting keys and their meta keys.
	 */
	const KEYS = array(
		'points'        => '_plms_assignment_points',
		'pass_points'   => '_plms_assignment_pass_points',
		'allow_text'    => '_plms_assignment_allow_text',
		'max_files'     => '_plms_assignment_max_files',
		'max_file_size' => '_plms_assignment_max_size',
		'file_types'    => '_plms_assignment_file_types',
		'resubmit'      => '_plms_assignment_resubmit',
	);

	/**
	 * Default values.
	 */
	const DEFAULTS = array(
		'points'        => 10,
		'pass_points'   => 0,
		'allow_text'    => true,
		'max_files'     => 3,
		'max_file_size' => 10,
		'file_types'    => array( 'pdf', 'doc', 'docx', 'odt', 'txt', 'jpg', 'jpeg', 'png', 'zip' ),
		'resubmit'      => true,
	);

	/**
	 * Returns an assignment's settings with defaults filled in.
	 *
	 * @param int $assignment_id Assignment ID.
	 * @return array{points: float, pass_points: float, allow_text: bool, max_files: int, max_file_size: int, file_types: array<int, string>, resubmit: bool}
	 */
	public static function get( int $assignment_id ): array {
		$settings = self::DEFAULTS;
		foreach ( self::KEYS as $key => $meta ) {
			if ( metadata_exists( 'post', $assignment_id, $meta ) ) {
				$settings[ $key ] = self::clean( $key, get_post_meta( $assignment_id, $meta, true ) );
			}
		}
		$settings['points']      = (float) $settings['points'];
		$settings['pass_points'] = min( (float) $settings['pass_points'], (float) $settings['points'] );
		/**
		 * Settings, typed for static analysis.
		 *
		 * @var array{points: float, pass_points: float, allow_text: bool, max_files: int, max_file_size: int, file_types: array<int, string>, resubmit: bool} $settings
		 */
		return $settings;
	}

	/**
	 * Writes the given settings; unknown keys are ignored.
	 *
	 * @param int                  $assignment_id Assignment ID.
	 * @param array<string, mixed> $values        Settings.
	 * @return void
	 */
	public static function update( int $assignment_id, array $values ): void {
		foreach ( self::KEYS as $key => $meta ) {
			if ( array_key_exists( $key, $values ) ) {
				$value = self::clean( $key, $values[ $key ] );
				update_post_meta( $assignment_id, $meta, is_bool( $value ) ? ( $value ? '1' : '0' ) : $value );
			}
		}
	}

	/**
	 * Returns the largest file size accepted, in bytes: the setting, capped by the server.
	 *
	 * @param int $assignment_id Assignment ID.
	 * @return int
	 */
	public static function max_bytes( int $assignment_id ): int {
		$setting = self::get( $assignment_id )['max_file_size'] * MB_IN_BYTES;
		return (int) min( $setting, wp_max_upload_size() );
	}

	/**
	 * Cleans one setting value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	public static function clean( string $key, $value ) {
		switch ( $key ) {
			case 'points':
				return round( max( 0.0, min( 1000.0, is_scalar( $value ) ? (float) $value : 0.0 ) ), 2 );
			case 'pass_points':
				return round( max( 0.0, min( 1000.0, is_scalar( $value ) ? (float) $value : 0.0 ) ), 2 );
			case 'max_files':
				return min( 10, is_scalar( $value ) ? absint( $value ) : 0 );
			case 'max_file_size':
				return max( 1, min( 1024, is_scalar( $value ) ? absint( $value ) : 1 ) );
			case 'file_types':
				$list = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
				$list = array_map(
					static function ( $extension ) {
						return strtolower( trim( (string) $extension, " .\t\n\r" ) );
					},
					$list
				);
				return array_values( array_unique( array_intersect( $list, array_keys( PrivateFiles::SAFE_TYPES ) ) ) );
		}
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return (bool) $value;
	}
}
