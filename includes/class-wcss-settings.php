<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page: role (hub/spoke), site id, shared secret, peer URLs.
 */
class WCSS_Settings {

	const OPTION_KEY = 'wcss_settings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public static function get() {
		$defaults = array(
			'role'          => 'spoke', // 'hub' or 'spoke'
			'site_id'       => '',
			'shared_secret' => '',
			'hub_url'       => '',      // used when role = spoke
			'spoke_urls'    => '',      // used when role = hub, one URL per line
		);
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Stock Sync', 'wc-stock-sync' ),
			__( 'Stock Sync', 'wc-stock-sync' ),
			'manage_woocommerce',
			'wcss-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'wcss_settings_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		$out                  = array();
		$out['role']          = in_array( $input['role'] ?? '', array( 'hub', 'spoke' ), true ) ? $input['role'] : 'spoke';
		$out['site_id']       = sanitize_key( $input['site_id'] ?? '' );
		$out['shared_secret'] = sanitize_text_field( $input['shared_secret'] ?? '' );
		$out['hub_url']       = esc_url_raw( trim( $input['hub_url'] ?? '' ) );
		$out['spoke_urls']    = sanitize_textarea_field( $input['spoke_urls'] ?? '' );
		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['wcss_generate_secret'] ) && check_admin_referer( 'wcss_generate_secret' ) ) {
			$settings                  = self::get();
			$settings['shared_secret'] = wp_generate_password( 40, false );
			update_option( self::OPTION_KEY, $settings );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'New shared secret generated. Remember to set the same value on ALL connected sites.', 'wc-stock-sync' ) . '</p></div>';
		}

		if ( isset( $_POST['wcss_resync'] ) && check_admin_referer( 'wcss_resync' ) ) {
			$count = WCSS_Client::instance()->resync_all();
			/* translators: %d: number of products/variations queued. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Full resync queued for %d product(s)/variation(s).', 'wc-stock-sync' ), $count ) ) . '</p></div>';
		}

		$settings = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WC Stock Sync', 'wc-stock-sync' ); ?></h1>
			<p><?php esc_html_e( 'Set up one site as the hub (authoritative stock) and the others as spokes. The same shared secret must be used on all connected sites.', 'wc-stock-sync' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'wcss_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="wcss_role"><?php esc_html_e( 'Role', 'wc-stock-sync' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[role]" id="wcss_role">
								<option value="spoke" <?php selected( $settings['role'], 'spoke' ); ?>><?php esc_html_e( 'Spoke', 'wc-stock-sync' ); ?></option>
								<option value="hub" <?php selected( $settings['role'], 'hub' ); ?>><?php esc_html_e( 'Hub', 'wc-stock-sync' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wcss_site_id"><?php esc_html_e( 'Site ID', 'wc-stock-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="wcss_site_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[site_id]" value="<?php echo esc_attr( $settings['site_id'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. main-store', 'wc-stock-sync' ); ?>" />
							<p class="description"><?php esc_html_e( 'Unique short identifier for this site (used in logs and to avoid loops).', 'wc-stock-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="wcss_secret"><?php esc_html_e( 'Shared secret', 'wc-stock-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="wcss_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[shared_secret]" value="<?php echo esc_attr( $settings['shared_secret'] ); ?>" />
						</td>
					</tr>
					<tr class="wcss-spoke-only">
						<th><label for="wcss_hub_url"><?php esc_html_e( 'Hub URL', 'wc-stock-sync' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="wcss_hub_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hub_url]" value="<?php echo esc_attr( $settings['hub_url'] ); ?>" placeholder="https://example.com" />
							<p class="description"><?php esc_html_e( 'Only relevant when role = Spoke.', 'wc-stock-sync' ); ?></p>
						</td>
					</tr>
					<tr class="wcss-hub-only">
						<th><label for="wcss_spoke_urls"><?php esc_html_e( 'Spoke URLs', 'wc-stock-sync' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="4" id="wcss_spoke_urls" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[spoke_urls]" placeholder="https://store2.example&#10;https://store3.example"><?php echo esc_textarea( $settings['spoke_urls'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One URL per line. Only relevant when role = Hub.', 'wc-stock-sync' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Generate new shared secret', 'wc-stock-sync' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'wcss_generate_secret' ); ?>
				<button type="submit" name="wcss_generate_secret" class="button"><?php esc_html_e( 'Generate', 'wc-stock-sync' ); ?></button>
			</form>

			<?php if ( 'hub' === $settings['role'] ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Full resync', 'wc-stock-sync' ); ?></h2>
				<p><?php esc_html_e( 'Queues the current stock level of every product/variation with an SKU on this (hub) site for delivery to all spokes. Use after initial setup, or if something has gone out of sync.', 'wc-stock-sync' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'wcss_resync' ); ?>
					<button type="submit" name="wcss_resync" class="button button-primary"><?php esc_html_e( 'Run full resync', 'wc-stock-sync' ); ?></button>
				</form>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Outbound queue', 'wc-stock-sync' ); ?></h2>
			<p>
				<?php
				/* translators: %d: number of queued messages. */
				echo esc_html( sprintf( __( '%d message(s) awaiting delivery (including any in retry backoff).', 'wc-stock-sync' ), WCSS_Queue::pending_count() ) );
				?>
			</p>

			<hr />
			<h2><?php esc_html_e( 'Recent events', 'wc-stock-sync' ); ?></h2>
			<?php WCSS_Logger::render_log_table(); ?>
		</div>
		<script>
		(function() {
			function toggle() {
				var role = document.getElementById('wcss_role').value;
				document.querySelectorAll('.wcss-spoke-only').forEach(function(el){ el.style.display = role === 'spoke' ? '' : 'none'; });
				document.querySelectorAll('.wcss-hub-only').forEach(function(el){ el.style.display = role === 'hub' ? '' : 'none'; });
			}
			document.getElementById('wcss_role').addEventListener('change', toggle);
			toggle();
		})();
		</script>
		<?php
	}
}
