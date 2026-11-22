<?php
/**
 * Cart banner model.
 *
 * @package OGD
 */

namespace OGDynamic;

use OGDynamic\Integrations\CartBannerSources;
use OGDynamic\Integrations\IntegrationManager;
use OGDynamic\Integrations\SendContext;

/**
 * Class CartBanners
 *
 * Personalized banner images for abandoned-cart (and other marketing)
 * emails. A banner pairs a canvas with a field mapping; integrations
 * (FunnelKit first) expose each banner as a merge tag that resolves the
 * mapping against per-recipient data at send time and outputs an
 * email-safe <img> pointing at the CDN render URL.
 */
class CartBanners {

	/**
	 * Option key prefix for per-banner options: `ogdy_cart_banner_{id}`.
	 *
	 * One option per banner (the Template::option_name() pattern) instead
	 * of one blob: saves touch only their own row — no read-modify-write
	 * of every banner — and merge-tag registration still lists them with
	 * a single prefix query.
	 */
	public const OPTION_PREFIX = 'cart_banner_';

	/**
	 * Per-request banner cache (null = not loaded yet).
	 *
	 * @var array|null
	 */
	private static ?array $cache = null;

	/**
	 * Default rendered width of the banner image in emails.
	 */
	public const DEFAULT_DISPLAY_WIDTH = 600;

	/**
	 * Banner link modes.
	 */
	public const LINK_RECOVERY = 'recovery';
	public const LINK_NONE     = 'none';

	/**
	 * Merge-tag param keys reserved for tag attributes (never field values).
	 *
	 * @var string[]
	 */
	public const RESERVED_ATTRS = array( 'id', 'width', 'alt', 'link', 'fallback', 'prefix', 'suffix', 'class' );

	/**
	 * Registers the universal banner shortcode.
	 *
	 * @return void
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'ogdynamic_cart_banner', array( self::class, 'render_shortcode' ) );
	}

	/**
	 * Renders the [ogdynamic_cart_banner] shortcode.
	 *
	 * Works anywhere WordPress shortcodes are processed. Without email
	 * context only custom mapping values and shortcode attributes resolve;
	 * dynamic sources need the integrations' send-time data.
	 *
	 * @param array|string $atts Shortcode attributes. Every attribute other
	 *                           than the reserved ones overrides the matching
	 *                           canvas field, e.g. [ogdynamic_cart_banner id="…" name="Sarah"].
	 * @return string Banner image HTML, or an empty string for an unknown banner.
	 */
	public static function render_shortcode( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();

		$id = isset( $atts['id'] ) ? strtolower( sanitize_text_field( (string) $atts['id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{12}$/', $id ) ) {
			return '';
		}

		$banner = self::get( $id );
		if ( null === $banner ) {
			return '';
		}

		$context = IntegrationManager::context();
		$params  = self::resolve_params( $banner, $context );
		$params  = self::apply_attr_overrides( $params, $atts );

		$link = isset( $atts['link'] ) ? (string) $atts['link'] : '';
		if ( '' === $link ) {
			// Fall back to the banner's saved link setting, like the merge tags do.
			$link = (string) ( $banner['link'] ?? self::LINK_RECOVERY );
		}
		$args = array(
			'alt'          => isset( $atts['alt'] ) && '' !== $atts['alt'] ? sanitize_text_field( (string) $atts['alt'] ) : $banner['name'],
			'link'         => ( self::LINK_RECOVERY === $link || self::LINK_NONE === $link ) ? $link : esc_url_raw( $link ),
			'width'        => isset( $atts['width'] ) ? absint( $atts['width'] ) : 0,
			'recovery_url' => $context->recovery_url(),
		);

		return self::image_markup( $banner, $params, $args );
	}

	/**
	 * Gets every banner (flag-guarded).
	 *
	 * Flag-guarded (FeatureFlags::CART_BANNERS): when the usage mask says
	 * no banners exist, the scan is skipped entirely — banner-less sites
	 * pay nothing, including FunnelKit's per-send read.
	 *
	 * @return array Banners keyed by id.
	 */
	public static function get_all(): array {
		if ( ! FeatureFlags::enabled( FeatureFlags::CART_BANNERS ) ) {
			return array();
		}

		if ( null === self::$cache ) {
			self::$cache = self::scan_all();
		}

		return self::$cache;
	}

	/**
	 * Loads every banner row straight from the options table.
	 *
	 * One prefix query (the Template::get_activated_post_types() pattern).
	 * Unguarded AND unmemoized by design: FeatureFlags::refresh_all()
	 * reads through this to recompute the very bit the guard in get_all()
	 * checks and must see current rows — a guarded read could never heal
	 * a stale "unused" bit, and a memoized one could miss rows that
	 * appeared or vanished earlier in the request. get_all() carries the
	 * per-request memo (merge-tag registration reads it on every send).
	 *
	 * @return array Banners keyed by id.
	 */
	public static function scan_all(): array {
		global $wpdb;

		$prefix = Settings::PREFIX . self::OPTION_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);

		$banners = array();

		foreach ( (array) $rows as $row ) {
			$banner = maybe_unserialize( $row['option_value'] );

			if ( is_array( $banner ) && ! empty( $banner['id'] ) ) {
				$banners[ (string) $banner['id'] ] = $banner;
			}
		}

		return $banners;
	}

