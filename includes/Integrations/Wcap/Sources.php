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
 * which the cron fires for every email shortly before the `products.cart`
 * filter our banner renders on, passing the same cart row to both. The
 * finished track URL is the only place the link exists — it embeds
 * Tyche's sent-history row id and an HMAC of it, so it cannot be rebuilt
 * independently.
 *
 * Amounts are the amounts Tyche's own cart table prints in the same email
 * (see line_amount()), never live product prices — the banner sits next
 * to that table and must not quote different numbers.
 */
class Sources {

	/**
	 * Captured cart row for the render stage (null = none this render).
	 *
	 * @var object|null
	 */
	protected static ?object $cart = null;

	/**
	 * The captured row's items whose product still exists — exactly the
	 * rows Tyche's cart table renders.
	 *
	 * @var array[]
	 */
	protected static array $items = array();

	/**
	 * Captured cart recovery link (empty = none this render).
	 *
	 * @var string
	 */
	protected static string $recovery_url = '';

	/**
	 * Id of the cart row the recovery link was captured for (0 = unknown).
	 *
	 * @var int
	 */
	protected static int $recovery_cart_id = 0;

	/**
	 * Capture callback for the `cart.link` merge-tag filter.
	 *
	 * The filter also passes the cart row being emailed; its id is kept
	 * with the link so the render stage only ever uses a link captured
	 * for the very row it renders (see recipient_recovery_url()).
	 *
	 * @param string      $url    Finished recovery track URL.
	 * @param mixed       $unused Tyche's literal false placeholder argument.
	 * @param object|null $cart   Row from Tyche's abandoned cart history table.
	 * @return string The URL, unchanged.
	 */
	public static function capture_link( string $url, $unused = false, $cart = null ): string {
		self::$recovery_url     = $url;
		self::$recovery_cart_id = is_object( $cart ) && isset( $cart->id ) ? (int) $cart->id : 0;

		return $url;
	}

	/**
	 * Sets the cart row the render stage builds its context from.
	 *
	 * The row's renderable items are resolved here, once.
	 *
	 * @param object $cart Row from Tyche's abandoned cart history table.
	 * @return void
	 */
	public static function set_cart( object $cart ): void {
		self::$cart  = $cart;
		self::$items = self::renderable_items( $cart );
	}

	/**
	 * Whether the cart has anything to show — at least one item whose
	 * product still exists.
	 *
	 * Tyche replaces its whole cart table with a "product no longer exists"
	 * notice when none does, and the banner steps aside with it: one quoting
	 * a cart total above that notice would promise a cart the customer
	 * cannot recover.
	 *
	 * @return bool
	 */
	public static function has_items(): bool {
		return array() !== self::$items;
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
		self::$cart             = null;
		self::$items            = array();
		self::$recovery_url     = '';
		self::$recovery_cart_id = 0;
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

		$items = self::$items;
		$first = self::product( $items[0] ?? null );
		$mode  = self::tax_mode();
		$count = 0;

		foreach ( $items as $item ) {
			$count += (int) $item['quantity'];
		}

		return SendContext::from_array(
			array(
				'customer_first_name' => $first_name,
				'customer_last_name'  => $last_name,
				'cart_total'          => self::cart_total( $items, $mode ),
				'cart_currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
				'cart_items_count'    => $count > 0 ? $count : null,
				'first_product_title' => $first['title'] ?? null,
				'first_product_price' => isset( $items[0] ) ? self::unit_price( $items[0], $mode ) : null,
				'first_product_image' => $first['image'] ?? null,
				'first_product_sku'   => $first['sku'] ?? null,
				'recovery_url'        => self::recipient_recovery_url( $cart ),
			)
		);
	}

