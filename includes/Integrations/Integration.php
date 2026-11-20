<?php
/**
 * Email-plugin integration contract.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

interface Integration {

	/**
	 * Unique integration slug.
	 *
	 * @return string
	 */
	public static function slug(): string;

	/**
	 * Human-readable integration name.
	 *
	 * @return string
	 */
	public static function label(): string;

	/**
	 * Whether the target plugin is installed and active.
	 *
	 * @return bool
	 */
	public static function is_available(): bool;

	/**
	 * Status payload for the admin app.
	 *
	 * @return array{slug: string, label: string, available: bool, active: bool, note: string}
	 */
	public static function status(): array;

	/**
	 * Hooks the target plugin. Only called when is_available() is true.
	 *
	 * @return void
	 */
	public static function init(): void;

	/**
	 * Source keys (EmailBannerSources::keys() subset) this integration can
	 * resolve at send time. Drives which sources the mapping UI offers.
	 *
	 * @return string[]
	 */
	public static function resolvable_sources(): array;

	/**
	 * Resolves one source key from the integration's own send-time data.
	 *
	 * @param string $key Source key.
	 * @return string Value, or an empty string when unavailable.
	 */
	public static function resolve_source( string $key ): string;

	/**
	 * Gets the cart recovery URL for the email currently being sent.
	 *
	 * @return string URL, or an empty string when unavailable.
	 */
	public static function recovery_url(): string;
}
