<?php
/**
 * Abandoned Cart Lite (Tyche) send context builder.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations\Wcap;

use OGDynamic\Integrations\SendContext;

/**
 * Class Sources
 *
 * Builds the normalized SendContext from the abandoned-cart row Tyche's
 * cron is currently emailing.
 *
 * The recovery link is captured on Tyche's `cart.link` merge-tag filter,
 * which fires exactly once per real email render, immediately before the
 * `products.cart` filter our banner renders on. The finished track URL is
 * the only place the link exists — it embeds Tyche's sent-history row id
 * and an HMAC of it, so it cannot be rebuilt independently. Preview and
 * "send test" emails bypass both filters entirely (Tyche sends the raw
 * template body), so they can never leak a previous recipient's capture.
 */
class Sources {

	/**
	 * Captured cart row for the render stage (null = none this render).
	 *
	 * @var object|null
	 */
	protected static ?object $cart = null;

	/**
	 * Captured cart recovery link (empty = none this render).
	 *
	 * @var string
	 */
	protected static string $recovery_url = '';

	/**
	 * Capture callback for the `cart.link` merge-tag filter.
	 *
	 * @param string $url Finished recovery track URL.
	 * @return string The URL, unchanged.
	 */
	public static function capture_link( string $url ): string {
		self::$recovery_url = $url;

		return $url;
	}

	/**
	 * Sets the cart row the render stage builds its context from.
	 *
	 * @param object $cart Row from Tyche's abandoned cart history table.
	 * @return void
	 */
	public static function set_cart( object $cart ): void {
		self::$cart = $cart;
	}

