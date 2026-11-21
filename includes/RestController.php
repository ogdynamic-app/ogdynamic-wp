<?php
/**
 * REST API bootstrap.
 *
 * @package OGD
 */

namespace OGDynamic;

use OGDynamic\Controllers\BlockController;
use OGDynamic\Controllers\ConnectionController;
use OGDynamic\Controllers\CartBannersController;
use OGDynamic\Controllers\TemplatesController;

class RestController {

	/**
	 * REST namespace shared by every controller route.
	 */
	public const NAMESPACE = 'ogdynamic/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		BlockController::init();
		ConnectionController::init();
		TemplatesController::init();
		CartBannersController::init();
	}
}