	/**
	 * Gets one banner by id.
	 *
	 * @param string $id Banner id.
	 * @return array|null Banner payload or null when unknown.
	 */
	public static function get( string $id ): ?array {
		$id = strtolower( $id );

		if ( ! preg_match( '/^[a-f0-9]{12}$/', $id ) || ! FeatureFlags::enabled( FeatureFlags::CART_BANNERS ) ) {
			return null;
		}

		$banner = Settings::get( self::OPTION_PREFIX . $id );

		return is_array( $banner ) ? $banner : null;
	}

	/**
	 * Creates a banner (id assigned) or updates an existing one.
	 *
	 * A banner's CartBounty assignment (`cartbounty` => {email, placement})
	 * claims one automation email — saving an assignment clears it from
	 * any other banner, so every email renders at most one banner.
	 *
	 * @param array $banner Sanitized banner payload.
	 * @return array Saved banner.
	 */
	public static function save( array $banner ): array {
		$exists = ! empty( $banner['id'] ) && null !== self::get( (string) $banner['id'] );

		if ( ! $exists ) {
			$banner['id']         = self::new_id();
			$banner['created_at'] = current_time( 'mysql' );
		}

		$banner['updated_at'] = current_time( 'mysql' );

		self::claim_cartbounty_email( $banner );
		self::claim_wcap_channel( $banner );

		Settings::update( self::OPTION_PREFIX . $banner['id'], $banner );

		self::$cache = null;

		// A save may both create the first banner and change a CartBounty
		// assignment, so every affected bit is recomputed from truth.
		FeatureFlags::refresh_all();

		return $banner;
	}

	/**
	 * Clears the same CartBounty email assignment from other banners.
	 *
	 * @param array $banner Banner payload being saved.
	 * @return void
	 */
	protected static function claim_cartbounty_email( array $banner ): void {
		$assignment = $banner['cartbounty'] ?? null;

		if ( ! is_array( $assignment ) || ! isset( $assignment['email'] ) ) {
			return;
		}

		$email = (int) $assignment['email'];

		foreach ( self::get_all() as $other_id => $other ) {
			if ( $other_id === (string) $banner['id'] ) {
				continue;
			}

			$other_assignment = $other['cartbounty'] ?? null;

			if ( is_array( $other_assignment ) && (int) ( $other_assignment['email'] ?? -1 ) === $email ) {
				unset( $other['cartbounty'] );
				Settings::update( self::OPTION_PREFIX . $other_id, $other );
			}
		}
	}

