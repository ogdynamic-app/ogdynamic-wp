<?php
/**
 * Normalized email send context.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

/**
 * Class SendContext
 *
 * The normalized per-recipient data an email is being sent with. Each
 * integration builds one from its own plugin's send-time state (that is
 * the integration's ONLY job — extraction); every catalog key is then
 * resolved and formatted here, once, so integrations cannot drift apart
 * in how values are presented.
 *
 * Built exclusively through from_array(), which sanitizes and casts every
 * field — the single validation point for integration data.
 */
final class SendContext {

	/**
	 * Context fields and their sanitizer kinds.
	 *
	 * Keys double as the constructor's accepted raw fields.
	 */
	private const FIELDS = array(
		'customer_first_name' => 'text',
		'customer_last_name'  => 'text',
		'cart_total'          => 'price',
		'cart_currency'       => 'text',
		'cart_items_count'    => 'int',
		'first_product_title' => 'text',
		'first_product_price' => 'price',
		'first_product_image' => 'url',
		'first_product_sku'   => 'text',
		'recovery_url'        => 'url',
	);

	/**
	 * Sanitized context values keyed by field (absent = null).
	 *
	 * @var array<string, string|int|float|null>
	 */
	private $fields = array();

	/**
	 * Private — build through from_array().
	 */
	private function __construct() {
	}

	/**
	 * Builds a context from raw integration data.
	 *
	 * Unknown keys are ignored; every known key is sanitized or cast per
	 * its kind, and unparseable values become null (absent).
	 *
	 * @param array $raw Raw field values, typically straight from the
	 *                   integration's cart/contact data.
	 * @return self
	 */
	public static function from_array( array $raw ): self {
		$context         = new self();
		$context->fields = array();

		foreach ( self::FIELDS as $key => $kind ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			$value = $raw[ $key ];

			if ( null === $value ) {
				continue;
			}

			$sanitized = self::sanitize( $value, $kind );

			if ( null !== $sanitized ) {
				$context->fields[ $key ] = $sanitized;
			}
		}

		return $context;
	}

	/**
	 * Whether the context carries any send-time data at all.
	 *
	 * Empty outside a send — used to pick the integration mid-send when
	 * several are active.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->fields;
	}

	/**
	 * Gets the cart recovery URL.
	 *
	 * @return string URL, or an empty string when unavailable.
	 */
	public function recovery_url(): string {
		$url = $this->fields['recovery_url'] ?? null;

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Resolves a catalog source key against this context.
	 *
	 * Presentation lives here and nowhere else: names are capitalized,
	 * prices formatted with the cart currency, and the full name joined —
	 * every integration renders identically. Entities are NOT decoded
	 * here; the URL builder does that once, as with OG images.
	 *
	 * @param string $key Catalog source key.
	 * @return string Formatted value, or an empty string when absent.
	 */
	public function get( string $key ): string {
		switch ( $key ) {
			case 'customer_first_name':
				return $this->name( 'customer_first_name' );
			case 'customer_last_name':
				return $this->name( 'customer_last_name' );
			case 'customer_full_name':
				return trim( $this->name( 'customer_first_name' ) . ' ' . $this->name( 'customer_last_name' ) );
			case 'cart_total':
				return $this->price( 'cart_total', 'cart_currency' );
			case 'cart_items_count':
				$count = $this->fields['cart_items_count'] ?? null;
				return is_int( $count ) ? (string) $count : '';
			case 'product_title':
				return $this->text( 'first_product_title' );
			case 'product_price':
				return $this->price( 'first_product_price', 'cart_currency' );
			case 'product_image':
				return $this->text( 'first_product_image' );
			case 'product_sku':
				return $this->text( 'first_product_sku' );
			case 'recovery_url':
				return $this->recovery_url();
		}

		return '';
	}

	/**
	 * Gets a text field.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function text( string $key ): string {
		$value = $this->fields[ $key ] ?? null;

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Gets a customer name field, capitalized for presentation.
	 *
	 * Checkout captures often hold lowercase input ("sarah"); ucwords()
	 * here keeps every integration's banners consistent.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function name( string $key ): string {
		$value = $this->text( $key );

		return '' === $value ? '' : ucwords( $value );
	}

	/**
	 * Gets a price field formatted with the cart currency.
	 *
	 * @param string $key           Price field key.
	 * @param string $currency_key  Currency field key.
	 * @return string Formatted price (HTML-stripped), or an empty string.
	 */
	private function price( string $key, string $currency_key ): string {
		$price = $this->fields[ $key ] ?? null;

		if ( ! is_float( $price ) && ! is_int( $price ) ) {
			return '';
		}

		$currency = $this->text( $currency_key );

		if ( function_exists( 'wc_price' ) ) {
			$args = '' !== $currency ? array( 'currency' => $currency ) : array();

			return wp_strip_all_tags( (string) wc_price( $price, $args ) );
		}

		return '' !== $currency ? sprintf( '%s %s', number_format( (float) $price, 2 ), $currency ) : (string) $price;
	}

	/**
	 * Sanitizes one raw value per its field kind.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $kind  Sanitizer kind from FIELDS.
	 * @return string|int|float|null Sanitized value, or null to drop it.
	 */
	private static function sanitize( $value, string $kind ) {
		switch ( $kind ) {
			case 'text':
				$value = trim( sanitize_text_field( (string) $value ) );
				return '' !== $value ? $value : null;

			case 'url':
				$value = is_string( $value ) ? esc_url_raw( trim( $value ) ) : '';
				return '' !== $value ? $value : null;

			case 'price':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				return (float) $value;

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$value = (int) $value;
				return $value > 0 ? $value : null;
		}

		return null;
	}
}
