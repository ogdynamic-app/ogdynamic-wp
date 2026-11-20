<?php
/**
 * Email banner model.
 *
 * @package OGD
 */

namespace OGDynamic;

use OGDynamic\Integrations\EmailBannerSources;
use OGDynamic\Integrations\IntegrationManager;

/**
 * Class EmailBanners
 *
 * Personalized banner images for abandoned-cart (and other marketing)
 * emails. A banner pairs a canvas with a field mapping; integrations
 * (FunnelKit first) expose each banner as a merge tag that resolves the
 * mapping against per-recipient data at send time and outputs an
 * email-safe <img> pointing at the CDN render URL.
 */
class EmailBanners {

	/**
	 * Option key holding every banner keyed by id.
	 */
	public const OPTION = 'email_banners';

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
		add_shortcode( 'ogdynamic_banner', array( self::class, 'render_shortcode' ) );
	}

	/**
	 * Renders the [ogdynamic_banner] shortcode.
	 *
	 * Works anywhere WordPress shortcodes are processed. Without email
	 * context only custom mapping values and shortcode attributes resolve;
	 * dynamic sources need the integrations' send-time data.
	 *
	 * @param array|string $atts Shortcode attributes. Every attribute other
	 *                           than the reserved ones overrides the matching
	 *                           canvas field, e.g. [ogdynamic_banner id="…" name="Sarah"].
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

		$params = self::resolve_params( $banner );
		$params = self::apply_attr_overrides( $params, $atts );

		$link = isset( $atts['link'] ) ? (string) $atts['link'] : '';
		$args = array(
			'alt'   => isset( $atts['alt'] ) && '' !== $atts['alt'] ? sanitize_text_field( (string) $atts['alt'] ) : $banner['name'],
			'link'  => self::LINK_RECOVERY === $link ? self::LINK_RECOVERY : esc_url_raw( $link ),
			'width' => isset( $atts['width'] ) ? absint( $atts['width'] ) : 0,
		);

		return self::image_markup( $banner, $params, $args );
	}

	/**
	 * Gets every banner.
	 *
	 * @return array Banners keyed by id.
	 */
	public static function get_all(): array {
		$banners = Settings::get( self::OPTION, array() );

		return is_array( $banners ) ? $banners : array();
	}

	/**
	 * Gets one banner by id.
	 *
	 * @param string $id Banner id.
	 * @return array|null Banner payload or null when unknown.
	 */
	public static function get( string $id ): ?array {
		$banners = self::get_all();

		return isset( $banners[ $id ] ) && is_array( $banners[ $id ] ) ? $banners[ $id ] : null;
	}

	/**
	 * Creates a banner (id assigned) or updates an existing one.
	 *
	 * @param array $banner Sanitized banner payload.
	 * @return array Saved banner.
	 */
	public static function save( array $banner ): array {
		$banners = self::get_all();

		if ( empty( $banner['id'] ) || ! isset( $banners[ $banner['id'] ] ) ) {
			$banner['id']         = self::new_id();
			$banner['created_at'] = current_time( 'mysql' );
		}

		$banner['updated_at'] = current_time( 'mysql' );

		$banners[ $banner['id'] ] = $banner;

		Settings::update( self::OPTION, $banners );

		return $banner;
	}

	/**
	 * Deletes a banner.
	 *
	 * @param string $id Banner id.
	 * @return bool True when a banner was removed.
	 */
	public static function delete( string $id ): bool {
		$banners = self::get_all();

		if ( ! isset( $banners[ $id ] ) ) {
			return false;
		}

		unset( $banners[ $id ] );

		Settings::update( self::OPTION, $banners );

		return true;
	}

	/**
	 * Generates a new banner id (12 lowercase hex chars).
	 *
	 * @return string Banner id.
	 */
	public static function new_id(): string {
		return bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Resolves a banner's mapping into canvas params.
	 *
	 * Dynamic sources resolve through one batched IntegrationManager
	 * dispatch (a single send-context build for the whole mapping, empty
	 * outside an email send); custom values pass through as-is.
	 *
	 * @param array $banner Banner payload.
	 * @return array Param values keyed by canvas field.
	 */
	public static function resolve_params( array $banner ): array {
		$map  = (array) ( $banner['map'] ?? array() );
		$keys = array();

		foreach ( $map as $entry ) {
			$attr_key = (string) ( $entry['attr_key'] ?? '' );
			$key      = (string) ( $entry['key'] ?? '' );

			if ( '' !== $attr_key && '' !== $key && 'custom' !== $key ) {
				$keys[] = $key;
			}
		}

		$resolved = IntegrationManager::resolve_sources( array_unique( $keys ) );

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
	 * Builds preview params from the source samples plus custom values.
	 *
	 * Used by the email-builder preview and the admin live preview so the
	 * banner shows lifelike content without a real recipient.
	 *
	 * @param array $banner Banner payload.
	 * @return array Param values keyed by canvas field.
	 */
	public static function sample_params( array $banner ): array {
		$samples = array();
		foreach ( IntegrationManager::sources() as $source ) {
			$samples[ $source['key'] ] = $source['sample'];
		}

		$params = array();

		foreach ( (array) ( $banner['map'] ?? array() ) as $entry ) {
			$attr_key = (string) ( $entry['attr_key'] ?? '' );
			$key      = (string) ( $entry['key'] ?? '' );

			if ( '' === $attr_key ) {
				continue;
			}

			if ( 'custom' === $key ) {
				$params[ $attr_key ] = (string) ( $entry['value'] ?? '' );
			} elseif ( '' !== $key && isset( $samples[ $key ] ) ) {
				$params[ $attr_key ] = $samples[ $key ];
			}
		}

		return $params;
	}

	/**
	 * Applies merge-tag/shortcode attribute overrides onto resolved params.
	 *
	 * Any attribute named like a canvas field (and not reserved) wins over
	 * the mapped value, e.g. {{ogd_banner_abc coupon="SAVE10"}}.
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
	 * @param array $args   Optional 'alt', 'link' (URL or recovery marker), 'width' overrides.
	 * @return string The image (or linked image) HTML.
	 */
	public static function image_markup( array $banner, array $params, array $args = array() ): string {
		$url = self::banner_url( $banner, $params );

		if ( '' === $url ) {
			return '';
		}

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

		$img = sprintf(
			'<img src="%s" alt="%s" width="%d" style="width:100%%;max-width:%dpx;height:auto;display:block;margin:0 auto;border:0;outline:none;text-decoration:none;" />',
			esc_url( $url ),
			esc_attr( $alt ),
			$width,
			$width
		);

		$link = trim( (string) ( $args['link'] ?? '' ) );

		if ( self::LINK_RECOVERY === $link ) {
			$link = self::recovery_url();
		} elseif ( '' !== $link && ! wp_http_validate_url( $link ) ) {
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
