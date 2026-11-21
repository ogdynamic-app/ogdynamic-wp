<?php
/**
 * FunnelKit merge tag for a single cart banner.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations\FunnelKit;

use OGDynamic\CartBanners;
use OGDynamic\Integrations\FunnelKitIntegration;
use OGDynamic\Integrations\IntegrationManager;

/**
 * Class BannerTag
 *
 * One banner gets one tag instance. FunnelKit instantiates tags through
 * `$class::get_instance()` and derives the registry slug from
 * `get_class()`, so FunnelKitIntegration creates per-banner class aliases
 * of this base class and passes the matching banner through
 * `self::$next_banner` at registration time.
 *
 * The banner travels as an instance property (not a static map keyed by
 * `static::class`) because PHP 8.5+ resolves `static::class` on an aliased
 * class to the original class name, collapsing every alias onto one key.
 *
 * Only loadable while FunnelKit is active (it extends a FunnelKit class);
 * FunnelKitIntegration::register_merge_tags() is the sole referencer.
 */
class BannerTag extends \BWFAN_Merge_Tag {

	/**
	 * Banner payload for this tag instance.
	 *
	 * @var array
	 */
	protected $banner = array();

	/**
	 * Banner being registered right now, set by FunnelKitIntegration just
	 * before it hands this tag's alias to the FunnelKit loader.
	 *
	 * @var array|null
	 */
	public static ?array $next_banner = null;

	/**
	 * Instantiates the tag for the pending banner.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		return new static();
	}

	/**
	 * Builds the tag around its banner.
	 */
	public function __construct() {
		$banner       = is_array( self::$next_banner ) ? self::$next_banner : array();
		$this->banner = $banner;

		$id = (string) ( $banner['id'] ?? '' );

		$this->tag_name        = 'ogd_cart_banner_' . $id;
		$this->tag_description = ! empty( $banner['name'] )
			? sprintf( /* translators: %s: Banner name. */
				__( 'Banner: %s', 'ogdynamic' ),
				(string) $banner['name']
			)
			: __( 'Personalized cart banner', 'ogdynamic' );

		add_shortcode( 'bwfan_' . $this->tag_name, array( $this, 'parse_shortcode' ) );

		$this->support_fallback = false;
		$this->is_crm_broadcast = true;
	}

	/**
	 * Unique registry slug for this banner.
	 *
	 * FunnelKit's base implementation sanitizes the class name, which is
	 * shared by every class_alias() target — overriding with the tag name
	 * keeps each banner a distinct registry entry.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->tag_name;
	}

	/**
	 * Renders the personalized banner image for emails.
	 *
	 * Supported attributes:
	 * - Any canvas field name overrides the mapped value for this send,
	 *   e.g. {{ogd_cart_banner_abc coupon="SAVE10"}}.
	 * - link: 'recovery' (cart recovery URL), 'none', or a URL — overrides
	 *   the banner's saved link setting.
	 * - width/alt: display overrides.
	 *
	 * @param array|string $atts Merge tag attributes.
	 * @return string The banner image HTML.
	 */
	public function parse_shortcode( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();

		if ( array() === $this->banner ) {
			return '';
		}

		if ( true === \BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			// Builder preview renders the plain canvas with its designed
			// defaults — placeholder recipient data here would mislead.
			$markup = CartBanners::image_markup(
				$this->banner,
				array(),
				array(
					'link'          => CartBanners::LINK_NONE,
					'alt'           => $this->tag_description,
					'margin_top'    => (int) ( $this->banner['margin_top'] ?? 0 ),
					'margin_bottom' => (int) ( $this->banner['margin_bottom'] ?? 0 ),
				)
			);

			return (string) $this->parse_shortcode_output( $markup, $atts );
		}

		// One context build serves both the params and the recovery link
		// (batch sends render several recipients per process, so it is
		// never memoized further).
		$context = IntegrationManager::context();
		$params  = CartBanners::resolve_params( $this->banner, $context );
		$params  = CartBanners::apply_attr_overrides( $params, $atts );

		$link = isset( $atts['link'] ) ? (string) $atts['link'] : '';
		if ( '' === $link ) {
			$link = (string) ( $this->banner['link'] ?? CartBanners::LINK_RECOVERY );
		}

		if ( CartBanners::LINK_NONE === $link ) {
			$link = CartBanners::LINK_NONE;
		} elseif ( CartBanners::LINK_RECOVERY !== $link ) {
			$link = esc_url_raw( $link );
		}

		$markup = CartBanners::image_markup(
			$this->banner,
			$params,
			array(
				'link'          => $link,
				'alt'           => isset( $atts['alt'] ) && '' !== $atts['alt'] ? sanitize_text_field( (string) $atts['alt'] ) : $this->tag_description,
				'width'         => isset( $atts['width'] ) ? absint( $atts['width'] ) : 0,
				'recovery_url'  => $context->recovery_url(),
				'margin_top'    => (int) ( $this->banner['margin_top'] ?? 0 ),
				'margin_bottom' => (int) ( $this->banner['margin_bottom'] ?? 0 ),
			)
		);

		return (string) $this->parse_shortcode_output( $markup, $atts );
	}
}
