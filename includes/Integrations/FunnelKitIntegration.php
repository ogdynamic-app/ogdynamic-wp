<?php
/**
 * FunnelKit Automations integration.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

use OGDynamic\CartBanners;
use OGDynamic\Integrations\FunnelKit\BannerTag;
use OGDynamic\Integrations\FunnelKit\Sources;

/**
 * Class FunnelKitIntegration
 *
 * Registers one FunnelKit merge tag per saved cart banner. When a banner
 * is selected in the FunnelKit email builder ("Merge tags" picker,
 * ogdynamic group), the tag expands at send time into the email-safe
 * banner image personalized for that recipient's cart.
 */
class FunnelKitIntegration implements Integration {

	/**
	 * Group label shown in the FunnelKit merge tags picker.
	 */
	public const GROUP_LABEL = 'ogdynamic';

	/**
	 * Loader shortName for registered tags.
	 */
	public const SHORT_NAME = 'ogdynamic';

	/**
	 * Unique integration slug.
	 *
	 * @return string
	 */
	public static function slug(): string {
		return 'funnelkit';
	}

	/**
	 * Human-readable integration name.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'FunnelKit Automations', 'ogdynamic' );
	}

	/**
	 * Whether FunnelKit Automations is installed and active.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'BWFAN_Merge_Tag_Loader' );
	}

	/**
	 * Status payload for the admin app.
	 *
	 * @return array{slug: string, label: string, available: bool, active: bool, note: string}
	 */
	public static function status(): array {
		$available = self::is_available();

		return array(
			'slug'      => self::slug(),
			'label'     => self::label(),
			'available' => $available,
			'active'    => $available,
			'note'      => $available
				? __( 'Your banners are available as merge tags inside FunnelKit Automations emails.', 'ogdynamic' )
				: __( 'Install and activate FunnelKit Automations to insert banners into its abandoned cart emails.', 'ogdynamic' ),
		);
	}

	/**
	 * Hooks the FunnelKit merge tags loader.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'bwfan_merge_tags_loaded', array( self::class, 'register_merge_tags' ), 10 );
	}

	/**
	 * Source keys FunnelKit resolves at send time from its merge tag data.
	 *
	 * Site sources are core (CartBannerSources::CORE_KEYS) and omitted here.
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
	 * Builds the send context from FunnelKit's send-time data.
	 *
	 * @return SendContext
	 */
	public static function send_context(): SendContext {
		return Sources::context();
	}

	/**
	 * Registers one merge tag per cart banner.
	 *
	 * Runs on `bwfan_merge_tags_loaded`, which FunnelKit fires whenever its
	 * merge tag registry is needed (builder UI, merge tag parsing at send
	 * time, broadcasts, and automations). Only the local banner option is
	 * read — no SaaS calls — so registration is cheap and cron-safe.
	 *
	 * Each banner gets its own class_alias of BannerTag (the loader derives
	 * registry entries per class), and the banner payload is handed to the
	 * instance through BannerTag::$next_banner because `static::class` on
	 * an aliased class collapses to the original name on PHP 8.5+.
	 *
	 * @return void
	 */
	public static function register_merge_tags(): void {
		if ( ! class_exists( 'BWFAN_Merge_Tag_Loader' ) || ! class_exists( 'BWFAN_Merge_Tag' ) ) {
			return;
		}

		foreach ( CartBanners::get_all() as $banner ) {
			if ( empty( $banner['id'] ) || empty( $banner['canvas_id'] ) ) {
				continue;
			}

			$class = self::tag_class( (string) $banner['id'] );

			if ( ! class_exists( $class ) ) {
				class_alias( BannerTag::class, $class );
			}

			BannerTag::$next_banner = $banner;

			\BWFAN_Merge_Tag_Loader::register( self::SHORT_NAME, $class, null, self::GROUP_LABEL );

			BannerTag::$next_banner = null;
		}
	}

	/**
	 * Gets the generated tag class name for a banner id.
	 *
	 * Banner ids are lowercase hex, so the generated class names are always
	 * valid PHP identifiers.
	 *
	 * @param string $id Banner id.
	 * @return string The class name.
	 */
	protected static function tag_class( string $id ): string {
		return 'OgdFkTag_' . strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $id ) ?? '' );
	}
}
