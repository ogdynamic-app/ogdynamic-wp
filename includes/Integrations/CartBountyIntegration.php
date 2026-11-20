<?php
/**
 * CartBounty integration.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

use OGDynamic\EmailBanners;
use OGDynamic\Integrations\CartBounty\Sources;

/**
 * Class CartBountyIntegration
 *
 * Injects a personalized banner into CartBounty's WordPress recovery
 * emails. Unlike FunnelKit (merge tags picked per email), CartBounty's
 * free email body is a fixed template — so this integration renders the
 * site's configured default banner after the email intro, personalized
 * per recipient from the cart being emailed.
 */
class CartBountyIntegration implements Integration {

	/**
	 * Hooks into CartBounty's email render.
	 *
	 * Free recovery emails fire `cartbounty_automation_*` actions with no
	 * args; the cart is captured separately via the unsubscribe URL filter
	 * (see Sources::capture()). Pro's builder fires
	 * `cartbounty_pro_automation_{template}_{position}_{step}` hooks —
	 * those are registered too so Pro users get the banner without extra
	 * setup, best-effort until they can be verified against a Pro install.
	 *
	 * Users can also place the `[ogdynamic_banner]` token anywhere in the
	 * email text — it expands on the wp_mail filter (CartBounty sends via
	 * wp_mail) and the automatic after-intro injection is then removed, so
	 * exactly one banner renders at the user-chosen spot.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'cartbounty_automation_unsubscribe_url', array( Sources::class, 'capture' ) );
		add_action( 'cartbounty_automation_after_intro', array( self::class, 'render_banner' ) );
		add_filter( 'wp_mail', array( self::class, 'expand_mail_tokens' ) );

		foreach ( array( 'light', 'rows', 'columns' ) as $template ) {
			foreach ( array( 'after_intro', 'after_title' ) as $position ) {
				foreach ( array( 1, 2, 3 ) as $step ) {
					add_action( "cartbounty_pro_automation_{$template}_{$position}_{$step}", array( self::class, 'render_banner' ) );
				}
			}
		}
	}

	/**
	 * Whether CartBounty is installed and active.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'CARTBOUNTY_VERSION_NUMBER' );
	}

	/**
	 * Unique integration slug.
	 *
	 * @return string
	 */
	public static function slug(): string {
		return 'cartbounty';
	}

	/**
	 * Human-readable integration name.
	 *
	 * @return string
	 */
	public static function label(): string {
		return 'CartBounty';
	}

	/**
	 * Status payload for the admin app.
	 *
	 * @return array{slug: string, label: string, available: bool, active: bool, note: string, banner_id: string}
	 */
	public static function status(): array {
		$available = self::is_available();
		$banner_id = self::default_banner_id();

		$note = $available
			/* translators: %s: [ogdynamic_banner] token. */
			? sprintf( __( 'Your default banner is inserted into CartBounty recovery emails after the intro text — or paste %s anywhere in the email text to place it yourself.', 'ogdynamic' ), '[ogdynamic_banner]' )
			: __( 'Install and activate CartBounty to inject banners into its abandoned cart recovery emails.', 'ogdynamic' );

		if ( $available && '' === $banner_id ) {
			$note = __( 'Pick a default banner below and it will be inserted into CartBounty recovery emails after the intro text.', 'ogdynamic' );
		}

		return array(
			'slug'      => self::slug(),
			'label'     => self::label(),
			'available' => $available,
			'active'    => $available && '' !== $banner_id,
			'note'      => $note,
			'banner_id' => $banner_id,
		);
	}

	/**
	 * Source keys CartBounty resolves from its saved cart rows.
	 *
	 * @return string[]
	 */
	public static function resolvable_sources(): array {
		return array(
			'customer_first_name',
			'customer_last_name',
			'customer_full_name',
			'cart_total',
			'cart_items_count',
			'product_title',
			'product_price',
			'product_image',
			'product_sku',
		);
	}

	/**
	 * Builds the send context from the captured cart row.
	 *
	 * @return SendContext
	 */
	public static function send_context(): SendContext {
		return Sources::context();
	}

	/**
	 * Markup echoed by the last automatic after-intro render.
	 *
	 * Remembered so expand_mail_tokens() can remove exactly that banner
	 * when the email text carries a placement token.
	 *
	 * @var string
	 */
	protected static string $last_markup = '';

