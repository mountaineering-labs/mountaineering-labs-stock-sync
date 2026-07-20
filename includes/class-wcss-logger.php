<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small rotating log kept in wp_options, viewable on the settings page.
 * Not meant for high-volume debugging, just enough to see the last events.
 */
class WCSS_Logger {

	const OPTION_KEY = 'wcss_log';
	const MAX_ENTRIES = 200;

	public static function log( $message, $context = array() ) {
		$entries   = get_option( self::OPTION_KEY, array() );
		$entries[] = array(
			'time'    => current_time( 'mysql' ),
			'message' => $message,
			'context' => $context,
		);
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -1 * self::MAX_ENTRIES );
		}
		update_option( self::OPTION_KEY, $entries, false );
	}

	public static function render_log_table() {
		$entries = array_reverse( get_option( self::OPTION_KEY, array() ) );
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No events yet.', 'wc-stock-sync' ) . '</p>';
			return;
		}
		printf(
			'<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
			esc_html__( 'Time', 'wc-stock-sync' ),
			esc_html__( 'Message', 'wc-stock-sync' ),
			esc_html__( 'Details', 'wc-stock-sync' )
		);
		foreach ( array_slice( $entries, 0, 50 ) as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( $entry['time'] ),
				esc_html( $entry['message'] ),
				esc_html( wp_json_encode( $entry['context'] ) )
			);
		}
		echo '</tbody></table>';
	}
}