	/**
	 * Clears the captured state after the email render.
	 *
	 * Runs on every products.cart render — banner or not — so a following
	 * render in the same process can never inherit the previous
	 * recipient's data.
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$cart         = null;
		self::$recovery_url = '';
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

		list( $first_name, $last_name ) = self::customer_names( $cart );

		$items = self::items( $cart );
		$first = self::product( $items[0] ?? null );

		$count = 0;
		$total = 0.0;

		foreach ( $items as $item ) {
			$count += (int) $item['quantity'];
			$total += (float) $item['line_total'];
		}

		return SendContext::from_array(
			array(
				'customer_first_name' => $first_name,
				'customer_last_name'  => $last_name,
				'cart_total'          => $total > 0 ? $total : null,
				'cart_currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
				'cart_items_count'    => $count > 0 ? $count : null,
				'first_product_title' => $first['title'] ?? null,
				'first_product_price' => $first['price'] ?? null,
				'first_product_image' => $first['image'] ?? null,
				'first_product_sku'   => $first['sku'] ?? null,
				'recovery_url'        => self::recipient_recovery_url( $cart ),
			)
		);
	}

	/**
	 * The captured recovery link — but only when it belongs to this
	 * recipient.
	 *
	 * The capture happens on Tyche's `cart.link` tag, and a hand-edited
	 * template can keep `{{products.cart}}` while dropping `{{cart.link}}`;
	 * that render would then inherit the PREVIOUS recipient's captured
	 * link and email a banner restoring someone else's cart. Tyche builds
	 * the track URL with `user_email={recipient}`, so the URL's owner is
	 * checked against the cart row being emailed (the row's user_email is
	 * always set by the cron before the filters fire — both its guest and
	 * registered branches assign it first). No match (or no email to
	 * check) means no link: the banner renders unlinked rather than
	 * pointing at the wrong cart.
	 *
	 * @param object $cart Cart row.
	 * @return string|null Recovery URL owned by this recipient, or null.
	 */
	protected static function recipient_recovery_url( object $cart ): ?string {
		$url   = self::$recovery_url;
		$email = isset( $cart->user_email ) ? (string) $cart->user_email : '';

		if ( '' === $url || '' === $email ) {
			return null;
		}

		if ( false === strpos( $url, $email ) && false === strpos( $url, rawurlencode( $email ) ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Resolves the recipient's names from the cart row.
	 *
	 * Mirrors Tyche's own resolution: guests' names live in their guest
	 * table keyed by the row's user_id; registered customers prefer the
	 * billing user meta and fall back to the account name.
	 *
	 * @param object $cart Cart row.
	 * @return array{0: string|null, 1: string|null} First and last name.
	 */
	protected static function customer_names( object $cart ): array {
		$user_id = isset( $cart->user_id ) ? (int) $cart->user_id : 0;

		if ( isset( $cart->user_type ) && 'GUEST' === $cart->user_type ) {
			if ( $user_id < 1 ) {
				return array( null, null );
			}

			global $wpdb;

			$table = $wpdb->prefix . 'ac_guest_abandoned_cart_history_lite';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + fixed suffix, not user input
			$sql = $wpdb->prepare( "SELECT billing_first_name, billing_last_name FROM {$table} WHERE id = %d", $user_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- single-row lookup per email render
			$guest = $wpdb->get_row( $sql );

			if ( null === $guest ) {
				return array( null, null );
			}

			return array(
				'' !== (string) $guest->billing_first_name ? (string) $guest->billing_first_name : null,
				'' !== (string) $guest->billing_last_name ? (string) $guest->billing_last_name : null,
			);
		}

		if ( $user_id < 1 ) {
			return array( null, null );
		}

		$first = get_user_meta( $user_id, 'billing_first_name', true );
		$last  = get_user_meta( $user_id, 'billing_last_name', true );
		$user  = null;

		if ( '' === $first || '' === $last ) {
			$user = get_userdata( $user_id );
		}

		$first = '' !== $first ? $first : (string) ( $user->first_name ?? '' );
		$last  = '' !== $last ? $last : (string) ( $user->last_name ?? '' );

		return array(
			'' !== $first ? $first : null,
			'' !== $last ? $last : null,
		);
	}

	/**
	 * Decodes the cart row's serialized cart contents.
	 *
	 * @param object $cart Cart row.
	 * @return array Items: {product_id, variation_id, quantity, line_total}.
	 */
	protected static function items( object $cart ): array {
		if ( empty( $cart->abandoned_cart_info ) ) {
			return array();
		}

		$info = json_decode( (string) $cart->abandoned_cart_info );

		if ( ! isset( $info->cart ) || ! is_object( $info->cart ) ) {
			return array();
		}

		$items = array();

		foreach ( get_object_vars( $info->cart ) as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$items[] = array(
				'product_id'   => (int) ( $item->product_id ?? 0 ),
				'variation_id' => (int) ( $item->variation_id ?? 0 ),
				'quantity'     => (int) ( $item->quantity ?? 0 ),
				'line_total'   => (float) ( $item->line_total ?? 0 ),
			);
		}

		return $items;
	}

	/**
	 * Resolves an item's live product data (variation first, parent
	 * fallback — the parent is what Tyche's own table renders).
	 *
	 * @param array|null $item Cart item.
	 * @return array {title, price, image, sku} — empty when unresolvable.
	 */
	protected static function product( ?array $item ): array {
		if ( null === $item || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = null;

		foreach ( array_filter( array_unique( array( $item['variation_id'], $item['product_id'] ) ) ) as $id ) {
			$candidate = wc_get_product( (int) $id );

			if ( $candidate instanceof \WC_Product ) {
				$product = $candidate;
				break;
			}
		}

		if ( null === $product ) {
			return array();
		}

		$image = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'large' ) : '';

		// A variation without its own photo shows the parent product's —
		// the same image Tyche's cart table renders for the line.
		if ( '' === $image && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent instanceof \WC_Product && $parent->get_image_id() ) {
				$image = wp_get_attachment_image_url( $parent->get_image_id(), 'large' );
			}
		}

		$sku = $product->get_sku();

		return array(
			'title' => $product->get_name(),
			'price' => (float) $product->get_price(),
			'image' => '' !== $image ? $image : null,
			'sku'   => '' !== $sku ? $sku : null,
		);
	}
}
