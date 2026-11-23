<?php
/**
 * Abandoned Cart Lite for WooCommerce (Tyche) integration.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

use OGDynamic\CartBanners;
use OGDynamic\FeatureFlags;
use OGDynamic\Integrations\Wcap\Sources;

/**
 * Class WcapIntegration
 *
 * Renders a personalized banner inside Abandoned Cart Lite's recovery
 * emails. Tyche's free emails merge a `{{products.cart}}` block (the cart
 * contents table) through a filter that carries the abandoned-cart row, so
 * a banner assigned to the channel renders above or below that block,
 * personalized from the row being emailed.
 *
 * One banner claims the channel for the whole plugin: Tyche's merge-tag
 * filters carry no email-template id, so per-email assignment is not
 * possible — every reminder email that includes the cart contents block
 * renders the assigned banner.
 *
 * Tyche's "preview" and "send test" emails build their own demo cart block
 * and pass it through the same block filter, but with no cart row (and
 * through a differently named link filter our capture is not on): with no
 * row to personalize from, the banner stays out of previews — they never
 * show recipient data.
 */
class WcapIntegration implements Integration {

	/**
	 * Banner positions relative to the cart contents block.
	 */
	public const PLACEMENTS = array(
		'above' => 'Above the cart contents',
		'below' => 'Below the cart contents',
	);

	/**
	 * Default placement when a saved assignment carries an unknown one.
	 */
	public const DEFAULT_PLACEMENT = 'above';

	/**
	 * Unique integration slug.
	 *
	 * @return string
	 */
	public static function slug(): string {
		return 'wcap';
	}

	/**
	 * Human-readable integration name.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Abandoned Cart Lite', 'ogdynamic' );
	}

	/**
	 * Whether Abandoned Cart Lite is installed and active.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'WCAL_PLUGIN_VERSION' );
	}

	/**
	 * FeatureFlags bit gating this integration's boot.
	 *
	 * @return int
	 */
	public static function feature_bit(): int {
		return FeatureFlags::WCAP;
	}

	/**
	 * Whether a banner claims this integration — its usage truth.
	 *
	 * @param array $banners All banners (already loaded by the refresh).
	 * @return bool
	 */
	public static function is_used( array $banners ): bool {
		foreach ( $banners as $banner ) {
			if ( ! empty( $banner['wcap'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hooks into Tyche's email render.
	 *
	 * The recovery link is captured on the `cart.link` merge-tag filter —
	 * the cron fires it for every email, right before the products block
	 * is built, and it carries the finished track URL (which embeds
	 * Tyche's sent-history id, so it cannot be rebuilt) plus the cart row.
	 * The banner renders on the `products.cart` filter, which receives the
	 * cart table HTML and that same row; the banner is prepended or
	 * appended per the assignment.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wcal_abandoned_cart_email_content_cart.link', array( Sources::class, 'capture_link' ), 10, 3 );
		add_filter( 'wcal_abandoned_cart_email_content_products.cart', array( self::class, 'render_banner' ), 10, 3 );
	}

	/**
	 * Status payload for the admin app.
	 *
	 * @return array{slug: string, label: string, available: bool, active: bool, note: string, placements: array[]}
	 */
	public static function status(): array {
		$available = self::is_available();
		$active    = false;

		foreach ( CartBanners::get_all() as $banner ) {
			if ( ! empty( $banner['wcap'] ) ) {
				$active = true;
				break;
			}
		}

		$note = $available
			? __( 'Assign a banner from any banner below — it renders personalized in every recovery email that shows the cart contents.', 'ogdynamic' )
			: __( 'Install and activate Abandoned Cart Lite for WooCommerce to insert banners into its abandoned cart recovery emails.', 'ogdynamic' );

		return array(
			'slug'       => self::slug(),
			'label'      => self::label(),
			'available'  => $available,
			'active'     => $active,
			'note'       => $note,
			'placements' => self::placement_options(),
		);
	}

	/**
	 * Lists the banner placements for the assignment dialog.
	 *
	 * @return array[] Entries: {value, label}.
	 */
	public static function placement_options(): array {
		$options = array();

		foreach ( self::PLACEMENTS as $value => $label ) {
			$options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return $options;
	}

	/**
	 * Source keys this integration resolves from its cart rows.
	 *
	 * @return string[]
	 */
	public static function resolvable_sources(): array {
		return array(
			'customer_first_name',
			'customer_last_name',
			'customer_full_name',
			'cart_total',
			'cart_items_count',
			'product_title',
			'product_price',
			'product_image',
			'product_sku',
		);
	}

	/**
	 * Builds the send context from the captured cart row.
	 *
	 * @return SendContext
	 */
	public static function send_context(): SendContext {
		return Sources::context();
	}

	/**
	 * Renders the assigned banner around the cart contents block.
	 *
	 * Called on Tyche's products.cart merge filter; receives the rendered
	 * cart table HTML and the abandoned-cart row being emailed. Returns
	 * the table with the banner prepended or appended per the assignment.
	 * The capture is cleared on every pass — banner or not — so a following
	 * render in the same process can never inherit the previous
	 * recipient's data.
	 *
	 * Without a cart row there is nothing to personalize from: that is
	 * Tyche's preview/test path, which builds a demo block and passes no
	 * row — the block goes back untouched.
	 *
	 * @param string $content Rendered cart contents HTML.
	 * @param mixed  $unused  Tyche's literal false placeholder argument (true in previews).
	 * @param object $cart    Row from Tyche's abandoned cart history table (null in previews).
	 * @return string Content with the banner, or unchanged.
	 */
	public static function render_banner( $content, $unused, $cart ) {
		$content = is_string( $content ) ? $content : '';
		$banner  = self::assigned_banner();

		if ( null !== $banner && ! empty( $banner['id'] ) && is_object( $cart ) ) {
			Sources::set_cart( $cart );

			// Tyche swaps its whole cart table for a "product no longer
			// exists" notice when nothing in the cart can be rendered —
			// the banner steps aside with it.
			if ( Sources::has_items() ) {
				$position = (string) ( $banner['wcap']['position'] ?? '' );

				if ( ! isset( self::PLACEMENTS[ $position ] ) ) {
					$position = self::DEFAULT_PLACEMENT;
				}

				// One context build serves both the params and the recovery
				// link; spacing comes from the banner's own email settings.
				$context = IntegrationManager::context();
				$params  = CartBanners::resolve_params( $banner, $context );
				$link    = (string) ( $banner['link'] ?? CartBanners::LINK_RECOVERY );

				$args = array(
					'link'          => ( CartBanners::LINK_RECOVERY === $link || CartBanners::LINK_NONE === $link ) ? $link : esc_url_raw( $link ),
					'alt'           => (string) $banner['name'],
					'recovery_url'  => $context->recovery_url(),
					'margin_top'    => (int) ( $banner['margin_top'] ?? 0 ),
					'margin_bottom' => (int) ( $banner['margin_bottom'] ?? 0 ),
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image_markup() escapes its URL/alt/link attributes.
				$markup = CartBanners::image_markup( $banner, $params, $args );

				Sources::clear();

				return 'below' === $position ? $content . $markup : $markup . $content;
			}
		}

		Sources::clear();

		return $content;
	}

	/**
	 * Finds the banner assigned to this integration.
	 *
	 * @return array|null Banner payload, or null when unassigned.
	 */
	protected static function assigned_banner(): ?array {
		foreach ( CartBanners::get_all() as $banner ) {
			if ( ! empty( $banner['wcap'] ) ) {
				return $banner;
			}
		}

		return null;
	}
}
