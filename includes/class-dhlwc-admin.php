<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHLWC_Admin {

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_dhlwc_test_connection', array( $this, 'handle_test_connection' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DHLWC_FILE ), array( $this, 'plugin_action_links' ) );
	}

	public function register_menu() {
		add_menu_page(
			'ShipPilot',
			'ShipPilot',
			'manage_woocommerce',
			'shippilot-for-woocommerce',
			array( $this, 'render_settings_page' ),
			'dashicons-location-alt',
			56
		);
	}

	public function register_settings() {
		register_setting(
			'dhlwc_settings_group',
			DHLWC_Constants::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'DHLWC_Settings', 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=shippilot-for-woocommerce' ) ) . '">' .
			esc_html__( 'Settings', 'shippilot-for-woocommerce' ) .
			'</a>'
		);

		return $links;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = DHLWC_Settings::get();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		$tabs = array(
			'settings' => __( 'API Settings', 'shippilot-for-woocommerce' ),
			'emails'   => __( 'Customer Emails', 'shippilot-for-woocommerce' ),
			'barcode'  => __( 'Barcode Integration', 'shippilot-for-woocommerce' ),
			'label'    => __( 'Label Design', 'shippilot-for-woocommerce' ),
			'help'     => __( 'Help', 'shippilot-for-woocommerce' ),
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'ShipPilot for WooCommerce', 'shippilot-for-woocommerce' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab ' . esc_attr( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=shippilot-for-woocommerce&tab=' . $key ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';

		if ( 'emails' === $tab ) {
			$this->render_emails( $settings );
		} elseif ( 'barcode' === $tab ) {
			$this->render_barcode( $settings );
		} elseif ( 'label' === $tab ) {
			$this->render_label( $settings );
		} elseif ( 'help' === $tab ) {
			$this->render_help();
		} else {
			$this->render_main( $settings );
		}

		echo '</div>';
	}

	private function render_main( $settings ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag after admin-post redirect.
		if ( isset( $_GET['dhlwc_test'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag after admin-post redirect.
			$status = sanitize_key( wp_unslash( $_GET['dhlwc_test'] ) );

			if ( 'ok' === $status ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'API connection test succeeded. This test sends a real token request to the shipping service.', 'shippilot-for-woocommerce' ) . '</p></div>';
			} elseif ( 'fail' === $status ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice text after admin-post redirect.
				$message = isset( $_GET['dhlwc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['dhlwc_message'] ) ) : __( 'API connection test failed.', 'shippilot-for-woocommerce' );
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dhlwc_settings_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Environment', 'shippilot-for-woocommerce' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[environment]">
							<option value="test" <?php selected( $settings['environment'], 'test' ); ?>><?php esc_html_e( 'Sandbox / Test', 'shippilot-for-woocommerce' ); ?></option>
							<option value="production" <?php selected( $settings['environment'], 'production' ); ?>><?php esc_html_e( 'Production', 'shippilot-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Customer Number', 'shippilot-for-woocommerce' ); ?></th>
					<td><input class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[customer_number]" value="<?php echo esc_attr( $settings['customer_number'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'API Password', 'shippilot-for-woocommerce' ); ?></th>
					<td><input type="password" class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[customer_password]" value="<?php echo esc_attr( $settings['customer_password'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Client ID', 'shippilot-for-woocommerce' ); ?></th>
					<td><input class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[client_id]" value="<?php echo esc_attr( $settings['client_id'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Client Secret', 'shippilot-for-woocommerce' ); ?></th>
					<td><input type="password" class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[client_secret]" value="<?php echo esc_attr( $settings['client_secret'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Automatic Shipment Creation', 'shippilot-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[auto_send]" value="yes" <?php checked( $settings['auto_send'], 'yes' ); ?>> <?php esc_html_e( 'Send orders automatically when they enter processing status.', 'shippilot-for-woocommerce' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Prepare Recipient', 'shippilot-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[prepare_recipient]" value="yes" <?php checked( $settings['prepare_recipient'], 'yes' ); ?>> <?php esc_html_e( 'Prepare recipient information before barcode creation.', 'shippilot-for-woocommerce' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Tracking Synchronization', 'shippilot-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[tracking_enabled]" value="yes" <?php checked( $settings['tracking_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'shippilot-for-woocommerce' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Default Volume / Weight', 'shippilot-for-woocommerce' ); ?></th>
					<td>
						<input type="number" min="1" size="8" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[default_desi]" value="<?php echo esc_attr( $settings['default_desi'] ); ?>">
						<input type="number" min="1" size="8" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[default_kg]" value="<?php echo esc_attr( $settings['default_kg'] ); ?>">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Package Type', 'shippilot-for-woocommerce' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[packaging_type]">
							<option value="1" <?php selected( $settings['packaging_type'], '1' ); ?>><?php esc_html_e( 'Document', 'shippilot-for-woocommerce' ); ?></option>
							<option value="2" <?php selected( $settings['packaging_type'], '2' ); ?>><?php esc_html_e( 'Parcel', 'shippilot-for-woocommerce' ); ?></option>
							<option value="3" <?php selected( $settings['packaging_type'], '3' ); ?>><?php esc_html_e( 'Package', 'shippilot-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Content Description', 'shippilot-for-woocommerce' ); ?></th>
					<td><input class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[content_text]" value="<?php echo esc_attr( $settings['content_text'] ); ?>"></td>
				</tr>
			</table>

			<?php
			$this->hidden_defaults( $settings, array( 'environment', 'customer_number', 'customer_password', 'client_id', 'client_secret', 'auto_send', 'prepare_recipient', 'tracking_enabled', 'default_desi', 'default_kg', 'packaging_type', 'content_text' ) );
			submit_button( __( 'Save Settings', 'shippilot-for-woocommerce' ) );
			?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dhlwc_test_connection' ); ?>
			<input type="hidden" name="action" value="dhlwc_test_connection">
			<?php submit_button( __( 'Test API Connection', 'shippilot-for-woocommerce' ), 'secondary' ); ?>
		</form>
		<?php
	}

	private function render_barcode( $settings ) {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dhlwc_settings_group' ); ?>

			<p><strong><?php esc_html_e( 'Barcode Integration', 'shippilot-for-woocommerce' ); ?></strong></p>
			<p><?php esc_html_e( 'This module should be used with accounts authorized for barcode generation. Recommended flow: create recipient, create order, verify shipment/order status, then create barcode.', 'shippilot-for-woocommerce' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Automatic Barcode Creation', 'shippilot-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[auto_barcode]" value="yes" <?php checked( $settings['auto_barcode'], 'yes' ); ?>> <?php esc_html_e( 'Try delayed barcode creation after shipment creation.', 'shippilot-for-woocommerce' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Note', 'shippilot-for-woocommerce' ); ?></th>
					<td><?php esc_html_e( 'The piece barcode is kept identical between order creation and barcode creation requests.', 'shippilot-for-woocommerce' ); ?></td>
				</tr>
			</table>

			<?php
			$this->hidden_defaults( $settings, array( 'auto_barcode' ) );
			submit_button( __( 'Save Settings', 'shippilot-for-woocommerce' ) );
			?>
		</form>
		<?php
	}

	private function render_label( $settings ) {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dhlwc_settings_group' ); ?>

			<p><strong><?php esc_html_e( 'Label Design', 'shippilot-for-woocommerce' ); ?></strong></p>
			<p><?php esc_html_e( 'These settings are used by the printable shipping label screen on WooCommerce orders.', 'shippilot-for-woocommerce' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Logo URL', 'shippilot-for-woocommerce' ); ?></th>
					<td><input class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[label_logo_url]" value="<?php echo esc_attr( $settings['label_logo_url'] ); ?>"><p class="description"><?php esc_html_e( 'Leave empty to use the text title.', 'shippilot-for-woocommerce' ); ?></p></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Sender Name', 'shippilot-for-woocommerce' ); ?></th>
					<td><input class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[label_sender_name]" value="<?php echo esc_attr( $settings['label_sender_name'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Sender Address', 'shippilot-for-woocommerce' ); ?></th>
					<td><textarea class="large-text" rows="3" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[label_sender_address]"><?php echo esc_textarea( $settings['label_sender_address'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Sender Phone', 'shippilot-for-woocommerce' ); ?></th>
					<td><input class="regular-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[label_sender_phone]" value="<?php echo esc_attr( $settings['label_sender_phone'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Accent Color', 'shippilot-for-woocommerce' ); ?></th>
					<td><input type="color" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[label_accent_color]" value="<?php echo esc_attr( $settings['label_accent_color'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Footer Note', 'shippilot-for-woocommerce' ); ?></th>
					<td><textarea class="large-text" rows="3" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[label_note]"><?php echo esc_textarea( $settings['label_note'] ); ?></textarea></td>
				</tr>
			</table>

			<?php
			$this->hidden_defaults( $settings, array( 'label_logo_url', 'label_sender_name', 'label_sender_address', 'label_sender_phone', 'label_note', 'label_accent_color' ) );
			submit_button( __( 'Save Label Design', 'shippilot-for-woocommerce' ) );
			?>
		</form>
		<?php
	}

	private function render_emails( $settings ) {
		$stages = array(
			'prepared'  => __( 'Prepared', 'shippilot-for-woocommerce' ),
			'shipped'   => __( 'Shipped', 'shippilot-for-woocommerce' ),
			'branch'    => __( 'At Branch', 'shippilot-for-woocommerce' ),
			'delivery'  => __( 'Out for Delivery', 'shippilot-for-woocommerce' ),
			'delivered' => __( 'Delivered', 'shippilot-for-woocommerce' ),
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dhlwc_settings_group' ); ?>

			<p><?php esc_html_e( 'Shipment emails use WooCommerce email header, footer and styling. You can customize only the subject and body content here.', 'shippilot-for-woocommerce' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Email Sending', 'shippilot-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[tracking_email_enabled]" value="yes" <?php checked( $settings['tracking_email_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'shippilot-for-woocommerce' ); ?></label></td>
				</tr>

				<?php foreach ( $stages as $stage => $label ) : ?>
					<tr>
					<?php /* translators: %s: Shipment email stage label. */ ?>
						<th><?php echo esc_html( sprintf( __( '%s subject', 'shippilot-for-woocommerce' ), $label ) ); ?></th>
						<td><input class="large-text" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[email_subject_<?php echo esc_attr( $stage ); ?>]" value="<?php echo esc_attr( $settings[ 'email_subject_' . $stage ] ); ?>"></td>
					</tr>
					<tr>
					<?php /* translators: %s: Shipment email stage label. */ ?>
						<th><?php echo esc_html( sprintf( __( '%s body', 'shippilot-for-woocommerce' ), $label ) ); ?></th>
						<td><textarea class="large-text" rows="5" name="<?php echo esc_attr( DHLWC_Constants::OPTION_KEY ); ?>[email_body_<?php echo esc_attr( $stage ); ?>]"><?php echo esc_textarea( $settings[ 'email_body_' . $stage ] ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php
			$this->hidden_defaults( $settings, array( 'tracking_email_enabled', 'email_subject_prepared', 'email_body_prepared', 'email_subject_shipped', 'email_body_shipped', 'email_subject_branch', 'email_body_branch', 'email_subject_delivery', 'email_body_delivery', 'email_subject_delivered', 'email_body_delivered' ) );
			submit_button( __( 'Save Settings', 'shippilot-for-woocommerce' ) );
			?>
		</form>
		<?php
	}

	private function render_help() {
		?>
		<h2><?php esc_html_e( 'Setup Help', 'shippilot-for-woocommerce' ); ?></h2>
		<p>
			<a class="button button-primary" href="https://sandbox.mngkargo.com.tr/" target="_blank" rel="noopener"><?php esc_html_e( 'Sandbox Portal', 'shippilot-for-woocommerce' ); ?></a>
			<a class="button" href="https://apizone.mngkargo.com.tr/" target="_blank" rel="noopener"><?php esc_html_e( 'Apizone Production Portal', 'shippilot-for-woocommerce' ); ?></a>
		</p>
		<ol>
			<li><?php esc_html_e( 'Create an application in Sandbox or Apizone.', 'shippilot-for-woocommerce' ); ?></li>
			<li><?php esc_html_e( 'Subscribe to Identity, Plus Command, Standard Command, Standard Query and Barcode Command API products.', 'shippilot-for-woocommerce' ); ?></li>
			<li><?php esc_html_e( 'Enter Client ID and Client Secret values in the plugin settings.', 'shippilot-for-woocommerce' ); ?></li>
			<li><?php esc_html_e( 'Run the token connection test with your customer number and API password.', 'shippilot-for-woocommerce' ); ?></li>
			<li><?php esc_html_e( 'Recommended barcode flow: create recipient, create order, then create barcode.', 'shippilot-for-woocommerce' ); ?></li>
		</ol>
		<p><strong><?php esc_html_e( 'Common error:', 'shippilot-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Error code 20011 usually means the shipment branch resolution or piece barcode matching is not ready yet.', 'shippilot-for-woocommerce' ); ?></p>
		<?php
	}

	private function hidden_defaults( $settings, $skip = array() ) {
		$skip = array_flip( array_merge( $skip, array( 'customer_password', 'client_secret' ) ) );

		foreach ( DHLWC_Settings::defaults() as $key => $default ) {
			if ( isset( $skip[ $key ] ) ) {
				continue;
			}

			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : $default;

			echo '<input type="hidden" name="' . esc_attr( DHLWC_Constants::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}
	}

	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'dhlwc_test_connection' ) ) {
			wp_die( esc_html__( 'Unauthorized action.', 'shippilot-for-woocommerce' ) );
		}

		$client = new DHLWC_API_Client( DHLWC_Settings::get() );
		$result = $client->token( true );

		$args = array(
			'page'       => 'shippilot-for-woocommerce',
			'dhlwc_test' => is_wp_error( $result ) ? 'fail' : 'ok',
		);

		if ( is_wp_error( $result ) ) {
			$args['dhlwc_message'] = rawurlencode( $result->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}