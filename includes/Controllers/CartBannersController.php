<?php
/**
 * Cart banners REST API endpoints.
 *
 * @package OGD
 */

namespace OGDynamic\Controllers;

use OGDynamic\CartBanners;
use OGDynamic\Integrations\CartBannerSources;
use OGDynamic\Integrations\IntegrationManager;
use OGDynamic\Integrations\WcapIntegration;
use OGDynamic\RestController;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class CartBannersController
 *
 * REST API controller for managing personalized cart banners. FunnelKit
 * reads the same option through FunnelKitIntegration, so every change is
 * live for the next email send.
 */
class CartBannersController {

	/**
	 * Initializes REST API routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/cart-banners',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => self::args_schema(),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/cart-banners/(?P<id>[a-f0-9]{12})',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => self::args_schema(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Checks if user can manage cart banners.
	 *
	 * @return bool True if user has permission, false otherwise.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Gets all banners with the source catalog and integration status.
	 *
	 * @return \WP_REST_Response REST response.
	 */
	public static function get(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'data'         => array_values( CartBanners::get_all() ),
				'sources'      => IntegrationManager::sources(),
				'integrations' => IntegrationManager::status(),
			)
		);
	}

	/**
	 * Creates a banner.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error REST response or error.
	 */
	public static function create( WP_REST_Request $request ) {
		$banner = self::banner_from_request( $request );

		if ( is_wp_error( $banner ) ) {
			return $banner;
		}

		return rest_ensure_response(
			array(
				'data' => CartBanners::save( $banner ),
			)
		);
	}

	/**
	 * Updates a banner.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error REST response or error.
	 */
	public static function update( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );

		$existing = CartBanners::get( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'ogd_cart_banner_not_found',
				__( 'Cart banner not found.', 'ogdynamic' ),
				array( 'status' => 404 )
			);
		}

		$banner = self::banner_from_request( $request, $existing );

		if ( is_wp_error( $banner ) ) {
			return $banner;
		}

		$banner['id'] = $id;

		return rest_ensure_response(
			array(
				'data' => CartBanners::save( $banner ),
			)
		);
	}

	/**
	 * Deletes a banner.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error REST response or error.
	 */
	public static function delete( WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$exists = CartBanners::delete( $id );

		if ( ! $exists ) {
			return new \WP_Error(
				'ogd_cart_banner_not_found',
				__( 'Cart banner not found.', 'ogdynamic' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'data' => array(),
			)
		);
	}

	/**
	 * Assembles a sanitized banner payload from the request.
	 *
	 * @param WP_REST_Request $request  The REST request object.
	 * @param array|null      $existing Existing banner on update (assignments
	 *                                  are preserved when the request omits them).
	 * @return array|\WP_Error Banner payload or error.
	 */
	protected static function banner_from_request( WP_REST_Request $request, ?array $existing = null ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new \WP_Error(
				'ogd_invalid_banner_name',
				__( 'A banner name is required.', 'ogdynamic' ),
				array( 'status' => 400 )
			);
		}

		$map = self::sanitize_map( $request->get_param( 'map' ) );

		if ( is_wp_error( $map ) ) {
			return $map;
		}

		$link = self::sanitize_link( $request->get_param( 'link' ) );

		if ( is_wp_error( $link ) ) {
			return $link;
		}

			$display_width = absint( $request->get_param( 'display_width' ) );
			$display_width = 0 === $display_width ? CartBanners::DEFAULT_DISPLAY_WIDTH : $display_width;

			// Per-banner email spacing; a PUT without the keys (e.g. the
			// CartBounty dialog's) keeps the saved values.
			$margin_top = $request->has_param( 'margin_top' )
				? max( 0, min( 200, absint( $request->get_param( 'margin_top' ) ) ) )
				: (int) ( $existing['margin_top'] ?? 0 );

			$margin_bottom = $request->has_param( 'margin_bottom' )
				? max( 0, min( 200, absint( $request->get_param( 'margin_bottom' ) ) ) )
				: (int) ( $existing['margin_bottom'] ?? 0 );

			$border_radius = $request->has_param( 'border_radius' )
				? max( 0, min( 40, absint( $request->get_param( 'border_radius' ) ) ) )
				: (int) ( $existing['border_radius'] ?? 0 );

			$cartbounty = $request->has_param( 'cartbounty' )
				? self::sanitize_assignment( $request->get_param( 'cartbounty' ) )
				: ( $existing['cartbounty'] ?? null );

			$wcap = $request->has_param( 'wcap' )
				? self::sanitize_wcap_assignment( $request->get_param( 'wcap' ) )
				: ( $existing['wcap'] ?? null );

		// The banner keeps a snapshot of the canvas dims so merge tags can
		// size the email image without a SaaS call at send time.
		return array(
			'id'            => '',
			'name'          => mb_substr( $name, 0, 120 ),
			'canvas_id'     => sanitize_text_field( (string) $request->get_param( 'canvas_id' ) ),
			'canvas_name'   => mb_substr( sanitize_text_field( (string) $request->get_param( 'canvas_name' ) ), 0, 200 ),
			'width'         => absint( $request->get_param( 'width' ) ),
			'height'        => absint( $request->get_param( 'height' ) ),
			'link'          => $link,
			'display_width' => max( 100, min( 1200, $display_width ) ),
			'margin_top'    => $margin_top,
			'margin_bottom' => $margin_bottom,
			'border_radius' => $border_radius,
			'map'           => $map,
			'cartbounty'    => $cartbounty,
			'wcap'          => $wcap,
		);
	}

	/**
	 * Sanitizes the banner's CartBounty assignment.
	 *
	 * Margin keys are ignored (and dropped) — spacing is a global email
	 * setting, not part of the assignment.
	 *
	 * @param mixed $value Raw assignment ({email, placement}) or null.
	 * @return array|null Assignment, or null when cleared/invalid.
	 */
	protected static function sanitize_assignment( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$email     = array_key_exists( 'email', $value ) ? absint( $value['email'] ) : -1;
		$placement = isset( $value['placement'] ) ? sanitize_key( (string) $value['placement'] ) : '';

		if ( $email < 0 || $email > 10 || '' === $placement ) {
			return null;
		}

		return array(
			'email'     => $email,
			'placement' => $placement,
		);
	}

	/**
	 * Sanitizes the banner's Abandoned Cart Lite assignment.
	 *
	 * Position only — the channel is one-per-plugin (see
	 * CartBanners::claim_wcap_channel()), so there is no email selector.
	 * Only a position the integration actually renders is accepted.
	 *
	 * @param mixed $value Raw assignment ({position}) or null.
	 * @return array|null Assignment, or null when cleared/invalid.
	 */
	protected static function sanitize_wcap_assignment( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$position = isset( $value['position'] ) ? sanitize_key( (string) $value['position'] ) : '';

		if ( ! isset( WcapIntegration::PLACEMENTS[ $position ] ) ) {
			return null;
		}

		return array( 'position' => $position );
	}

	/**
	 * REST args schema shared by create and update.
	 *
	 * @return array
	 */
	protected static function args_schema(): array {
		return array(
			'name'          => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'canvas_id'     => array(
				'required'          => true,
				'validate_callback' => array( TemplatesController::class, 'validate_template_id' ),
				'sanitize_callback' => array( TemplatesController::class, 'sanitize_template_id' ),
			),
			'canvas_name'   => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'width'         => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 10000,
			),
			'height'        => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 10000,
			),
			'link'          => array(
				'required' => false,
				'type'     => 'string',
			),
			'display_width' => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 100,
				'maximum'  => 1200,
			),
			'margin_top'    => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 0,
				'maximum'  => 200,
			),
			'margin_bottom' => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 0,
				'maximum'  => 200,
			),
			'border_radius' => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 0,
				'maximum'  => 40,
			),
			'map'           => array(
				'required'          => true,
				'validate_callback' => array( self::class, 'validate_map' ),
				'sanitize_callback' => array( self::class, 'sanitize_map' ),
			),
			'cartbounty'    => array(
				'required'          => false,
				'validate_callback' => array( self::class, 'validate_assignment' ),
			),
			'wcap'          => array(
				'required'          => false,
				'validate_callback' => array( self::class, 'validate_wcap_assignment' ),
			),
		);
	}

	/**
	 * Validates an Abandoned Cart Lite assignment shape.
	 *
	 * Null (or an empty object) clears the assignment, and the position
	 * must be one the integration renders. The arg declares no `type` on
	 * purpose: core's injected rest_parse_request_arg would run schema
	 * validation inside the SANITIZE step and reject the clearing null
	 * before this callback ever runs — the custom validate_callback owns
	 * the whole shape check instead.
	 *
	 * @param mixed $value The assignment to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_wcap_assignment( $value ): bool {
		if ( null === $value || array() === $value ) {
			return true;
		}

		return is_array( $value )
			&& isset( $value['position'] )
			&& is_string( $value['position'] )
			&& isset( WcapIntegration::PLACEMENTS[ $value['position'] ] );
	}

	/**
	 * Validates a CartBounty assignment shape.
	 *
	 * Null (or an empty object) clears the assignment. The arg declares
	 * no `type` on purpose: core's injected rest_parse_request_arg would
	 * run schema validation inside the SANITIZE step and reject the
	 * clearing null before this callback ever runs — the custom
	 * validate_callback owns the whole shape check instead.
	 *
	 * @param mixed $value The assignment to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_assignment( $value ): bool {
		if ( null === $value || array() === $value ) {
			return true;
		}

		if ( ! is_array( $value ) || ! isset( $value['email'], $value['placement'] ) ) {
			return false;
		}

		return is_numeric( $value['email'] )
			&& (int) $value['email'] >= 0
			&& (int) $value['email'] <= 10
			&& is_string( $value['placement'] )
			&& 1 === preg_match( '/^[a-z_]{2,30}$/', $value['placement'] );
	}

	/**
	 * Validates a banner map structure.
	 *
	 * @param mixed $value The map to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_map( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$source_keys = self::source_keys();

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['attr_key'], $item['key'] ) ) {
				return false;
			}

			if ( ! is_string( $item['attr_key'] ) || ! is_string( $item['key'] ) ) {
				return false;
			}

			// Canvas render URLs take flat string params, so attr keys must
			// match the same pattern the block generate endpoint enforces.
			if ( ! preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $item['attr_key'] ) ) {
				return false;
			}

			$key = $item['key'];

			// '' = canvas default, 'custom' = static value, otherwise a
			// known source from the catalog.
			if ( '' !== $key && 'custom' !== $key && ! in_array( $key, $source_keys, true ) ) {
				return false;
			}

			if ( isset( $item['value'] ) && ! is_string( $item['value'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitizes a banner map.
	 *
	 * Entries mapping to no source are dropped — the canvas default renders.
	 *
	 * @param mixed $value The map to sanitize.
	 * @return array|\WP_Error Sanitized map or error.
	 */
	public static function sanitize_map( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error(
				'ogd_invalid_banner_map',
				__( 'Invalid banner field mapping.', 'ogdynamic' ),
				array( 'status' => 400 )
			);
		}

		$clean = array();

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$attr_key = isset( $item['attr_key'] ) ? sanitize_text_field( (string) $item['attr_key'] ) : '';
			$key      = isset( $item['key'] ) ? sanitize_text_field( (string) $item['key'] ) : '';
			$value2   = isset( $item['value'] ) ? sanitize_text_field( (string) $item['value'] ) : '';

			if ( '' === $attr_key || '' === $key ) {
				continue;
			}

			$entry = array(
				'attr_key' => $attr_key,
				'key'      => $key,
			);

			if ( 'custom' === $key ) {
				$entry['value'] = mb_substr( $value2, 0, 200 );
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Sanitizes the banner link mode.
	 *
	 * @param mixed $value Raw link value.
	 * @return string|\WP_Error 'recovery', 'none', a clean URL, or error.
	 */
	protected static function sanitize_link( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return CartBanners::LINK_RECOVERY;
		}

		if ( CartBanners::LINK_RECOVERY === $value || CartBanners::LINK_NONE === $value ) {
			return $value;
		}

		$url = esc_url_raw( $value );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error(
				'ogd_invalid_banner_link',
				__( 'The banner link must be "recovery", "none", or a valid URL.', 'ogdynamic' ),
				array( 'status' => 400 )
			);
		}

		return $url;
	}

	/**
	 * Gets the known source keys.
	 *
	 * The whole shared catalog plus any third-party sources offered via
	 * the `ogdynamic_email_banner_sources` filter — not just the active
	 * integrations' keys, so banners saved while an integration was active
	 * stay editable while it is temporarily deactivated.
	 *
	 * @return string[] Source keys.
	 */
	protected static function source_keys(): array {
		$keys = CartBannerSources::keys();

		foreach ( IntegrationManager::sources() as $source ) {
			$keys[] = $source['key'];
		}

		return array_values( array_unique( $keys ) );
	}
}
