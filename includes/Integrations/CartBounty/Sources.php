<?php
/**
 * CartBounty send context builder.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations\CartBounty;

use OGDynamic\Integrations\SendContext;

/**
 * Class Sources
 *
 * Captures the abandoned cart CartBounty is currently emailing and builds
 * the normalized SendContext from its saved row.
 *
 * Capture rides the `cartbounty_automation_unsubscribe_url` filter: it
 * fires exactly once per real recovery email render (inside
 * get_selected_template(), right before the email template includes run),
 * and the URL it receives ends with "-{cart_id}", which identifies the
 * row in CartBounty's own table. The banner then renders on the
 * `cartbounty_automation_after_intro` action in the same request.
 *
 * Test/preview emails render without a cart, so no capture happens and
 * the banner falls back to the canvas defaults.
 */
class Sources {

	/**
	 * Captured cart row for the render stage (null = none this render).
	 *
	 * @var object|null
	 */
	protected static ?object $cart = null;

	/**
	 * Snapshot of the captured cart for the wp_mail stage.
	 *
	 * The render capture is cleared as soon as the banner action has
	 * fired, but the `[ogdynamic_banner]` token in the email body is
	 * expanded later, on the wp_mail filter — this snapshot carries the
	 * cart there and is consumed by exactly one mail send.
	 *
	 * @var object|null
	 */
	protected static ?object $mail_cart = null;

	/**
	 * Capture callback for `cartbounty_automation_unsubscribe_url`.
	 *
	 * @param string $url Unsubscribe URL ending in "-{cart_id}".
	 * @return string The URL, unchanged.
	 */
	public static function capture( string $url ): string {
		self::$cart = null;

		// URL shape: {site}?cartbounty={hmac hash}-{cart_id}&cartbounty-unsubscribe=1
		// The hmac is hex (no dashes), so the first dash-digits after the
		// cartbounty param is always the cart row id.
		if ( ! preg_match( '/cartbounty=[^&]*-(\d+)/', $url, $matches ) ) {
			return $url;
		}

		$cart_id = (int) $matches[1];
		if ( $cart_id < 1 || ! defined( 'CARTBOUNTY_TABLE_NAME' ) ) {
			return $url;
		}

		global $wpdb;

		$table = $wpdb->prefix . CARTBOUNTY_TABLE_NAME;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + plugin constant, not user input
			"SELECT id, name, surname, email, cart_contents, cart_total, currency, session_id FROM {$table} WHERE id = %d",
			$cart_id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- single-row lookup per email render
		$cart = $wpdb->get_row( $sql );

		if ( null !== $cart && ! empty( $cart->email ) ) {
			self::$cart      = $cart;
			self::$mail_cart = $cart;
		}

		return $url;
	}

	/**
	 * Consumes the mail-stage cart snapshot.
	 *
	 * Returned only when the mail recipient matches the captured cart's
	 * email — a later test/preview send to another address must never
	 * receive a previous recipient's personalization. The snapshot is
	 * consumed (one mail per capture) either way.
	 *
	 * @param string $to Mail recipient.
	 * @return object|null Cart row when the recipient matches, else null.
	 */
	public static function mail_cart( string $to ): ?object {
		$cart            = self::$mail_cart;
		self::$mail_cart = null;

		if ( null === $cart ) {
			return null;
		}

		return 0 === strcasecmp( (string) $to, (string) $cart->email ) ? $cart : null;
	}

	/**
	 * Restores a mail-stage snapshot as the render cart.
	 *
	 * Token expansion on the wp_mail filter happens after render_banner()
	 * cleared the render capture; this puts the recipient-matched cart
	 * back so context resolution personalizes the token correctly.
	 *
	 * @param object $cart Cart row.
	 * @return void
	 */
	public static function set_render_cart( object $cart ): void {
		self::$cart = $cart;
	}

