<?php
/**
 * Feature usage flags.
 *
 * @package OGD
 */

namespace OGDynamic;

/**
 * Class FeatureFlags
 *
 * Mirrors "which features does this site actually use" into one autoloaded
 * bitmask so customer-facing requests can skip hook registrations and option
 * queries for features the customer never configured (see docs/feature-gating.md).
 *
 * The mask is INVERTED — it stores the bits confirmed UNUSED. Gating work
 * requires positive proof, so every unknown state fails open: a missing mask
 * (legacy install, hand-deleted, garbage value) gates nothing, and a bit the
 * stored mask predates (feature added by a plugin update) gates nothing
 * until the next admin-side refresh settles it.
 *
 * Truth lives in the database; the mask is only a mirror. Every refresh
 * recomputes its bit from the actual option rows, and refreshes run strictly
 * admin-side (admin page loads, model mutations, activation) — frontend,
 * cron, and email-send paths only ever read the mask, which rides in
 * alloptions and costs no query.
 */
class FeatureFlags {

	/**
	 * Any OG template mapping exists (ogdy_mapping_* rows).
	 */
	public const OG_MAPPINGS = 1;

	/**
	 * Any cart recovery banner exists (ogdy_cart_banner_* rows).
	 */
	public const CART_BANNERS = 2;

	/**
	 * A banner is assigned to a CartBounty email.
	 */
	public const CARTBOUNTY = 4;

	/**
	 * A default OG mapping exists (ogdy_mapping_default) — the fallback
	 * used when the visited content type has no specific mapping.
	 */
	public const OG_DEFAULT = 8;

	/**
	 * Settings key → ogdy_unused_features. The plugin's only autoloaded option.
	 */
	private const OPTION_KEY = 'unused_features';

	/**
	 * Core bits and the option prefix their truth is derived from.
	 *
	 * Values point at the constants that own the prefixes (not retyped
	 * strings), so the scans can never drift from what the features write.
	 * Integration bits are NOT here — their truth comes from the banner
	 * records via each integration's is_used().
	 */
	private const PREFIXES = array(
		self::OG_MAPPINGS  => Template::POST_TYPE_KEY_PREFIX,
		self::CART_BANNERS => CartBanners::OPTION_PREFIX,
	);

	/**
	 * Whether a feature is in use.
	 *
	 * Reads the autoloaded mask — no query. Stored ints come back from the
	 * option layer as strings (and garbage as anything), so the cast is
	 * load-bearing: any non-numeric value becomes 0 = nothing unused.
	 *
	 * @param int $feature Feature bit.
	 * @return bool True when the feature may run.
	 */
	public static function enabled( int $feature ): bool {
		$unused = Settings::get( self::OPTION_KEY, null );

		return null === $unused || ( ( (int) $unused ) & $feature ) === 0;
	}

	/**
	 * Marks one feature used (clears its bit) or unused (sets it).
	 *
	 * Read-modify-write preserving the other bits. Written as a string
	 * because get_option() returns scalars as strings — an int write would
	 * fail update_option()'s strict comparison and UPDATE on every call.
	 *
	 * @param int  $feature Feature bit.
	 * @param bool $used    Whether the feature is in use.
	 * @return void
	 */
	public static function set( int $feature, bool $used ): void {
		$unused = (int) Settings::get( self::OPTION_KEY, 0 );
		$unused = $used ? ( $unused & ~$feature ) : ( $unused | $feature );

		Settings::update( self::OPTION_KEY, (string) $unused, true );
	}

	/**
	 * Recomputes one core bit from its option rows (indexed prefix LIKE).
	 *
	 * @param int $feature Feature bit (must have a PREFIXES entry).
	 * @return void
	 */
	public static function refresh( int $feature ): void {
		$prefix = self::PREFIXES[ $feature ] ?? '';

		if ( '' === $prefix ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$used = null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
				$wpdb->esc_like( Settings::PREFIX . $prefix ) . '%'
			)
		);

		self::set( $feature, $used );
	}

	/**
	 * Recomputes every bit — core prefixes plus every integration's usage.
	 *
	 * The banners bit comes from the banner scan itself (one query both
	 * settles the bit and feeds the integration derivations), so its
	 * prefix probe is skipped. The scan goes through scan_all() — the
	 * unguarded read — since a guarded get_all() would trust the very bit
	 * being rechecked and could never heal drift. Admin-side only.
	 *
	 * @return void
	 */
	public static function refresh_all(): void {
		foreach ( array_keys( self::PREFIXES ) as $feature ) {
			if ( self::CART_BANNERS === $feature ) {
				continue; // Set from the banner scan below, not a second query.
			}

			self::refresh( $feature );
		}

		self::refresh_og_default();

		$banners = CartBanners::scan_all();

		self::set( self::CART_BANNERS, array() !== $banners );

		foreach ( Integrations\IntegrationManager::integrations() as $integration ) {
			if ( ! method_exists( $integration, 'feature_bit' ) || ! method_exists( $integration, 'is_used' ) ) {
				continue;
			}

			self::set( $integration::feature_bit(), $integration::is_used( $banners ) );
		}
	}

	/**
	 * Recomputes the default-mapping bit from its option row.
	 *
	 * Reads through Template::get_mapping(), so the OG_MAPPINGS bit must
	 * already be settled for the current refresh (refresh_all refreshes
	 * the prefix bits first; the Template mutation callers refresh
	 * OG_MAPPINGS immediately before this) — a stale unused OG_MAPPINGS
	 * bit would make the guarded read report no default even when the row
	 * exists, which the next full refresh would then heal anyway.
	 *
	 * @return void
	 */
	public static function refresh_og_default(): void {
		self::set( self::OG_DEFAULT, array() !== Template::get_mapping( 'default' ) );
	}
}