	/**
	 * The captured recovery link — but only when it was captured for this
	 * very cart row.
	 *
	 * Tyche's cron fires the link filter for a row and, a few lines later,
	 * the block filter for that same row, so in its real send loop the two
	 * always pair up. The capture is cleared on every block render — but a
	 * template that sends `{{cart.link}}` without `{{products.cart}}`
	 * leaves its capture in place, because nothing renders to clear it.
	 * The cron itself never uses such a lingering link (each block render is
	 * preceded by its own row's capture), yet the block filter is public
	 * API: any other caller rendering it for a different row would inherit
	 * it and email a banner restoring someone else's cart. The row id
	 * travels with the capture, so a mismatch means no link: the banner
	 * renders unlinked rather than pointing at the wrong cart.
	 *
	 * @param object $cart Cart row.
	 * @return string|null Recovery URL captured for this row, or null.
	 */
	protected static function recipient_recovery_url( object $cart ): ?string {
		if ( '' === self::$recovery_url || ! isset( $cart->id ) ) {
			return null;
		}

		if ( (int) $cart->id !== self::$recovery_cart_id ) {
			return null;
		}

		return self::$recovery_url;
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
	 * Tyche stores WooCommerce's own cart contents, so every line carries
	 * the abandonment-time amounts WooCommerce computed for it.
	 *
	 * @param object $cart Cart row.
	 * @return array[] Items: {product_id, variation_id, quantity,
	 *                       line_total, line_subtotal, line_tax, line_subtotal_tax}.
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
				'product_id'        => (int) ( $item->product_id ?? 0 ),
				'variation_id'      => (int) ( $item->variation_id ?? 0 ),
				'quantity'          => (int) ( $item->quantity ?? 0 ),
				'line_total'        => (float) ( $item->line_total ?? 0 ),
				'line_subtotal'     => (float) ( $item->line_subtotal ?? 0 ),
				'line_tax'          => (float) ( $item->line_tax ?? 0 ),
				'line_subtotal_tax' => (float) ( $item->line_subtotal_tax ?? 0 ),
			);
		}

		return $items;
	}

	/**
	 * The row's items whose product still exists — exactly the rows
	 * Tyche's cart table renders.
	 *
	 * The table looks the parent product up for a variation line, so the
	 * same lookup decides here; a line whose product is gone contributes
	 * nothing to the table and nothing to the banner.
	 *
	 * @param object $cart Cart row.
	 * @return array[] Renderable items.
	 */
	protected static function renderable_items( object $cart ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$items = array();

		foreach ( self::items( $cart ) as $item ) {
			if ( wc_get_product( $item['product_id'] ) instanceof \WC_Product ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * The cart total exactly as Tyche's cart table computes it.
	 *
	 * @param array[] $items Renderable items.
	 * @param array   $mode  From tax_mode().
	 * @return float|null Total, or null when it is not a positive amount.
	 */
	protected static function cart_total( array $items, array $mode ): ?float {
		$total = 0.0;
		$tax   = 0.0;

		foreach ( $items as $item ) {
			$total += self::line_amount( $item, $mode['tested_tax'] );
			$tax   += (float) $item['line_tax'];
		}

		if ( $mode['tax_row'] ) {
			$total += $tax;
		}

		return $total > 0 ? $total : null;
	}

	/**
	 * An item's unit price — the table's "Price" column.
	 *
	 * @param array $item Renderable item.
	 * @param array $mode From tax_mode().
	 * @return float|null Price, or null without a positive quantity.
	 */
	protected static function unit_price( array $item, array $mode ): ?float {
		$quantity = (int) $item['quantity'];

		if ( $quantity < 1 ) {
			return null;
		}

		return self::line_amount( $item, $mode['tested_tax'] ) / $quantity;
	}

	/**
	 * A line's amount exactly as Tyche's cart table derives it.
	 *
	 * The banner sits next to that table in the same email, so its numbers
	 * must be the table's. Tyche computes them from the abandonment-time
	 * line fields WooCommerce stored with the cart — never the live
	 * product price, which may have changed since or leave out a discount
	 * that was applied — and which fields count depends on the store's tax
	 * settings. This mirrors that arithmetic (the cron's item loop, the
	 * "Line Subtotal" column).
	 *
	 * @param array  $item       Renderable item.
	 * @param string $tested_tax Line-tax field deciding whether the stored
	 *                           subtotal (pre-discount) plus its tax stands
	 *                           in for the line — '' when the stored
	 *                           after-discount total always does.
	 * @return float
	 */
	protected static function line_amount( array $item, string $tested_tax ): float {
		$line_total    = (float) $item['line_total'];
		$line_subtotal = (float) $item['line_subtotal'];

		if ( '' === $tested_tax ) {
			return $line_total;
		}

		return (float) $item[ $tested_tax ] > 0
			? $line_subtotal + (float) $item['line_subtotal_tax']
			: $line_total;
	}

	/**
	 * How the store's tax settings make Tyche's cart table read the stored
	 * line fields.
	 *
	 * `tested_tax` is line_amount()'s deciding field; `tax_row` is whether
	 * the table adds the lines' tax on top as a "Tax:" row of its own.
	 *
	 * @return array{tested_tax: string, tax_row: bool}
	 */
	protected static function tax_mode(): array {
		$prices_include_tax = (string) get_option( 'woocommerce_prices_include_tax', '' );
		$taxed              = 'yes' === get_option( 'woocommerce_calc_taxes', '' );

		if ( 'no' === $prices_include_tax && $taxed ) {
			// Tax-exclusive prices: a line shows its after-discount total, tax is a row of its own.
			return array(
				'tested_tax' => '',
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tyche's own filter, applied under its name
				'tax_row'    => (bool) apply_filters( 'wcal_show_taxes', true ),
			);
		}

		// Tax-inclusive prices are keyed on the line's tax; with taxes off,
		// on the tax stored against the pre-discount subtotal.
		return array(
			'tested_tax' => ( 'yes' === $prices_include_tax && $taxed ) ? 'line_tax' : 'line_subtotal_tax',
			'tax_row'    => false,
		);
	}

	/**
	 * Resolves an item's product data (variation first, parent fallback —
	 * the parent is what Tyche's own table renders).
	 *
	 * @param array|null $item Cart item.
	 * @return array {title, image, sku} — empty when unresolvable.
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
			'image' => '' !== $image ? $image : null,
			'sku'   => '' !== $sku ? $sku : null,
		);
	}
}
