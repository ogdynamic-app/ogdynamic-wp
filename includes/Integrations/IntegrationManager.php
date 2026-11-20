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
 *
 * Extension points for other plugins (see AGENTS.md):
 * - `ogdynamic_integrations` registers additional Integration classes
 * - `ogdynamic_email_banner_sources` offers additional mapping sources
 * - `ogdynamic_email_banner_resolved_sources` fills source values at render
 */
class IntegrationManager {

	/**
	 * Built-in integrations.
	 *
	 * @var array<class-string>
	 */
	public const INTEGRATIONS = array(
		FunnelKitIntegration::class,
	);

	/**
	 * Gets every integration, including third-party registrations.
	 *
	 * Entries must be class strings implementing Integration; anything
	 * else from the filter is ignored rather than fataling the request.
	 * A filter that yields no valid class falls back to the built-ins —
	 * one broken extension must not disable banner rendering site-wide.
	 *
	 * @return array<class-string>
	 */
	public static function integrations(): array {
		$integrations = apply_filters( 'ogdynamic_integrations', self::INTEGRATIONS );

		if ( ! is_array( $integrations ) ) {
			return self::INTEGRATIONS;
		}

		$valid = array();

		foreach ( $integrations as $integration ) {
			if ( ! is_string( $integration ) || ! class_exists( $integration ) ) {
				continue;
			}

			if ( ! in_array( Integration::class, (array) class_implements( $integration ), true ) ) {
				continue;
			}

			$valid[] = $integration;
		}

		if ( array() === $valid ) {
			return self::INTEGRATIONS;
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Boots available integrations.
	 *
	 * Runs unconditionally from OGDynamic::boot() — email plugins send via
	 * cron and ActionScheduler, so integrations must not be admin-gated.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::integrations() as $integration ) {
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

		foreach ( self::integrations() as $integration ) {
			$integrations[] = $integration::status();
		}

		return $integrations;
	}

	/**
	 * Source catalog entries the current site can actually resolve.
	 *
	 * Core sources are always included; every other catalog entry is
	 * included when at least one active integration declares its key.
	 * Third-party plugins offer additional sources through the
	 * `ogdynamic_email_banner_sources` filter (entries need at least a
	 * valid `key` and a `label`; invalid additions are dropped).
	 *
	 * @return array[] Catalog entries (key, label, group, type).
	 */
	public static function sources(): array {
		$resolvable = array();

		foreach ( self::integrations() as $integration ) {
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

		/** Filterable so other plugins can offer their own data sources. */
		$offered = apply_filters( 'ogdynamic_email_banner_sources', $sources );

		// A broken filter (non-array) falls back to the core list rather
		// than emptying the mapping dropdown.
		if ( ! is_array( $offered ) ) {
			$offered = $sources;
		}

		return self::sanitize_source_entries( $offered );
	}

	/**
	 * Normalizes and filters source entries coming from any source list.
	 *
	 * @param array[] $entries Raw source entries.
	 * @return array[] Valid entries with sanitized key/label/group/type.
	 */
	protected static function sanitize_source_entries( array $entries ): array {
		$valid = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key   = isset( $entry['key'] ) ? sanitize_key( (string) $entry['key'] ) : '';
			$label = isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '';

			if ( '' === $key || ! preg_match( '/^[a-z0-9_]{2,64}$/', $key ) || '' === $label ) {
				continue;
			}

			$type = isset( $entry['type'] ) ? sanitize_key( (string) $entry['type'] ) : 'text';

			$valid[ $key ] = array(
				'key'   => $key,
				'label' => $label,
				'group' => isset( $entry['group'] ) ? sanitize_text_field( (string) $entry['group'] ) : '',
				'type'  => in_array( $type, array( 'text', 'image' ), true ) ? $type : 'text',
			);
		}

		return array_values( $valid );
	}

	/**
	 * Gets the send context of the active integration being sent.
	 *
	 * Asks each active integration in registration order and keeps the
	 * first context that carries data — an integration mid-send has data,
	 * the others build empty contexts. Deliberately NOT memoized across
	 * the request: batch senders render several recipients per process,
	 * and a cached context would leak one recipient's data into the next
	 * email (use resolve_sources() to batch several keys into one build).
	 *
	 * @return SendContext Context (empty outside a send).
	 */
	public static function context(): SendContext {
		foreach ( self::integrations() as $integration ) {
			if ( ! $integration::is_available() ) {
				continue;
			}

			$context = $integration::send_context();

			if ( ! $context->is_empty() ) {
				return $context;
			}
		}

		return SendContext::from_array( array() );
	}

	/**
	 * Resolves several source keys with a single context build.
	 *
	 * Core sources resolve directly; everything else is resolved and
	 * formatted by the shared SendContext — one build for the whole
	 * banner, so a mapping never pays the extraction cost per field.
	 * Third-party plugins fill their custom source values (or override
	 * resolved ones) through the `ogdynamic_email_banner_resolved_sources`
	 * filter; non-string values from the filter are dropped.
	 *
	 * @param string[] $keys Catalog source keys.
	 * @return array<string, string> Resolved values keyed by source key.
	 */
	public static function resolve_sources( array $keys ): array {
		$context  = self::context();
		$resolved = array();

		foreach ( $keys as $key ) {
			$core = EmailBannerSources::core_resolve( (string) $key );

			$resolved[ (string) $key ] = '' !== $core ? $core : $context->get( (string) $key );
		}

		$filtered = apply_filters( 'ogdynamic_email_banner_resolved_sources', $resolved, $keys );

		// A broken filter (non-array) keeps the core resolution.
		if ( is_array( $filtered ) ) {
			$resolved = $filtered;
		}

		foreach ( $resolved as $key => $value ) {
			if ( is_numeric( $value ) ) {
				$resolved[ $key ] = (string) $value;
				continue;
			}

			if ( ! is_string( $value ) ) {
				unset( $resolved[ $key ] );
			}
		}

		return $resolved;
	}

	/**
	 * Resolves a source key against the current send context.
	 *
	 * @param string $key Source key.
	 * @return string Value, or an empty string when nobody can resolve it.
	 */
	public static function resolve_source( string $key ): string {
		return self::resolve_sources( array( $key ) )[ $key ] ?? '';
	}

	/**
	 * Gets the cart recovery URL from the active integration being sent.
	 *
	 * @return string URL, or an empty string when unavailable.
	 */
	public static function recovery_url(): string {
		return self::context()->recovery_url();
	}
}