	/**
	 * Clears the Abandoned Cart Lite assignment from other banners.
	 *
	 * One banner renders per channel (Tyche's merge-tag filters carry no
	 * email-template id), so a new claim replaces the previous holder.
	 *
	 * @param array $banner Banner payload being saved.
	 * @return void
	 */
	protected static function claim_wcap_channel( array $banner ): void {
		if ( empty( $banner['wcap'] ) ) {
			return;
		}

		foreach ( self::get_all() as $other_id => $other ) {
			if ( $other_id === (string) $banner['id'] ) {
				continue;
			}

			if ( ! empty( $other['wcap'] ) ) {
				unset( $other['wcap'] );
				Settings::update( self::OPTION_PREFIX . $other_id, $other );
			}
		}
	}

	/**
	 * Deletes a banner.
	 *
	 * @param string $id Banner id.
	 * @return bool True when a banner was removed.
	 */
	public static function delete( string $id ): bool {
		if ( null === self::get( $id ) ) {
			return false;
		}

		Settings::delete( self::OPTION_PREFIX . strtolower( $id ) );

		self::$cache = null;

		FeatureFlags::refresh_all();

		return true;
	}

	/**
	 * Generates a new, unused banner id (12 lowercase hex chars).
	 *
	 * @return string Banner id.
	 */
	public static function new_id(): string {
		do {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeded_random_bytes -- random_bytes is CSPRNG, not seedable mt_rand.
			$id = bin2hex( random_bytes( 6 ) );
		} while ( null !== self::get( $id ) );

		return $id;
	}

	/**
	 * Resolves a banner's mapping into canvas params.
	 *
	 * Dynamic sources resolve through one batched IntegrationManager
	 * dispatch (a single send-context build for the whole mapping, empty
	 * outside an email send); custom values pass through as-is. Callers
	 * mid-render pass their already-built context so params and the
	 * recovery link together cost one context build, not two.
	 *
	 * @param array            $banner  Banner payload.
	 * @param SendContext|null $context Already-built send context, or null to build one.
	 * @return array Param values keyed by canvas field.
	 */
	public static function resolve_params( array $banner, ?SendContext $context = null ): array {
		$map  = (array) ( $banner['map'] ?? array() );
		$keys = array();

		foreach ( $map as $entry ) {
			$attr_key = (string) ( $entry['attr_key'] ?? '' );
			$key      = (string) ( $entry['key'] ?? '' );

			if ( '' !== $attr_key && '' !== $key && 'custom' !== $key ) {
				$keys[] = $key;
			}
		}

		$resolved = IntegrationManager::resolve_sources( array_unique( $keys ), $context );

		$params = array();

		foreach ( $map as $entry ) {
			$attr_key = (string) ( $entry['attr_key'] ?? '' );
			$key      = (string) ( $entry['key'] ?? '' );

			if ( '' === $attr_key ) {
				continue;
			}

			if ( 'custom' === $key ) {
				$params[ $attr_key ] = (string) ( $entry['value'] ?? '' );
				continue;
			}

			if ( '' !== $key ) {
				$params[ $attr_key ] = $resolved[ $key ] ?? '';
			}
		}

		return $params;
	}

	/**
	 * Applies merge-tag/shortcode attribute overrides onto resolved params.
	 *
	 * Any attribute named like a canvas field (and not reserved) wins over
	 * the mapped value, e.g. {{ogd_cart_banner_abc coupon="SAVE10"}}.
	 *
	 * @param array $params Resolved params.
	 * @param array $atts   Raw attributes.
	 * @return array Overridden params.
	 */
	public static function apply_attr_overrides( array $params, array $atts ): array {
		foreach ( $atts as $att_key => $att_value ) {
			if ( is_int( $att_key ) || ! is_string( $att_value ) ) {
				continue;
			}

			if ( in_array( $att_key, self::RESERVED_ATTRS, true ) ) {
				continue;
			}

			if ( ! preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $att_key ) ) {
				continue;
			}

			if ( '' !== $att_value ) {
				$params[ $att_key ] = $att_value;
			}
		}

