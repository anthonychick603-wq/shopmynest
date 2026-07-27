<?php
/**
 * Web checkout: per-seller live shipping rates.
 *
 * Splits the WooCommerce shipping packages one-per-seller and quotes each
 * package by calling the same seller-aware Shippo function the mobile app
 * uses (mnu_native_get_live_shipping_rates in class-mnu-native-checkout.php).
 * That way both the app and the standard web checkout return real
 * seller -> buyer rates, not store-origin rates.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Split the cart's shipping packages into one package per seller.
 *
 * WooCommerce, by default, produces exactly one package for the whole cart
 * (or one per shipping class). For a marketplace we need each seller's items
 * in their own package so Shippo can rate them with that seller's origin.
 *
 * @param array<int, array<string, mixed>> $packages
 * @return array<int, array<string, mixed>>
 */
function mnu_web_split_packages_by_seller( array $packages ): array {
	if ( ! function_exists( 'tnm_get_product_seller_id' ) ) {
		return $packages;
	}
	if ( empty( $packages ) ) {
		return $packages;
	}

	$new = array();

	foreach ( $packages as $package ) {
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		if ( ! $contents ) {
			$new[] = $package;
			continue;
		}

		$groups = array();
		foreach ( $contents as $cart_item_key => $item ) {
			$product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$seller_id                     = (int) tnm_get_product_seller_id( $product );
			$groups[ $seller_id ][ $cart_item_key ] = $item;
		}

		if ( count( $groups ) <= 1 ) {
			// Single seller (or unresolved): keep the package as-is but tag it
			// so the rate filter knows which seller to quote.
			$seller_ids            = array_keys( $groups );
			$package['mnu_seller'] = (int) ( $seller_ids[0] ?? 0 );
			$new[]                 = $package;
			continue;
		}

		foreach ( $groups as $seller_id => $seller_contents ) {
			$totals = array(
				'contents_cost'    => 0.0,
				'contents_tax'     => 0.0,
				'contents_taxes'   => array(),
			);
			foreach ( $seller_contents as $item ) {
				$totals['contents_cost'] += (float) ( $item['line_total'] ?? 0 );
				$totals['contents_tax']  += (float) ( $item['line_tax'] ?? 0 );
			}

			$split                 = $package;
			$split['contents']     = $seller_contents;
			$split['contents_cost'] = $totals['contents_cost'];
			$split['mnu_seller']   = (int) $seller_id;
			$new[]                 = $split;
		}
	}

	return $new;
}
add_filter( 'woocommerce_cart_shipping_packages', 'mnu_web_split_packages_by_seller', 20 );

/**
 * Build the destination address array for a package in the shape
 * mnu_native_get_live_shipping_rates() expects.
 *
 * @param array<string, mixed> $package
 * @return array<string, string>
 */
function mnu_web_destination_from_package( array $package ): array {
	$dest = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();

	return array(
		'first_name' => (string) ( $dest['first_name'] ?? '' ),
		'last_name'  => (string) ( $dest['last_name'] ?? '' ),
		'address_1'  => (string) ( $dest['address'] ?? $dest['address_1'] ?? '' ),
		'address_2'  => (string) ( $dest['address_2'] ?? '' ),
		'city'       => (string) ( $dest['city'] ?? '' ),
		'state'      => (string) ( $dest['state'] ?? '' ),
		'postcode'   => (string) ( $dest['postcode'] ?? '' ),
		'country'    => (string) ( $dest['country'] ?? '' ),
		'phone'      => (string) ( $dest['phone'] ?? '' ),
	);
}

/**
 * Build the cart-line array for a package in the shape
 * mnu_native_get_live_shipping_rates() expects.
 *
 * @param array<string, mixed> $package
 * @return array<int, array<string, mixed>>
 */
function mnu_web_lines_from_package( array $package ): array {
	$lines    = array();
	$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
	foreach ( $contents as $item ) {
		$product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
		if ( ! $product ) {
			continue;
		}
		$lines[] = array(
			'product'    => $product,
			'product_id' => (int) $product->get_id(),
			'quantity'   => (int) ( $item['quantity'] ?? 1 ),
		);
	}
	return $lines;
}

/**
 * Signature used to cache Shippo lookups within a single request. Live rates
 * for the same package and address should not be requested more than once
 * per page load.
 *
 * @param array<string, mixed> $package
 */
