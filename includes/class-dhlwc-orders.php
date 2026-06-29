<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHLWC_Orders {
	private $settings;
	private $api;
	private $builder;

	public function __construct() {
		$this->settings = DHLWC_Settings::get();
		$this->api      = new DHLWC_API_Client( $this->settings );
		$this->builder  = new DHLWC_Payload_Builder( $this->settings );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_order_box_style' ) );
	}

	public function enqueue_admin_order_box_style() {
		wp_register_style(
			'shippilot-admin-order-box',
			false,
			array(),
			'1.1.8'
		);

		wp_enqueue_style( 'shippilot-admin-order-box' );

		wp_add_inline_style(
			'shippilot-admin-order-box',
			'#dhlwc_order_box .dhlwc-order-actions,#dhlwc_order_box_hpos .dhlwc-order-actions{display:grid!important;grid-template-columns:1fr 1fr!important;gap:8px!important;margin-top:12px!important;}#dhlwc_order_box .dhlwc-action-button,#dhlwc_order_box_hpos .dhlwc-action-button{width:100%;text-align:center;box-sizing:border-box;min-height:32px;line-height:30px;padding:0 6px;}#dhlwc_order_box .dhlwc-full-button,#dhlwc_order_box_hpos .dhlwc-full-button{display:block;width:100%;}#dhlwc_order_box .dhlwc-tracking-action,#dhlwc_order_box_hpos .dhlwc-tracking-action{margin-top:8px;}'
		);
	}

	public function register_hooks() {
		add_action( 'init', array( $this, 'register_order_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_order_statuses' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_auto_send_order' ), 20, 1 );
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );
		add_action( 'admin_post_dhlwc_send_order', array( $this, 'handle_send_order_post' ) );
		add_action( 'admin_post_dhlwc_create_barcode', array( $this, 'handle_create_barcode_post' ) );
		add_action( 'admin_post_dhlwc_check_tracking', array( $this, 'handle_check_tracking_post' ) );
		add_action( DHLWC_Constants::CRON_HOOK, array( $this, 'sync_tracking_cron' ) );
		add_action( DHLWC_Constants::BARCODE_RETRY_HOOK, array( $this, 'retry_create_barcode' ), 10, 1 );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_order_meta_fields' ), 10, 3 );
	}

	public function register_order_statuses() {
		$statuses = array(
			'wc-dhl-ready'    => __( 'Ready for shipment', 'shippilot-for-woocommerce' ),
			'wc-dhl-shipped'  => __( 'Handed to carrier', 'shippilot-for-woocommerce' ),
			'wc-dhl-branch'   => __( 'At destination branch', 'shippilot-for-woocommerce' ),
			'wc-dhl-delivery' => __( 'Out for delivery', 'shippilot-for-woocommerce' ),
		);

		foreach ( $statuses as $key => $label ) {
			register_post_status(
				$key,
				array(
					'label'                     => $label,
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: Number of orders with this ShipPilot order status. */
					'label_count'               => _n_noop(
						'ShipPilot order status <span class="count">(%s)</span>',
						'ShipPilot order statuses <span class="count">(%s)</span>',
						'shippilot-for-woocommerce'
					),
				)
			);
		}
	}

	public function add_order_statuses( $statuses ) {
		$new = array();

		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$new['wc-dhl-ready']    = __( 'Ready for shipment', 'shippilot-for-woocommerce' );
				$new['wc-dhl-shipped']  = __( 'Handed to carrier', 'shippilot-for-woocommerce' );
				$new['wc-dhl-branch']   = __( 'At destination branch', 'shippilot-for-woocommerce' );
				$new['wc-dhl-delivery'] = __( 'Out for delivery', 'shippilot-for-woocommerce' );
			}
		}

		return $new;
	}

	public function maybe_auto_send_order( $order_id ) {
		if ( 'yes' !== $this->settings['auto_send'] ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$this->send_order( $order, false );
		}
	}

	public function send_order( WC_Order $order, $force = false ) {
		if ( ! $force && $order->get_meta( DHLWC_Constants::META_SENT ) ) {
			return true;
		}

		if ( 'yes' === $this->settings['prepare_recipient'] && ! $order->get_meta( DHLWC_Constants::META_RECIPIENT_CREATED ) ) {
			$recipient = $this->api->request( 'POST', '/pluscmdapi/createRecipient', $this->builder->create_recipient( $order ) );

			if ( ! is_wp_error( $recipient ) ) {
				$order->update_meta_data( DHLWC_Constants::META_RECIPIENT_CREATED, 'yes' );
			}
		}

		$payload       = $this->builder->create_order( $order );
		$reference     = $payload['order']['referenceId'];
		$piece_barcode = $payload['orderPieceList'][0]['barcode'];
		$response      = $this->api->request( 'POST', '/standardcmdapi/createOrder', $payload );

		if ( is_wp_error( $response ) ) {
			$order->update_meta_data( DHLWC_Constants::META_ERROR, wp_json_encode( $response->get_error_data(), JSON_UNESCAPED_UNICODE ) );
			$order->save();

			return $response;
		}

		$order->update_meta_data( DHLWC_Constants::META_SENT, 'yes' );
		$order->update_meta_data( DHLWC_Constants::META_REFERENCE_ID, $reference );
		$order->update_meta_data( DHLWC_Constants::META_PIECE_BARCODE, $piece_barcode );
		$order->update_meta_data( DHLWC_Constants::META_RESPONSE, wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );
		$order->update_meta_data( DHLWC_Constants::META_TRACKING_ACTIVE, 'yes' );
		$order->update_meta_data( DHLWC_Constants::META_ORDER_CREATED_AT, time() );
		$order->update_status( 'dhl-ready', __( 'ShipPilot shipment order created.', 'shippilot-for-woocommerce' ) );
		$order->save();

		if ( 'yes' === $this->settings['tracking_email_enabled'] ) {
			( new DHLWC_Email( $this->settings ) )->send_stage( $order, 'prepared' );
		}

		if ( 'yes' === $this->settings['auto_barcode'] ) {
			$this->schedule_barcode_retry( $order->get_id(), 600 );
		}

		return $response;
	}

	public function create_barcode( WC_Order $order, $retry_on_20011 = true ) {
		$reference = $order->get_meta( DHLWC_Constants::META_REFERENCE_ID );

		if ( ! $reference ) {
			$created = $this->send_order( $order, true );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$reference = $order->get_meta( DHLWC_Constants::META_REFERENCE_ID );
		}

		$payload  = $this->builder->create_barcode( $order, $reference );
		$response = $this->api->request( 'POST', '/barcodecmdapi/createbarcode', $payload );

		if ( is_wp_error( $response ) ) {
			$order->update_meta_data( DHLWC_Constants::META_ERROR, wp_json_encode( $response->get_error_data(), JSON_UNESCAPED_UNICODE ) );

			$attempts = (int) $order->get_meta( DHLWC_Constants::META_BARCODE_ATTEMPTS ) + 1;
			$order->update_meta_data( DHLWC_Constants::META_BARCODE_ATTEMPTS, $attempts );
			$order->save();

			if ( $retry_on_20011 && $attempts < 5 && $this->is_api_error_code( $response, '20011' ) ) {
				$this->schedule_barcode_retry( $order->get_id(), 300 * $attempts );
			}

			return $response;
		}

		$first        = isset( $response[0] ) && is_array( $response[0] ) ? $response[0] : $response;
		$barcode_info = $this->extract_barcode_info( $first );
		$has_shipment = ! empty( $first['shipmentId'] );
		$barcode_type = $has_shipment ? 'shipment' : 'reference';

		$order->update_meta_data( DHLWC_Constants::META_BARCODE_CREATED, 'yes' );
		$order->update_meta_data( DHLWC_Constants::META_BARCODE_TYPE, $barcode_type );
		$order->update_meta_data( DHLWC_Constants::META_BARCODE_RESPONSE, wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );

		if ( ! empty( $barcode_info['zpl'] ) ) {
			$order->update_meta_data( DHLWC_Constants::META_BARCODE_ZPL, $barcode_info['zpl'] );
		}

		if ( ! empty( $barcode_info['barcode'] ) ) {
			$order->update_meta_data( DHLWC_Constants::META_BARCODE_VALUE, $barcode_info['barcode'] );
		}

		if ( ! empty( $first['shipmentId'] ) ) {
			$order->update_meta_data( DHLWC_Constants::META_SHIPMENT_ID, $first['shipmentId'] );
		}

		if ( ! empty( $first['invoiceId'] ) ) {
			$order->update_meta_data( DHLWC_Constants::META_INVOICE_ID, $first['invoiceId'] );
		}

		if ( $has_shipment ) {
			$order->update_status( 'dhl-shipped', __( 'ShipPilot shipment barcode created.', 'shippilot-for-woocommerce' ) );

			if ( 'yes' === $this->settings['tracking_email_enabled'] ) {
				( new DHLWC_Email( $this->settings ) )->send_stage( $order, 'shipped' );
			}
		} else {
			$order->add_order_note( __( 'ShipPilot reference/order barcode created. The final shipment barcode is not available yet.', 'shippilot-for-woocommerce' ) );
		}

		$order->save();

		return $response;
	}

	public function retry_create_barcode( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order && ! $order->get_meta( DHLWC_Constants::META_BARCODE_CREATED ) ) {
			$this->create_barcode( $order, true );
		}
	}

	private function schedule_barcode_retry( $order_id, $delay ) {
		if ( ! wp_next_scheduled( DHLWC_Constants::BARCODE_RETRY_HOOK, array( $order_id ) ) ) {
			wp_schedule_single_event( time() + max( 60, (int) $delay ), DHLWC_Constants::BARCODE_RETRY_HOOK, array( $order_id ) );
		}
	}

	private function is_api_error_code( $error, $code ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();

		return isset( $data['body']['error']['Code'] ) && (string) $data['body']['error']['Code'] === (string) $code;
	}

	private function extract_barcode_info( $response ) {
		$barcodes = isset( $response['barcodes'] ) && is_array( $response['barcodes'] ) ? $response['barcodes'] : array();
		$first    = isset( $barcodes[0] ) && is_array( $barcodes[0] ) ? $barcodes[0] : array();

		return array(
			'zpl'     => isset( $first['value'] ) ? (string) $first['value'] : '',
			'barcode' => isset( $first['barcode'] ) ? (string) $first['barcode'] : '',
		);
	}

	public function sync_tracking_cron() {
		if ( 'yes' !== $this->settings['tracking_enabled'] ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 20,
				'status'     => array( 'dhl-ready', 'dhl-shipped', 'dhl-branch', 'dhl-delivery' ),
				'meta_key'   => DHLWC_Constants::META_TRACKING_ACTIVE,
				'meta_value' => 'yes',
			)
		);

		foreach ( $orders as $order ) {
			$this->sync_order_tracking( $order );
		}
	}

	public function sync_order_tracking( WC_Order $order ) {
		$reference = $order->get_meta( DHLWC_Constants::META_REFERENCE_ID );

		if ( ! $reference ) {
			return false;
		}

		$response = $this->api->request( 'GET', '/standardqueryapi/getshipmentstatus/' . rawurlencode( $reference ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$order->update_meta_data( DHLWC_Constants::META_STATUS_RESPONSE, wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );

		if ( ! empty( $response['trackingUrl'] ) ) {
			$order->update_meta_data( DHLWC_Constants::META_TRACKING_URL, $response['trackingUrl'] );
		}

		if ( ! empty( $response['shipmentId'] ) ) {
			$order->update_meta_data( DHLWC_Constants::META_SHIPMENT_ID, $response['shipmentId'] );
		}

		$stage = $this->map_stage( $response );

		if ( $stage ) {
			$this->apply_stage( $order, $stage, $response );
		}

		$order->save();

		return $response;
	}

	private function map_stage( $response ) {
		$text = strtoupper( wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );

		if ( ! empty( $response['isDelivered'] ) || false !== strpos( $text, 'TESLIM' ) || false !== strpos( $text, 'DELIVERED' ) ) {
			return 'delivered';
		}

		if ( false !== strpos( $text, 'DAGIT' ) || false !== strpos( $text, 'DAĞIT' ) ) {
			return 'delivery';
		}

		if ( false !== strpos( $text, 'VARI' ) || false !== strpos( $text, 'ŞUBE' ) || false !== strpos( $text, 'SUBE' ) ) {
			return 'branch';
		}

		if ( false !== strpos( $text, 'KARGO' ) || false !== strpos( $text, 'SHIPMENT' ) ) {
			return 'shipped';
		}

		return '';
	}

	private function apply_stage( WC_Order $order, $stage, $response ) {
		if ( $order->get_meta( DHLWC_Constants::META_LAST_STAGE ) === $stage ) {
			return;
		}

		$order->update_meta_data( DHLWC_Constants::META_LAST_STAGE, $stage );

		if ( 'shipped' === $stage ) {
			$order->update_status( 'dhl-shipped' );
		}

		if ( 'branch' === $stage ) {
			$order->update_status( 'dhl-branch' );
		}

		if ( 'delivery' === $stage ) {
			$order->update_status( 'dhl-delivery' );
		}

		if ( 'delivered' === $stage ) {
			$order->update_status( 'completed' );
		}

		( new DHLWC_Email( $this->settings ) )->send_stage(
			$order,
			$stage,
			array(
				'tracking_url' => $order->get_meta( DHLWC_Constants::META_TRACKING_URL ),
				'message'      => wp_json_encode( $response, JSON_UNESCAPED_UNICODE ),
			)
		);
	}

	public function add_order_metabox() {
		add_meta_box( 'dhlwc_order_box', 'ShipPilot', array( $this, 'render_order_metabox' ), 'shop_order', 'side', 'default' );
		add_meta_box( 'dhlwc_order_box_hpos', 'ShipPilot', array( $this, 'render_order_metabox' ), 'woocommerce_page_wc-orders', 'side', 'default' );
	}

	public function render_order_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$barcode_type  = $order->get_meta( DHLWC_Constants::META_BARCODE_TYPE );
		$barcode_zpl   = $order->get_meta( DHLWC_Constants::META_BARCODE_ZPL );
		$barcode_value = $order->get_meta( DHLWC_Constants::META_BARCODE_VALUE );

		echo '<p><strong>' . esc_html__( 'Reference:', 'shippilot-for-woocommerce' ) . '</strong> ' . esc_html( $order->get_meta( DHLWC_Constants::META_REFERENCE_ID ) ?: '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Piece barcode:', 'shippilot-for-woocommerce' ) . '</strong> ' . esc_html( $order->get_meta( DHLWC_Constants::META_PIECE_BARCODE ) ?: '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Shipment number:', 'shippilot-for-woocommerce' ) . '</strong> ' . esc_html( $order->get_meta( DHLWC_Constants::META_SHIPMENT_ID ) ?: '-' ) . '</p>';

		if ( $barcode_type ) {
			$label = 'shipment' === $barcode_type ? __( 'Shipment barcode', 'shippilot-for-woocommerce' ) : __( 'Reference / order barcode', 'shippilot-for-woocommerce' );
			echo '<p><strong>' . esc_html__( 'Barcode type:', 'shippilot-for-woocommerce' ) . '</strong> ' . esc_html( $label ) . '</p>';
		}

		if ( $barcode_value ) {
			echo '<p><strong>' . esc_html__( 'Carrier barcode:', 'shippilot-for-woocommerce' ) . '</strong> <code>' . esc_html( $barcode_value ) . '</code></p>';
		}

		if ( $barcode_zpl ) {
			echo '<p><strong>' . esc_html__( 'ZPL label:', 'shippilot-for-woocommerce' ) . '</strong> ' . esc_html__( 'Ready', 'shippilot-for-woocommerce' ) . '</p>';
		}

		echo '<div class="dhlwc-order-actions">';
		echo wp_kses_post( $this->order_button( $order, 'dhlwc_send_order', __( 'Create shipment', 'shippilot-for-woocommerce' ), 'button-primary dhlwc-action-button' ) );
		echo wp_kses_post( $this->order_button( $order, 'dhlwc_create_barcode', __( 'Create barcode', 'shippilot-for-woocommerce' ), 'dhlwc-action-button' ) );
		echo wp_kses_post( DHLWC_Label::print_button( $order ) );
		echo $barcode_zpl ? wp_kses_post( DHLWC_Label::zpl_download_button( $order ) ) : '<span></span>';
		echo '</div>';

		echo '<p class="dhlwc-tracking-action">';
		echo wp_kses_post( $this->order_button( $order, 'dhlwc_check_tracking', __( 'Check shipment status', 'shippilot-for-woocommerce' ), 'dhlwc-action-button dhlwc-full-button' ) );
		echo '</p>';
	}

	private function order_button( WC_Order $order, $action, $label, $class = '' ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => $action,
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $order->get_id()
		);

		return '<a class="button ' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public function handle_send_order_post() {
		$order  = $this->get_admin_post_order( 'dhlwc_send_order' );
		$result = $this->send_order( $order, true );

		$this->redirect_to_order( $order, is_wp_error( $result ) ? 'error' : 'sent' );
	}

	public function handle_create_barcode_post() {
		$order  = $this->get_admin_post_order( 'dhlwc_create_barcode' );
		$result = $this->create_barcode( $order, true );

		$this->redirect_to_order( $order, is_wp_error( $result ) ? 'barcode_error' : 'barcode' );
	}

	public function handle_check_tracking_post() {
		$order  = $this->get_admin_post_order( 'dhlwc_check_tracking' );
		$result = $this->sync_order_tracking( $order );

		$this->redirect_to_order( $order, is_wp_error( $result ) ? 'tracking_error' : 'tracking' );
	}

	private function get_admin_post_order( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized action.', 'shippilot-for-woocommerce' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( ! $order_id || ! check_admin_referer( $action . '_' . $order_id ) ) {
			wp_die( esc_html__( 'Security verification failed.', 'shippilot-for-woocommerce' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'shippilot-for-woocommerce' ) );
		}

		return $order;
	}

	private function redirect_to_order( WC_Order $order, $status ) {
		wp_safe_redirect(
			add_query_arg(
				'dhlwc_status',
				sanitize_key( $status ),
				$order->get_edit_order_url()
			)
		);
		exit;
	}

	public function email_order_meta_fields( $fields, $sent_to_admin, $order ) {
		if ( $order instanceof WC_Order && $order->get_meta( DHLWC_Constants::META_REFERENCE_ID ) ) {
			$fields['dhlwc_reference'] = array(
				'label' => __( 'ShipPilot reference number', 'shippilot-for-woocommerce' ),
				'value' => $order->get_meta( DHLWC_Constants::META_REFERENCE_ID ),
			);

			if ( $order->get_meta( DHLWC_Constants::META_SHIPMENT_ID ) ) {
				$fields['dhlwc_shipment'] = array(
					'label' => __( 'ShipPilot shipment number', 'shippilot-for-woocommerce' ),
					'value' => $order->get_meta( DHLWC_Constants::META_SHIPMENT_ID ),
				);
			}

			if ( $order->get_meta( DHLWC_Constants::META_BARCODE_TYPE ) ) {
				$fields['dhlwc_barcode_type'] = array(
					'label' => __( 'ShipPilot barcode type', 'shippilot-for-woocommerce' ),
					'value' => 'shipment' === $order->get_meta( DHLWC_Constants::META_BARCODE_TYPE ) ? __( 'Shipment barcode', 'shippilot-for-woocommerce' ) : __( 'Reference / order barcode', 'shippilot-for-woocommerce' ),
				);
			}
		}

		return $fields;
	}
}