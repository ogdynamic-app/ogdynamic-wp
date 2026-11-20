<?php
/**
 * Email-plugin integration registry.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

/**
 * Class IntegrationManager
 *
 * Knows every supported email-plugin integration, boots the ones whose
 * target plugin is active, reports status to the admin app, and fronts
 * the shared source catalog: the mapping UI sources are the union of the
 * core (integration-independent) sources and every active integration's
 * declared resolvable keys, and send-time resolution dispatches to the
 * first active integration that can fill the key.
 */
class IntegrationManager {

	/**
	 * Registered integrations.
	 *
	 * @var array<class-string>
	 */
	public const INTEGRATIONS = array(
		FunnelKitIntegration::class,
	);

	/**
	 * Boots available integrations.
	 *
	 * Runs unconditionally from OGDynamic::boot() — email plugins send via
	 * cron and ActionScheduler, so integrations must not be admin-gated.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::INTEGRATIONS as $integration ) {
			if ( $integration::is_available() ) {
				$integration::init();
			}
		}
	}

	/**
	 * Status payload for the admin app.
	 *
	 * @return array[] One status entry per integration.
	 */
	public static function status(): array {
		$integrations = array();

		foreach ( self::INTEGRATIONS as $integration ) {
			$integrations[] = $integration::status();
		}

		return $integrations;
	}

	/**
	 * Source catalog entries the current site can actually resolve.
	 *
	 * Core sources are always included; every other catalog entry is
	 * included when at least one active integration declares its key.
	 *
	 * @return array[] Catalog entries (key, label, group, type, sample).
	 */
	public static function sources(): array {
		$resolvable = array();

		foreach ( self::INTEGRATIONS as $integration ) {
			if ( $integration::is_available() ) {
				$resolvable = array_merge( $resolvable, $integration::resolvable_sources() );
			}
		}

		$sources = array();

		foreach ( EmailBannerSources::catalog() as $source ) {
			if ( in_array( $source['key'], EmailBannerSources::CORE_KEYS, true ) || in_array( $source['key'], $resolvable, true ) ) {
				$sources[] = $source;
			}
		}

		return $sources;
	}

	/**
	 * Resolves a source key against the active integrations' send-time data.
	 *
	 * Core sources resolve directly; everything else asks each active
	 * integration in registration order and keeps the first non-empty
	 * value — an integration mid-send has data, the others resolve empty.
	 *
	 * @param string $key Source key.
	 * @return string Value, or an empty string when nobody can resolve it.
	 */
	public static function resolve_source( string $key ): string {
		$core = EmailBannerSources::core_resolve( $key );

		if ( '' !== $core ) {
			return $core;
		}

		foreach ( self::INTEGRATIONS as $integration ) {
			if ( ! $integration::is_available() ) {
				continue;
			}

			$value = $integration::resolve_source( $key );

			if ( '' !== $value && null !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Gets the cart recovery URL from the active integration being sent.
	 *
	 * @return string URL, or an empty string when unavailable.
	 */
	public static function recovery_url(): string {
		foreach ( self::INTEGRATIONS as $integration ) {
			if ( ! $integration::is_available() ) {
				continue;
			}

			$url = $integration::recovery_url();

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}
}
