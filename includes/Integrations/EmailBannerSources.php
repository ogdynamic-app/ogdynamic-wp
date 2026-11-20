<?php
/**
 * Shared email banner source catalog.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

/**
 * Class EmailBannerSources
 *
 * The universal "abandoned cart data" vocabulary every email banner maps
 * canvas fields to. Keys are integration-agnostic: each integration
 * declares which keys it can resolve at send time (see Integration::
 * resolvable_sources()) and resolves them from its own data model, so a
 * saved banner keeps working unchanged across integrations.
 *
 * Site sources are core: they resolve from plain WordPress data with no
 * integration involved.
 */
class EmailBannerSources {

	/**
	 * Source keys resolved by this class (no integration needed).
	 */
	public const CORE_KEYS = array( 'site_name', 'site_tagline' );

	/**
	 * Full source catalog for the mapping UI and previews.
	 *
	 * @return array[] Each entry: key, label, group, type ('text'|'image'), sample.
	 */
	public static function catalog(): array {
		return array(
			array(
				'key'    => 'customer_first_name',
				'label'  => __( 'Customer first name', 'ogdynamic' ),
				'group'  => __( 'Customer', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => 'Sarah',
			),
			array(
				'key'    => 'customer_last_name',
				'label'  => __( 'Customer last name', 'ogdynamic' ),
				'group'  => __( 'Customer', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => 'Johnson',
			),
			array(
				'key'    => 'customer_full_name',
				'label'  => __( 'Customer full name', 'ogdynamic' ),
				'group'  => __( 'Customer', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => 'Sarah Johnson',
			),
			array(
				'key'    => 'cart_total',
				'label'  => __( 'Cart total', 'ogdynamic' ),
				'group'  => __( 'Cart', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => '$129.00',
			),
			array(
				'key'    => 'cart_items_count',
				'label'  => __( 'Cart items count', 'ogdynamic' ),
				'group'  => __( 'Cart', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => '2',
			),
			array(
				'key'    => 'product_title',
				'label'  => __( 'First product title', 'ogdynamic' ),
				'group'  => __( 'First product', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => 'Aurora Table Lamp',
			),
			array(
				'key'    => 'product_price',
				'label'  => __( 'First product price', 'ogdynamic' ),
				'group'  => __( 'First product', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => '$129.00',
			),
			array(
				'key'    => 'product_image',
				'label'  => __( 'First product image', 'ogdynamic' ),
				'group'  => __( 'First product', 'ogdynamic' ),
				'type'   => 'image',
				'sample' => '',
			),
			array(
				'key'    => 'product_sku',
				'label'  => __( 'First product SKU', 'ogdynamic' ),
				'group'  => __( 'First product', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => 'AURORA-LAMP',
			),
			array(
				'key'    => 'site_name',
				'label'  => __( 'Site name', 'ogdynamic' ),
				'group'  => __( 'Site', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => get_bloginfo( 'name' ),
			),
			array(
				'key'    => 'site_tagline',
				'label'  => __( 'Site tagline', 'ogdynamic' ),
				'group'  => __( 'Site', 'ogdynamic' ),
				'type'   => 'text',
				'sample' => get_bloginfo( 'description' ),
			),
		);
	}

	/**
	 * Gets every known source key (used for REST map validation).
	 *
	 * Validation accepts the whole catalog, not just the active
	 * integrations' keys, so banners saved while an integration was active
	 * stay editable while it is temporarily deactivated.
	 *
	 * @return string[] Source keys.
	 */
	public static function keys(): array {
		$keys = array();

		foreach ( self::catalog() as $source ) {
			$keys[] = $source['key'];
		}

		return $keys;
	}

	/**
	 * Resolves a core (integration-independent) source key.
	 *
	 * @param string $key Source key.
	 * @return string Value, or an empty string for non-core keys.
	 */
	public static function core_resolve( string $key ): string {
		switch ( $key ) {
			case 'site_name':
				return get_bloginfo( 'name' );
			case 'site_tagline':
				return get_bloginfo( 'description' );
		}

		return '';
	}
}
