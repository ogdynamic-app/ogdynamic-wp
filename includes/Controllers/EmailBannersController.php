<?php
/**
 * Email banners REST API endpoints.
 *
 * @package OGD
 */

namespace OGDynamic\Controllers;

use OGDynamic\EmailBanners;
use OGDynamic\Integrations\EmailBannerSources;
use OGDynamic\Integrations\IntegrationManager;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class EmailBannersController
 *
 * REST API controller for managing personalized email banners. FunnelKit
 * reads the same option through FunnelKitIntegration, so every change is
 * live for the next email send.
 */
class EmailBannersController {

	/**
	 * Initializes REST API routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		register_rest_route(
			'ogdynamic/v1',
			'/email-banners',
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
			'ogdynamic/v1',
			'/email-banners/(?P<id>[a-f0-9]{12})',
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
	 * Checks if user can manage email banners.
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
				'data'         => array_values( EmailBanners::get_all() ),
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
				'data' => EmailBanners::save( $banner ),
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

		if ( null === EmailBanners::get( $id ) ) {
			return new \WP_Error(
				'ogd_banner_not_found',
				__( 'Email banner not found.', 'ogdynamic' ),
				array( 'status' => 404 )
			);
		}

		$banner = self::banner_from_request( $request );

		if ( is_wp_error( $banner ) ) {
			return $banner;
		}

		$banner['id'] = $id;

		return rest_ensure_response(
			array(
				'data' => EmailBanners::save( $banner ),
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
		$exists = EmailBanners::delete( $id );

		if ( ! $exists ) {
			return new \WP_Error(
				'ogd_banner_not_found',
				__( 'Email banner not found.', 'ogdynamic' ),
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
	 * @param WP_REST_Request $request The REST request object.
	 * @return array|\WP_Error Banner payload or error.
	 */
	protected static function banner_from_request( WP_REST_Request $request ) {
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
		$display_width = 0 === $display_width ? EmailBanners::DEFAULT_DISPLAY_WIDTH : $display_width;

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
			'map'           => $map,
		);
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
			'map'           => array(
				'required'          => true,
				'validate_callback' => array( self::class, 'validate_map' ),
				'sanitize_callback' => array( self::class, 'sanitize_map' ),
			),
		);
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

		if ( '' === $value || EmailBanners::LINK_RECOVERY === $value || EmailBanners::LINK_NONE === $value ) {
			return EmailBanners::LINK_RECOVERY;
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
	 * The whole shared catalog — not just the active integrations' keys —
	 * so banners saved while an integration was active stay editable while
	 * it is temporarily deactivated.
	 *
	 * @return string[] Source keys.
	 */
	protected static function source_keys(): array {
		return EmailBannerSources::keys();
	}
}
