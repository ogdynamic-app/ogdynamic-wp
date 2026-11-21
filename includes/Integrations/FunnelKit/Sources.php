<?php
/**
 * FunnelKit send context builder.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations\FunnelKit;

use OGDynamic\Integrations\SendContext;

/**
 * Class Sources
 *
 * Extracts FunnelKit's per-recipient send-time data — whatever its merge
 * tag loader exposes while it renders an email (cart_details, contact
 * id) — into a normalized SendContext. Extraction only: formatting and
 * catalog-key resolution happen centrally in SendContext. Outside a
 * send, everything resolves to an empty context.
 */
class Sources {

	/**
	 * Builds the send context from FunnelKit's current send-time data.
	 *
	 * @return SendContext
	 */
	public static function context(): SendContext {
		$cart = self::cart_details();

		if ( array() === $cart ) {
			return SendContext::from_array( array() );
		}

		$items   = isset( $cart['items'] ) ? maybe_unserialize( $cart['items'] ) : array();
		$items   = is_array( $items ) ? $items : array();
		$product = self::first_product( $items );

		return SendContext::from_array(
			array(
				'customer_first_name' => self::customer_name( 'first', $cart ),
				'customer_last_name'  => self::customer_name( 'last', $cart ),
				'cart_total'          => isset( $cart['total'] ) ? maybe_unserialize( $cart['total'] ) : null,
				'cart_currency'       => isset( $cart['currency'] ) ? maybe_unserialize( $cart['currency'] ) : null,
				'cart_items_count'    => self::items_count( $items ),
				'first_product_title' => null !== $product ? $product->get_name() : null,
				'first_product_price' => null !== $product && null !== $product->get_price() ? (float) $product->get_price() : null,
				'first_product_image' => self::product_image( $product ),
				'first_product_sku'   => null !== $product ? $product->get_sku() : null,
				'recovery_url'        => self::recovery_url( $cart ),
			)
		);
	}

	/**
	 * Gets the abandoned-cart row FunnelKit is currently sending for.
	 *
	 * @return array Cart details or an empty array outside a send.
	 */
	protected static function cart_details(): array {
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
	 * @param array $cart Cart details.
	 * @return string|null URL, or null outside a send.
	 */
	protected static function recovery_url( array $cart ): ?string {
		if ( ! class_exists( 'BWFAN_Common' ) || ! method_exists( 'BWFAN_Common', 'wc_get_cart_recovery_url' ) ) {
			return null;
		}

		if ( empty( $cart['token'] ) ) {
			return null;
		}

		$url = \BWFAN_Common::wc_get_cart_recovery_url( $cart['token'], '', '', array() );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Sums the cart's item quantities.
	 *
	 * @param array $items Cart items.
	 * @return int|null Total quantity, or null when the cart has no items.
	 */
	protected static function items_count( array $items ): ?int {
		if ( array() === $items ) {
			return null;
		}

		$count = 0;

		foreach ( $items as $item ) {
			$count += (int) ( $item['quantity'] ?? 0 );
		}

		return $count > 0 ? $count : null;
	}

	/**
	 * Gets the first cart item's product.
	 *
	 * @param array $items Cart items.
	 * @return \WC_Product|null Product or null when the cart has no usable items.
	 */
	protected static function first_product( array $items ): ?\WC_Product {
		if ( ! class_exists( 'WC_Product' ) ) {
			return null;
		}

		foreach ( $items as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				return $item['data'];
			}
		}

		return null;
	}

	/**
	 * Gets the product's featured image URL.
	 *
	 * @param \WC_Product|null $product Product.
	 * @return string|null URL, or null when unavailable.
	 */
	protected static function product_image( ?\WC_Product $product ): ?string {
		if ( null === $product || ! function_exists( 'wp_get_attachment_url' ) ) {
			return null;
		}

		$attachment_id = $product->get_image_id();

		return $attachment_id ? wp_get_attachment_url( $attachment_id ) : null;
	}

	/**
	 * Extracts the recipient's name.
	 *
	 * Captured checkout fields win (the FunnelKit-native cart source);
	 * the CRM contact is the fallback for logged-in carts where checkout
	 * data was never entered. Raw value — presentation is centralized.
	 *
	 * @param string $which 'first' or 'last'.
	 * @param array  $cart  Cart details.
	 * @return string|null Name or null when unknown.
	 */
	protected static function customer_name( string $which, array $cart ): ?string {
		if ( ! empty( $cart['checkout_data'] ) ) {
			$checkout = json_decode( (string) $cart['checkout_data'], true );

			$field = is_array( $checkout ) && isset( $checkout['fields'][ 'billing_' . $which . '_name' ] )
				? $checkout['fields'][ 'billing_' . $which . '_name' ]
				: '';

			if ( is_string( $field ) && '' !== $field ) {
				return $field;
			}
		}

		if ( class_exists( 'WooFunnels_Contact' ) && class_exists( 'BWFAN_Merge_Tag_Loader' ) ) {
			$contact_id = \BWFAN_Merge_Tag_Loader::get_data( 'contact_id' );
			if ( $contact_id ) {
				$contact = new \WooFunnels_Contact( '', '', '', (int) $contact_id );
				if ( $contact instanceof \WooFunnels_Contact && $contact->get_id() > 0 ) {
					$value = 'last' === $which ? $contact->get_l_name() : $contact->get_f_name();

					return is_string( $value ) && '' !== $value ? $value : null;
				}
			}
		}

		return null;
	}
}
