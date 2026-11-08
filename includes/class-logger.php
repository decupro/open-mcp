<?php
/**
 * Lightweight activity log (ring buffer stored in a non-autoloaded option).
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Activity log.
 */
class Logger {

	const OPTION = 'open_mcp_log';
	const MAX    = 200;

	/**
	 * Record a tool call.
	 *
	 * @param array $entry Entry data.
	 */
	public function log( array $entry ) {
		if ( ! Plugin::setting( 'log_enabled' ) ) {
			return;
		}
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array_merge(
				array(
					'time' => time(),
					'user' => get_current_user_id(),
				),
				$entry
			)
		);
		$log = array_slice( $log, 0, self::MAX );
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Entries, newest first.
	 *
	 * @return array
	 */
	public function entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Clear the log. */
	public function clear() {
		delete_option( self::OPTION );
	}
}