		return $params;
	}

	/**
	 * Builds the CDN render URL for a banner.
	 *
	 * The `ogdynamic_email_banner_params` filter lets other plugins adjust
	 * the canvas params before the URL is built (e.g. append UTM tags);
	 * everything it returns passes the same sanitization as core values.
	 *
	 * @param array $banner Banner payload.
	 * @param array $params Resolved param values keyed by canvas field.
	 * @return string Render URL.
	 */
	public static function banner_url( array $banner, array $params ): string {
		$filtered = apply_filters( 'ogdynamic_email_banner_params', $params, $banner );

		// A broken filter keeps the core params rather than clearing them.
		if ( is_array( $filtered ) ) {
			$params = $filtered;
		}

		$params = self::filter_params( $params );

		$url = untrailingslashit( OGDYNAMIC_CDN ) . '/c/' . rawurlencode( (string) $banner['canvas_id'] );

		if ( array() !== $params ) {
			$url .= '?' . http_build_query( self::decode_entities( $params ) );
		}

		return $url;
	}

	/**
	 * Builds the email-safe banner image markup.
	 *
	 * The markup is intentionally minimal: email clients strip most CSS,
	 * so inline styles only cover what Gmail/Outlook allow on <img>.
	 *
	 * @param array $banner Banner payload.
	 * @param array $params Resolved param values keyed by canvas field.
	 * @param array $args   Optional 'alt', 'link' (URL, 'recovery' marker, or 'none'), 'width', 'recovery_url' (recovery URL from the caller's already-built send context, so a mid-render caller never pays a second context build), 'margin_top'/'margin_bottom' (px spacing above/below the image, clamped 0-200; both zero keeps the compact centered margin), and 'border_radius' (px, 0-40; omit to use the banner's saved corner style, pass 0 for sharp) overrides.
	 * @return string The image (or linked image) HTML.
	 */
	public static function image_markup( array $banner, array $params, array $args = array() ): string {
		$url = self::banner_url( $banner, $params );

		$width = (int) ( $args['width'] ?? 0 );
		if ( $width <= 0 ) {
			$width = (int) ( $banner['display_width'] ?? self::DEFAULT_DISPLAY_WIDTH );
		}
		if ( $width <= 0 ) {
			$width = self::DEFAULT_DISPLAY_WIDTH;
		}

		$alt = (string) ( $args['alt'] ?? '' );
		if ( '' === $alt ) {
			$alt = (string) ( $banner['name'] ?? __( 'Banner', 'ogdynamic' ) );
		}

		// Email spacing: per-render margins, zero keeping the compact
		// centered default.
		$margin_top    = max( 0, min( 200, (int) ( $args['margin_top'] ?? 0 ) ) );
		$margin_bottom = max( 0, min( 200, (int) ( $args['margin_bottom'] ?? 0 ) ) );

		// Corner rounding is a style of the banner itself (like width), so
		// the saved setting applies in every render; an explicit args
		// override wins, with 0 meaning sharp. 0 radius keeps the markup
		// free of a border-radius declaration — Outlook ignores it anyway
		// (renders sharp), everything else rounds.
		$border_radius = array_key_exists( 'border_radius', $args )
			? max( 0, min( 40, (int) $args['border_radius'] ) )
			: max( 0, min( 40, (int) ( $banner['border_radius'] ?? 0 ) ) );

		// Declarative style: keys are the CSS property names as emitted, and
		// null drops the property — new styles are one entry, never name or
		// format surgery.
		$styles = array(
			'width'           => '100%',
			'max-width'       => $width . 'px',
			'height'          => 'auto',
			'display'         => 'block',
			'margin-top'      => $margin_top . 'px',
			'margin-right'    => 'auto',
			'margin-bottom'   => $margin_bottom . 'px',
			'margin-left'     => 'auto',
			'border-radius'   => $border_radius > 0 ? $border_radius . 'px' : null,
			'border'          => '0',
			'outline'         => 'none',
			'text-decoration' => 'none',
		);

		$img = sprintf(
			'<img src="%s" alt="%s" width="%d" style="%s" />',
			esc_url( $url ),
			esc_attr( $alt ),
			$width,
			esc_attr( self::style_attribute( $styles ) )
		);

		$link = trim( (string) ( $args['link'] ?? '' ) );

		if ( self::LINK_RECOVERY === $link ) {
			// A caller mid-render passes the recovery URL from its
			// already-built send context; only callers without one pay
			// a context build here.
			$link = isset( $args['recovery_url'] ) && is_string( $args['recovery_url'] )
				? trim( $args['recovery_url'] )
				: self::recovery_url();
		} elseif ( self::LINK_NONE === $link || ( '' !== $link && ! wp_http_validate_url( $link ) ) ) {
			// 'none' means unlinked; anything else that is not a valid URL
			// is dropped rather than emitted as a broken href.
			$link = '';
		}

		if ( '' !== $link ) {
			$img = sprintf(
				'<a href="%s" target="_blank" style="text-decoration:none;">%s</a>',
				esc_url( $link ),
				$img
			);
		}

		/**
		 * Lets other plugins adjust the final banner markup (e.g. wrap it
		 * in tracking or fallback markup for dark-mode email clients).
		 *
		 * @param string $img    Final image (or linked image) HTML.
		 * @param array  $banner Banner payload.
		 * @param array  $params Resolved param values keyed by canvas field.
		 * @param array  $args   Render args (alt, link, width).
		 */
		$filtered = apply_filters( 'ogdynamic_email_banner_markup', $img, $banner, $params, $args );

		return is_string( $filtered ) ? $filtered : $img;
	}

	/**
	 * Resolves the cart recovery URL from the integration being sent.
	 *
	 * @return string Recovery URL or an empty string.
	 */
	public static function recovery_url(): string {
		return IntegrationManager::recovery_url();
	}

	/**
	 * Drops empty and non-scalar values and sorts keys so identical inputs
	 * give identical URLs.
	 *
	 * Non-strings are cast, not passed through: extension filters can
	 * return anything, but canvas render URLs are flat strings only.
	 *
	 * @param array $params Resolved params.
	 * @return array Filtered params.
	 */
	protected static function filter_params( array $params ): array {
		$filtered = array();

		foreach ( $params as $key => $value ) {
			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			if ( is_numeric( $value ) && ! is_string( $value ) ) {
				$value = (string) $value;
			}

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			if ( ! preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $key ) ) {
				continue;
			}

			if ( strlen( $value ) > 2000 ) {
				$value = substr( $value, 0, 2000 );
			}

			$filtered[ $key ] = $value;
		}

		ksort( $filtered );

		return $filtered;
	}

	/**
	 * Serializes a style map into a style attribute value.
	 *
	 * Keys are the CSS property names as emitted; null/empty values drop
	 * the property.
	 *
	 * @param array<string, string|null> $styles CSS property map.
	 * @return string Style value, e.g. "width: 100%; border-radius: 14px".
	 */
	protected static function style_attribute( array $styles ): string {
		$parts = array();

		foreach ( $styles as $property => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$parts[] = $property . ': ' . $value;
		}

		return implode( '; ', $parts );
	}

	/**
	 * Decode HTML entities in a resolved field value.
	 *
	 * Mirrors ImageGenerator::decode_entities(): one pass per value so
	 * double-encoded entities stay intact; values without entities are
	 * returned unchanged.
	 *
	 * @param string|array $value Resolved field value.
	 * @return string|array Decoded value.
	 */
	protected static function decode_entities( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'decode_entities' ), $value );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$charset = get_bloginfo( 'charset' );
		if ( '' === $charset ) {
			$charset = 'UTF-8';
		}

		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, $charset );
	}
}
