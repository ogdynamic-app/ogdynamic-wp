<?php
/**
 * CartBounty integration.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

use OGDynamic\EmailBanners;
use OGDynamic\Integrations\CartBounty\Sources;

/**
 * Class CartBountyIntegration
 *
 * Injects a personalized banner into CartBounty's WordPress recovery
 * emails. Unlike FunnelKit (merge tags picked per email), CartBounty's
 * free email body is a fixed template — so this integration renders the
 * site's configured default banner after the email intro, personalized
 * per recipient from the cart being emailed.
 */
class CartBountyIntegration implements Integration {

	/**
	 * Hooks into CartBounty's email render.
	 *
	 * Free recovery emails fire `cartbounty_automation_*` actions with no
	 * args; the cart is captured separately via the unsubscribe URL filter
	 * (see Sources::capture()). Pro's builder fires
	 * `cartbounty_pro_automation_{template}_{position}_{step}` hooks —
	 * those are registered too so Pro users get the banner without extra
	 * setup, best-effort until they can be verified against a Pro install.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'cartbounty_automation_unsubscribe_url', array( Sources::class, 'capture' ) );
		add_action( 'cartbounty_automation_after_intro', array( self::class, 'render_banner' ) );

		foreach ( array( 'light', 'rows', 'columns' ) as $template ) {
			foreach ( array( 'after_intro', 'after_title' ) as $position ) {
				foreach ( array( 1, 2, 3 ) as $step ) {
					add_action( "cartbounty_pro_automation_{$template}_{$position}_{$step}", array( self::class, 'render_banner' ) );
				}
			}
		}
	}

	/**
	 * Whether CartBounty is installed and active.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'CARTBOUNTY_VERSION_NUMBER' );
	}

	/**
	 * Unique integration slug.
	 *
	 * @return string
	 */
	public static function slug(): string {
		return 'cartbounty';
	}

	/**
	 * Human-readable integration name.
	 *
	 * @return string
	 */
	public static function label(): string {
		return 'CartBounty';
	}

	/**
	 * Status payload for the admin app.
	 *
	 * @return array{slug: string, label: string, available: bool, active: bool, note: string, banner_id: string}
	 */
	public static function status(): array {
		$available = self::is_available();
		$banner_id = self::default_banner_id();

		$note = $available
			? __( 'Your default banner is inserted into CartBounty recovery emails after the intro text.', 'ogdynamic' )
			: __( 'Install and activate CartBounty to inject banners into its abandoned cart recovery emails.', 'ogdynamic' );

		if ( $available && '' === $banner_id ) {
			$note = __( 'Pick a default banner below and it will be inserted into CartBounty recovery emails after the intro text.', 'ogdynamic' );
		}

		return array(
			'slug'      => self::slug(),
			'label'     => self::label(),
			'available' => $available,
			'active'    => $available && '' !== $banner_id,
			'note'      => $note,
			'banner_id' => $banner_id,
		);
	}

	/**
	 * Source keys CartBounty resolves from its saved cart rows.
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
	 * Renders the configured banner into the email.
	 *
	 * Echoes nothing when no banner is configured or the banner was
	 * deleted. The captured cart is cleared afterwards so a following
	 * test/preview render (which fires no capture filter) can never leak
	 * the previous recipient's data.
	 *
	 * @return void
	 */
	public static function render_banner(): void {
		$banner_id = self::default_banner_id();

		if ( '' === $banner_id ) {
			Sources::clear();
			return;
		}

		$banner = EmailBanners::get( $banner_id );

		if ( null === $banner ) {
			Sources::clear();
			return;
		}

		$params = EmailBanners::resolve_params( $banner );
		$args   = array(
			'link' => (string) ( $banner['link'] ?? EmailBanners::LINK_RECOVERY ),
			'alt'  => (string) $banner['name'],
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image_markup() escapes its URL/alt/link attributes.
		echo EmailBanners::image_markup( $banner, $params, $args );

		Sources::clear();
	}

	/**
	 * Gets the configured default banner id.
	 *
	 * @return string 12-hex banner id, or an empty string when unset.
	 */
	public static function default_banner_id(): string {
		$banner_id = (string) IntegrationManager::get_integration_banner( self::slug() );

		return preg_match( '/^[a-f0-9]{12}$/', $banner_id ) ? $banner_id : '';
	}
}