	/**
	 * Renders the configured banner into the email.
	 *
	 * Echoes nothing when no banner is configured or the banner was
	 * deleted. The captured cart is cleared afterwards so a following
	 * test/preview render (which fires no capture filter) can never leak
	 * the previous recipient's data.
	 *
	 * @return void
	 */
	public static function render_banner(): void {
		self::$last_markup = '';

		$banner_id = self::default_banner_id();

		if ( '' === $banner_id ) {
			Sources::clear();
			return;
		}

		$banner = EmailBanners::get( $banner_id );

		if ( null === $banner ) {
			Sources::clear();
			return;
		}

		$params = EmailBanners::resolve_params( $banner );
		$args   = array(
			'link' => (string) ( $banner['link'] ?? EmailBanners::LINK_RECOVERY ),
			'alt'  => (string) $banner['name'],
		);

		$markup = EmailBanners::image_markup( $banner, $params, $args );

		self::$last_markup = $markup;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image_markup() escapes its URL/alt/link attributes.
		echo $markup;

		Sources::clear();
	}

	/**
	 * Expands `[ogdynamic_banner]` tokens in the outgoing mail body.
	 *
	 * Runs on the wp_mail filter (CartBounty sends through wp_mail) with
	 * the captured cart as personalization context — returned only when
	 * the mail recipient matches the cart's email, so a later test send
	 * to another address can never receive a previous recipient's data.
	 * When the body carries a token, the automatic after-intro banner is
	 * removed from the message so exactly one banner renders.
	 *
	 * @param array $mail Mail args (to, subject, message, headers, attachments).
	 * @return array Filtered mail args.
	 */
	public static function expand_mail_tokens( array $mail ): array {
		$message = (string) ( $mail['message'] ?? '' );

		// No token: consume the snapshot (one capture feeds one mail) and
		// keep the automatic injection untouched.
		if ( false === strpos( $message, '[ogdynamic_banner' ) ) {
			Sources::mail_cart( (string) ( $mail['to'] ?? '' ) );
			return $mail;
		}

		$cart = Sources::mail_cart( (string) ( $mail['to'] ?? '' ) );

		if ( null === $cart ) {
			return $mail;
		}

		// The render capture was already cleared by render_banner(); put
		// the mail-stage snapshot back so EmailBanners::resolve_params()
		// resolves this recipient's cart while expanding the token.
		Sources::set_render_cart( $cart );

		// get_shortcode_regex() ships without delimiters (core wraps it
		// the same way inside do_shortcode()).
		$regex = '@' . get_shortcode_regex( array( 'ogdynamic_banner' ) ) . '@';

		$expanded = preg_replace_callback(
			$regex,
			static function ( $matches ) {
				$atts = shortcode_parse_atts( $matches[3] ?? '' );
				$atts = is_array( $atts ) ? $atts : array();

				// Token without an id renders the configured default banner.
				if ( empty( $atts['id'] ) ) {
					$atts['id'] = self::default_banner_id();
				}

				return EmailBanners::render_shortcode( $atts );
			},
			$message
		);

		Sources::clear();

		if ( is_string( $expanded ) ) {
			$mail['message'] = self::remove_auto_banner( $expanded );
		}

		return $mail;
	}

	/**
	 * Removes the automatically injected banner from the message body.
	 *
	 * @param string $message Expanded message.
	 * @return string Message without the first auto-injected banner occurrence.
	 */
	protected static function remove_auto_banner( string $message ): string {
		$markup = self::$last_markup;

		self::$last_markup = '';

		if ( '' === $markup ) {
			return $message;
		}

		$position = strpos( $message, $markup );

		if ( false === $position ) {
			return $message;
		}

		return substr( $message, 0, $position ) . substr( $message, $position + strlen( $markup ) );
	}

	/**
	 * Gets the configured default banner id.
	 *
	 * @return string 12-hex banner id, or an empty string when unset.
	 */
	public static function default_banner_id(): string {
		$banner_id = (string) IntegrationManager::get_integration_banner( self::slug() );

		return preg_match( '/^[a-f0-9]{12}$/', $banner_id ) ? $banner_id : '';
	}
}
