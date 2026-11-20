<?php
/**
 * FunnelKit banner data source resolver.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations\FunnelKit;

/**
 * Class Sources
 *
 * Resolves the shared email banner source catalog (see
 * Integrations\EmailBannerSources) against FunnelKit's per-recipient
 * send-time data: the data its merge tag loader exposes while it renders
 * an email (cart_details, contact id). Everything degrades to empty
 * strings outside a send, so banners fall back to the canvas defaults
 * instead of breaking.
 */
class Sources {

	/**
	 * Resolves a source key against FunnelKit's current send-time data.
	 *
	 * @param string $key Source key.
	 * @return string Resolved value, or an empty string when unavailable.
	 */
	public static function resolve( string $key ): string {
		switch ( $key ) {
			case 'customer_first_name':
				return self::customer_name( 'first' );
			case 'customer_last_name':
				return self::customer_name( 'last' );
			case 'customer_full_name':
				return trim( self::customer_name( 'first' ) . ' ' . self::customer_name( 'last' ) );
			case 'cart_total':
				return self::format_price( self::cart_field( 'total' ) );
			case 'cart_items_count':
				$count = 0;
				foreach ( self::cart_items() as $item ) {
					$count += (int) ( $item['quantity'] ?? 0 );
				}
				return $count > 0 ? (string) $count : '';
			case 'product_title':
				$product = self::first_product();
				return null !== $product ? $product->get_name() : '';
			case 'product_price':
				$product = self::first_product();
				return null !== $product ? self::format_price( $product->get_price() ) : '';
			case 'product_image':
				$product = self::first_product();
				if ( null === $product || ! function_exists( 'wp_get_attachment_url' ) ) {
					return '';
				}
				$attachment_id = $product->get_image_id();
				return $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
			case 'product_sku':
				$product = self::first_product();
				return null !== $product ? (string) $product->get_sku() : '';
		}

		return '';
	}

	/**
	 * Gets the abandoned-cart row FunnelKit is currently sending for.
	 *
	 * @return array Cart details or an empty array outside a send.
	 */
	public static function cart_details(): array {
		if ( ! class_exists( 'BWFAN_Merge_Tag_Loader' ) ) {
			return array();
		}

		$cart = \BWFAN_Merge_Tag_Loader::get_data( 'cart_details' );

		if ( empty( $cart ) && class_exists( 'BWFAN_Model_Abandonedcarts' ) ) {
			$abandoned_id = \BWFAN_Merge_Tag_Loader::get_data( 'cart_abandoned_id' );
			if ( $abandoned_id ) {
				$cart = \BWFAN_Model_Abandonedcarts::get( $abandoned_id );
			}
		}

		return is_array( $cart ) ? $cart : array();
	}

	/**
	 * Gets the FunnelKit cart recovery URL for the email being sent.
	 *
	 * @return string URL, or an empty string outside a send.
	 */
	public static function recovery_url(): string {
		if ( ! class_exists( 'BWFAN_Common' ) || ! method_exists( 'BWFAN_Common', 'wc_get_cart_recovery_url' ) ) {
			return '';
		}

		$cart = self::cart_details();
		if ( array() === $cart || empty( $cart['token'] ) ) {
			return '';
		}

		$url = \BWFAN_Common::wc_get_cart_recovery_url( $cart['token'], '', '', array() );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Gets one field of the current cart row.
	 *
	 * @param string $key Cart row key.
	 * @return string|null Value or null when absent.
	 */
	protected static function cart_field( string $key ) {
		$cart = self::cart_details();

		return isset( $cart[ $key ] ) ? maybe_unserialize( $cart[ $key ] ) : null;
	}

	/**
	 * Gets the current cart's items.
	 *
	 * @return array Items, each optionally carrying a WC_Product under 'data'.
	 */
	protected static function cart_items(): array {
		$items = self::cart_field( 'items' );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Gets the first cart item's product.
	 *
	 * @return \WC_Product|null Product or null when the cart has no usable items.
	 */
	protected static function first_product(): ?\WC_Product {
		if ( ! class_exists( 'WC_Product' ) ) {
			return null;
		}

		foreach ( self::cart_items() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				return $item['data'];
			}
		}

		return null;
	}

	/**
	 * Resolves the recipient's name.
	 *
	 * Captured checkout fields win (the FunnelKit-native cart source);
	 * the CRM contact is the fallback for logged-in carts where checkout
	 * data was never entered.
	 *
	 * @param string $which 'first' or 'last'.
	 * @return string Name or an empty string.
	 */
	protected static function customer_name( string $which ): string {
		$cart = self::cart_details();

		if ( ! empty( $cart['checkout_data'] ) ) {
			$checkout = json_decode( (string) $cart['checkout_data'], true );

			$field = is_array( $checkout ) && isset( $checkout['fields'][ 'billing_' . $which . '_name' ] )
				? $checkout['fields'][ 'billing_' . $which . '_name' ]
				: '';

			if ( is_string( $field ) && '' !== $field ) {
				return ucwords( $field );
			}
		}

		if ( class_exists( 'WooFunnels_Contact' ) && class_exists( 'BWFAN_Merge_Tag_Loader' ) ) {
			$contact_id = \BWFAN_Merge_Tag_Loader::get_data( 'contact_id' );
			if ( $contact_id ) {
				$contact = new \WooFunnels_Contact( '', '', '', (int) $contact_id );
				if ( $contact instanceof \WooFunnels_Contact && $contact->get_id() > 0 ) {
					$value = 'last' === $which ? $contact->get_l_name() : $contact->get_f_name();

					return is_string( $value ) ? $value : '';
				}
			}
		}

		return '';
	}

	/**
	 * Formats a price email- and URL-safely.
	 *
	 * @param mixed $price Raw price.
	 * @return string Formatted price, or an empty string when unavailable.
	 */
	protected static function format_price( $price ): string {
		if ( null === $price || '' === $price ) {
			return '';
		}

		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $price;
		}

		return wp_strip_all_tags( (string) wc_price( (float) $price ) );
	}
}