	/**
	 * Clears the captured cart after the banner render.
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$cart = null;
	}

	/**
	 * Builds the send context from the captured cart row.
	 *
	 * @return SendContext Empty context outside a real email send.
	 */
	public static function context(): SendContext {
		$cart = self::$cart;

		if ( null === $cart ) {
			return SendContext::from_array( array() );
		}

		$products = self::products( $cart );
		$first    = array();

		foreach ( $products as $product ) {
			if ( is_array( $product ) && ! empty( $product['product_title'] ) ) {
				$first = $product;
				break;
			}
		}

		$count = 0;
		foreach ( $products as $product ) {
			if ( is_array( $product ) ) {
				$count += (int) ( $product['quantity'] ?? 0 );
			}
		}

		return SendContext::from_array(
			array(
				'customer_first_name' => isset( $cart->name ) ? $cart->name : null,
				'customer_last_name'  => isset( $cart->surname ) ? $cart->surname : null,
				'cart_total'          => isset( $cart->cart_total ) ? (float) $cart->cart_total : null,
				'cart_currency'       => isset( $cart->currency ) ? $cart->currency : null,
				'cart_items_count'    => $count > 0 ? $count : null,
				'first_product_title' => $first['product_title'] ?? null,
				'first_product_price' => self::product_price( $first ),
				'first_product_image' => self::product_image( $first ),
				'first_product_sku'   => self::product_sku( $first ),
				'recovery_url'        => self::recovery_url( $cart ),
			)
		);
	}

	/**
	 * Rebuilds CartBounty's cart recovery URL for the captured row.
	 *
	 * Mirrors Cartbounty_Admin::create_cart_url(): hmac of email + session
	 * id, with the cart row id appended.
	 *
	 * @param object $cart Cart row.
	 * @return string|null URL, or null when the key constant is missing.
	 */
	protected static function recovery_url( object $cart ): ?string {
		if ( ! defined( 'CARTBOUNTY_ENCRYPTION_KEY' ) || ! function_exists( 'wc_get_cart_url' ) ) {
			return null;
		}

		$hash = hash_hmac( 'sha256', $cart->email . $cart->session_id, CARTBOUNTY_ENCRYPTION_KEY ) . '-' . $cart->id;

		return wc_get_cart_url() . '?cartbounty=' . $hash;
	}

	/**
	 * Unserializes the cart's product entries.
	 *
	 * @param object $cart Cart row.
	 * @return array Product arrays; empty when the cart has no contents.
	 */
	protected static function products( object $cart ): array {
		if ( empty( $cart->cart_contents ) ) {
			return array();
		}

		$contents = maybe_unserialize( $cart->cart_contents );

		// Since v8.8 products may be nested under a 'products' key sharing
		// the column with cart meta (see get_saved_cart_contents()).
		if ( is_array( $contents ) && isset( $contents['products'] ) ) {
			$contents = $contents['products'];
		}

		return is_array( $contents ) ? $contents : array();
	}

	/**
	 * Gets a product entry's price.
	 *
	 * @param array $product Product entry.
	 * @return float|null Price, or null when unknown.
	 */
	protected static function product_price( array $product ): ?float {
		if ( empty( $product['product_variation_price'] ) ) {
			return null;
		}

		return (float) $product['product_variation_price'];
	}

	/**
	 * Gets a product entry's image URL.
	 *
	 * Mirrors Cartbounty_Admin::get_product_thumbnail_url(): the variation
	 * image wins, then the product's; no placeholder fallback — an empty
	 * value lets the canvas default render.
	 *
	 * @param array $product Product entry.
	 * @return string|null URL, or null when unavailable.
	 */
	protected static function product_image( array $product ): ?string {
		if ( empty( $product['product_id'] ) ) {
			return null;
		}

		if ( ! empty( $product['product_variation_id'] ) ) {
			$image = get_the_post_thumbnail_url( (int) $product['product_variation_id'], 'large' );

			if ( ! empty( $image ) ) {
				return $image;
			}
		}

		$image = get_the_post_thumbnail_url( (int) $product['product_id'], 'large' );

		return ! empty( $image ) ? $image : null;
	}

	/**
	 * Gets a product entry's SKU.
	 *
	 * @param array $product Product entry.
	 * @return string|null SKU, or null when unavailable.
	 */
	protected static function product_sku( array $product ): ?string {
		if ( empty( $product['product_id'] ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product_object = wc_get_product( (int) $product['product_id'] );

		if ( ! $product_object instanceof \WC_Product ) {
			return null;
		}

		$sku = $product_object->get_sku();

		return '' !== $sku ? $sku : null;
	}
}
