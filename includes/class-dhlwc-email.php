<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHLWC_Email {
	private $settings;

	public function __construct( array $settings = null ) {
		$this->settings = $settings ?: DHLWC_Settings::get();
	}

	public function send_stage( WC_Order $order, $stage, array $data = array() ) {
		if ( 'yes' !== $this->settings['tracking_email_enabled'] ) {
			return;
		}

		$subject = $this->replace(
			$this->settings[ 'email_subject_' . $stage ] ?? '{site_name} - Shipment update',
			$order,
			$stage,
			$data
		);

		$body = $this->replace(
			$this->settings[ 'email_body_' . $stage ] ?? '',
			$order,
			$stage,
			$data
		);

		$to = $order->get_billing_email();

		if ( ! $to ) {
			return;
		}

		$mailer  = WC()->mailer();
		$content = $this->html( $order, $stage, $subject, $body, $data );

		$mailer->send(
			$to,
			$subject,
			$content,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	private function html( WC_Order $order, $stage, $heading, $plain_body, array $data ) {
		$mailer = WC()->mailer();

		ob_start();

		do_action( 'woocommerce_email_header', $heading, null );

		echo '<div class="email-introduction">';
		echo wp_kses_post( wpautop( nl2br( esc_html( $plain_body ) ) ) );

		if ( ! empty( $data['tracking_url'] ) ) {
			echo '<p><a href="' . esc_url( $data['tracking_url'] ) . '">' . esc_html__( 'Track shipment', 'shippilot-for-woocommerce' ) . '</a></p>';
		}

		echo '</div>';

		do_action( 'woocommerce_email_order_details', $order, false, false, null );
		do_action( 'woocommerce_email_order_meta', $order, false, false, null );
		do_action( 'woocommerce_email_customer_details', $order, false, false, null );
		do_action( 'woocommerce_email_footer', null );

		return $mailer->wrap_message( $heading, ob_get_clean() );
	}

	private function replace( $text, WC_Order $order, $stage, array $data ) {
		$tracking_url = $data['tracking_url'] ?? $order->get_meta( DHLWC_Constants::META_TRACKING_URL );

		$vars = array(
			'{site_name}'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{order_number}'  => $order->get_order_number(),
			'{customer_name}' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'{stage}'         => $stage,
			'{message}'       => $data['message'] ?? '',
			'{tracking_url}'  => $tracking_url,
			'{tracking_line}' => $tracking_url ? 'Tracking link: ' . $tracking_url : '',
			'{shipment_id}'   => $order->get_meta( DHLWC_Constants::META_SHIPMENT_ID ),
		);

		return strtr( (string) $text, $vars );
	}
}