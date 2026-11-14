<?php
/**
 * The newsletter checkbox at WooCommerce checkout.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an unticked "email me news" checkbox to WooCommerce checkout, both
 * the classic checkout and the block checkout (WooCommerce 8.9+), and signs
 * up customers who tick it once their order is placed.
 */
class LorePort_WooCommerce {

	const FIELD = 'loreport/subscribe';

	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! LorePort_Settings::checkbox( 'woocommerce' )['enabled'] ) {
			return;
		}

		// Classic checkout.
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'classic_field' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_order' ), 10, 3 );

		// Block checkout.
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'block_order' ) );
	}

	public static function classic_field(): void {
		woocommerce_form_field(
			'loreport_subscribe',
			array(
				'type'  => 'checkbox',
				'class' => array( 'form-row-wide', 'loreport-subscribe' ),
				'label' => esc_html( LorePort_Settings::checkbox( 'woocommerce' )['label'] ),
			),
			0
		);
	}

	/**
	 * @param int      $order_id    Order ID.
	 * @param array    $posted_data Checkout data.
	 * @param WC_Order $order       The order.
	 */
	public static function classic_order( $order_id, $posted_data, $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the checkout nonce.
		if ( ! empty( $_POST['loreport_subscribe'] ) && $order instanceof WC_Order ) {
			self::subscribe( $order );
		}
	}

	public static function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::FIELD,
				'label'    => LorePort_Settings::checkbox( 'woocommerce' )['label'],
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
			)
		);
	}

	/** @param WC_Order $order The order. */
	public static function block_order( $order ): void {
		if ( $order instanceof WC_Order && self::ticked_in_block_checkout( $order ) ) {
			self::subscribe( $order );
		}
	}

	private static function ticked_in_block_checkout( WC_Order $order ): bool {
		try {
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) && class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
				$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );

				return (bool) $fields->get_field_from_object( self::FIELD, $order, 'other' );
			}
		} catch ( Throwable $e ) {
			unset( $e ); // Fall back to the stored meta below.
		}

		return (bool) $order->get_meta( '_wc_other/' . self::FIELD );
	}

	private static function subscribe( WC_Order $order ): void {
		if ( $order->get_meta( '_loreport_signup' ) ) {
			return; // Once per order.
		}
		$box    = LorePort_Settings::checkbox( 'woocommerce' );
		$status = LorePort_Signups::submit(
			array(
				'email'        => $order->get_billing_email(),
				'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'list_id'      => '' !== $box['list'] ? $box['list'] : LorePort_Settings::default_list(),
				'ip'           => $order->get_customer_ip_address(),
				'user_agent'   => $order->get_customer_user_agent(),
				'source'       => 'wordpress:woocommerce:' . $order->get_id(),
				'consent_text' => $box['label'],
			)
		);
		$order->update_meta_data( '_loreport_signup', $status );
		/* translators: %s: signup result, such as "pending" */
		$order->add_order_note( sprintf( __( 'Newsletter signup sent to LorePort (%s).', 'loreport' ), $status ) );
		$order->save();
	}
}
