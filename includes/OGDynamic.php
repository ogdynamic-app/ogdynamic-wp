<?php
/**
 * Main plugin coordinator.
 *
 * @package OGD
 */

namespace OGDynamic;

use OGDynamic\Traits\Singleton;

final class OGDynamic {
	use Singleton;

	public function boot(): void {
		RestController::init();
		Block::register();
		CartBanners::register_shortcode();
		Integrations\IntegrationManager::register();

		if ( is_admin() ) {
			Admin::register();
		} else {
			MetaTags::register();
		}
	}
}