function mnu_web_rate_signature( array $package ): string {
	$key = array(
		'seller'      => (int) ( $package['mnu_seller'] ?? 0 ),
		'destination' => mnu_web_destination_from_package( $package ),
		'lines'       => array(),
	);
	foreach ( mnu_web_lines_from_package( $package ) as $line ) {
		$key['lines'][] = array(
			'id'  => $line['product_id'],
			'qty' => $line['quantity'],
		);
	}
	return md5( wp_json_encode( $key ) );
}

/**
 * Replace the rates WooCommerce would show for a package with the live
 * per-seller Shippo rates.
 *
 * @param array<string, WC_Shipping_Rate> $rates
 * @param array<string, mixed>            $package
 * @return array<string, WC_Shipping_Rate>
 */
function mnu_web_replace_package_rates( array $rates, array $package ): array {
	static $cache = array();

	if ( ! function_exists( 'mnu_native_get_live_shipping_rates' ) ) {
		return $rates;
	}

	$signature = mnu_web_rate_signature( $package );
	if ( isset( $cache[ $signature ] ) ) {
		return $cache[ $signature ];
	}

	$lines   = mnu_web_lines_from_package( $package );
	$address = mnu_web_destination_from_package( $package );
	if ( ! $lines || '' === $address['country'] ) {
		$cache[ $signature ] = $rates;
		return $rates;
	}

	$options = mnu_native_get_live_shipping_rates( $lines, $address );

	if ( is_wp_error( $options ) || ! is_array( $options ) || ! $options ) {
		// Live rates unavailable: emit no rates and let WooCommerce show
		// the standard "no shipping options" message. Log for admins.
		if ( function_exists( 'mnu_native_shipping_debug_log' ) ) {
			$reason = is_wp_error( $options ) ? $options->get_error_message() : 'no rates returned';
			mnu_native_shipping_debug_log( sprintf( 'Web checkout: no live rates for package (seller %d): %s', (int) ( $package['mnu_seller'] ?? 0 ), $reason ) );
		}
		$cache[ $signature ] = array();
		return array();
	}

	$out       = array();
	$seller_id = (int) ( $package['mnu_seller'] ?? 0 );
	foreach ( $options as $option ) {
		$rate_id = 'mnu_shippo:' . $seller_id . ':' . sanitize_key( (string) ( $option['id'] ?? uniqid( 'r', true ) ) );
		$rate    = new WC_Shipping_Rate(
			$rate_id,
			(string) ( $option['label'] ?? 'Shipping' ),
			(float) ( $option['amount'] ?? 0 ),
			array(),
			'mnu_shippo',
			0
		);
		$rate->add_meta_data( 'seller_id', (string) $seller_id );
		if ( ! empty( $option['id'] ) ) {
			$rate->add_meta_data( 'shippo_option_id', (string) $option['id'] );
		}
		$out[ $rate_id ] = $rate;
	}

	$cache[ $signature ] = $out;
	return $out;
}
add_filter( 'woocommerce_package_rates', 'mnu_web_replace_package_rates', 20, 2 );

/**
 * Declare the mnu_shippo method so WooCommerce recognizes it as a valid
 * shipping method when it rebuilds session shipping choices. Registered as
 * a lightweight no-op class because the rate objects are produced in the
 * filter above rather than by a full method's calculate_shipping() call.
 */
add_filter(
	'woocommerce_shipping_methods',
	static function ( array $methods ): array {
		if ( ! class_exists( 'MNU_Web_Shippo_Method' ) ) {
			class MNU_Web_Shippo_Method extends WC_Shipping_Method {
				public function __construct( $instance_id = 0 ) {
					$this->id                 = 'mnu_shippo';
					$this->instance_id        = absint( $instance_id );
					$this->method_title       = 'MyNest Live Shipping';
					$this->method_description = 'Per-seller live shipping rates from Shippo.';
					$this->supports           = array( 'shipping-zones' );
					$this->enabled            = 'yes';
					$this->title              = 'MyNest Live Shipping';
				}
				public function calculate_shipping( $package = array() ) {
					// Rates are produced by the woocommerce_package_rates filter.
				}
			}
		}
		$methods['mnu_shippo'] = 'MNU_Web_Shippo_Method';
		return $methods;
	}
);
