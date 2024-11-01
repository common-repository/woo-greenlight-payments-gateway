<?php
/**
 * Plugin Name:       Payments Gateway for Greenlight on WooCommerce
 * Plugin URI:        https://www.greenlightpayments.com
 * Description:       Receive payments using Greenlight Payments.
 * Version:           1.0.0
 * Author:            AnnexCore
 * Author URI:        https://annexcore.com
 * License:           GPL-2.0+
 * @package Greenlight
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'glwc_woo_greenlight_payment_class', 11 );

/**
 * Add the payment gateway class.
 */
function glwc_woo_greenlight_payment_class() {
	// Make sure WooCommerce is active.
	if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
		return;
	}
	if ( ! class_exists( 'WC_Greenlight_Payments' ) ) {
		include_once plugin_dir_path( __FILE__ ) . '/classes/class-wc-greenlight-payments.php';
	}
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways.
 * @return array $gateways all WC gateways + greenlight gateway
 */
function glwc_add_greenlight_to_payment_methods( $gateways ) {
	$gateways[] = 'WC_Greenlight_Payments';
	return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'glwc_add_greenlight_to_payment_methods' );