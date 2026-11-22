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

		// Unused features register nothing at all — not even their hooks
		// (see FeatureFlags). REST stays unconditional: its rest_api_init
		// hook never fires on page views, and REST requests don't run
		// under is_admin().
		if ( FeatureFlags::enabled( FeatureFlags::CART_BANNERS ) ) {
			CartBanners::register_shortcode();
		}

		Integrations\IntegrationManager::register();

		if ( is_admin() ) {
			Admin::register();
		} elseif ( FeatureFlags::enabled( FeatureFlags::OG_MAPPINGS ) ) {
			MetaTags::register();
		}
	}
}
