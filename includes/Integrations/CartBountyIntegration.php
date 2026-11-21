<?php
/**
 * CartBounty integration.
 *
 * @package OGD
 */

namespace OGDynamic\Integrations;

use OGDynamic\CartBanners;
use OGDynamic\Integrations\CartBounty\Sources;

/**
 * Class CartBountyIntegration
 *
 * Renders personalized banners inside CartBounty's WordPress recovery
 * emails. CartBounty's free email body is a fixed template, so banners
 * are assigned per email directly on the banner record: each banner may
 * claim one CartBounty automation email ("First email", …) and one
 * position in it; at send time the integration renders whichever banner
 * is assigned to the email being sent, personalized from the cart being
 * emailed.
 */
class CartBountyIntegration implements Integration {

	/**
	 * Banner positions in the email template (hook suffix → label).
	 *
	 * Each value is the suffix of the free plugin's zero-arg
	 * `cartbounty_automation_{position}` template action.
	 */
	public const PLACEMENTS = array(
		'before_title' => 'Above the heading',
		'after_title'  => 'Below the heading',
		'after_intro'  => 'After the content text',
		'after_button' => 'Below the button',
		'footer_start' => 'In the footer',
	);

	/**
	 * Default placement when a saved assignment carries an unknown one.
	 */
	public const DEFAULT_PLACEMENT = 'after_intro';

	/**
	 * Hooks into CartBounty's email render.
	 *
	 * The cart and the automation step (email) number are captured on the
	 * `cartbounty_automation_unsubscribe_url` filter, which fires exactly
	 * once per real recovery email render; the render listens on every
	 * free-template position action and echoes the banner on the one that
	 * matches the assigned placement. Pro's builder fires
	 * `cartbounty_pro_automation_{template}_{position}_{step}` hooks —
	 * those are registered too so Pro users get the banner without extra
	 * setup, best-effort until they can be verified against a Pro install.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'cartbounty_automation_unsubscribe_url', array( Sources::class, 'capture' ), 10, 2 );

		foreach ( array_keys( self::PLACEMENTS ) as $placement ) {
			add_action( "cartbounty_automation_{$placement}", array( self::class, 'render_banner' ) );
		}

		// Preview/test renders carry no cart, so the capture filter never
		// fires — recover the step from the request (CartBounty's own
		// callbacks read the same field) at priority 9, before their
		// priority-10 renderer, so the assigned banner still shows.
		add_action( 'wp_ajax_email_preview', array( self::class, 'capture_preview_step' ), 9 );
		add_action( 'wp_ajax_send_test', array( self::class, 'capture_preview_step' ), 9 );

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
	 * `emails` and `placements` feed the per-banner "Use in CartBounty"
	 * dialog: the automation emails read from CartBounty's own steps
	 * option (so the list follows whatever steps the site has), the
	 * positions from the template hooks.
	 *
	 * @return array{slug: string, label: string, available: bool, active: bool, note: string, emails: array[], placements: array[]}
	 */
	public static function status(): array {
		$available = self::is_available();
		$active    = false;

		foreach ( CartBanners::get_all() as $banner ) {
			if ( ! empty( $banner['cartbounty'] ) ) {
				$active = true;
				break;
			}
		}

		$note = $available
			? __( 'Assign a banner to a recovery email from any banner below — it renders personalized for every recipient of that email.', 'ogdynamic' )
			: __( 'Install and activate CartBounty to insert banners into its abandoned cart recovery emails.', 'ogdynamic' );

		return array(
			'slug'       => self::slug(),
			'label'      => self::label(),
			'available'  => $available,
			'active'     => $active,
			'note'       => $note,
			'emails'     => self::emails(),
			'placements' => self::placement_options(),
		);
	}

	/**
	 * Lists CartBounty's automation emails for the assignment dialog.
	 *
	 * Built from CartBounty's `cartbounty_automation_steps` option — the
	 * same keys the send loop passes to the capture filter — so the list
	 * always matches the emails the site can actually send. The option
	 * does not store step names, so they come from a local map mirroring
	 * CartBounty's defaults.
	 *
	 * @return array[] Entries: {id, name, enabled}.
	 */
	public static function emails(): array {
		$steps = get_option( 'cartbounty_automation_steps' );

		if ( ! is_array( $steps ) || array() === $steps ) {
			$max  = defined( 'CARTBOUNTY_MAX_STEPS' ) ? (int) constant( 'CARTBOUNTY_MAX_STEPS' ) : 3;
			$keys = range( 0, max( 1, $max ) - 1 );
		} else {
			$keys = array_keys( $steps );
		}

		$names = array( 'First email', 'Second email', 'Third email', 'Fourth email' );

		$emails = array();
		foreach ( $keys as $key ) {
			if ( ! is_int( $key ) ) {
				continue;
			}

			$emails[] = array(
				'id'      => $key,
				'name'    => $names[ $key ] ?? sprintf( /* translators: %d: email number. */ __( 'Email %d', 'ogdynamic' ), $key + 1 ),
				'enabled' => isset( $steps[ $key ]['enabled'] ),
			);
		}

		return $emails;
	}

	/**
	 * Lists the banner placements for the assignment dialog.
	 *
	 * @return array[] Entries: {value, label}.
	 */
	public static function placement_options(): array {
		$options = array();

		foreach ( self::PLACEMENTS as $value => $label ) {
			$options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return $options;
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
	 * Renders the banner assigned to the email being sent.
	 *
	 * Called on every template position action; echoes the banner only on
	 * the action matching the assigned placement. The captured cart is
	 * cleared after the render — or on the footer action, the last one in
	 * the free template, when no placement matched — so a following test
	 * or preview render in the same process can never leak the previous
	 * recipient's data.
	 *
	 * @return void
	 */
	public static function render_banner(): void {
		$hook       = (string) current_action();
		$step       = self::hook_step( $hook ) ?? Sources::current_step();
		$assignment = null === $step ? null : self::assignment_for( $step );

		if ( null !== $assignment && self::placement_matches( $assignment['placement'], $hook ) ) {
			$banner = $assignment['banner'];

			if ( ! empty( $banner['id'] ) ) {
				// One context build serves both the params and the
				// recovery link; spacing comes from the banner's own
				// email settings.
				$context = IntegrationManager::context();
				$params  = CartBanners::resolve_params( $banner, $context );
				$args    = array(
					'link'          => (string) ( $banner['link'] ?? CartBanners::LINK_RECOVERY ),
					'alt'           => (string) $banner['name'],
					'recovery_url'  => $context->recovery_url(),
					'margin_top'    => (int) ( $banner['margin_top'] ?? 0 ),
					'margin_bottom' => (int) ( $banner['margin_bottom'] ?? 0 ),
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image_markup() escapes its URL/alt/link attributes.
				echo CartBanners::image_markup( $banner, $params, $args );

				Sources::clear();
				return;
			}
		}

		// The footer action is the last one in the free template — the
		// catch-all clear for renders that matched no placement.
		if ( 'cartbounty_automation_footer_start' === $hook ) {
			Sources::clear();
		}
	}

	/**
	 * Recovers the automation step for preview/test renders.
	 *
	 * CartBounty's "Preview email" and "Send test" AJAX requests carry the
	 * step in `$_POST['step']`; with it, the assigned banner renders at its
	 * position with canvas defaults (there is no cart to personalize from).
	 * Without it — or when the field changes name — nothing renders, same
	 * as before. Never overrides a real send's captured step.
	 *
	 * @return void
	 */
	public static function capture_preview_step(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read-only context; CartBounty's own callback verifies the nonce.
		$step = isset( $_POST['step'] ) ? absint( wp_unslash( $_POST['step'] ) ) : -1;

		if ( $step >= 0 ) {
			Sources::set_preview_step( $step );
		}
	}

	/**
	 * Finds the banner assigned to an automation email.
	 *
	 * @param int $step Automation step (email) number.
	 * @return array|null {banner: array, placement: string} or null.
	 */
	protected static function assignment_for( int $step ): ?array {
		foreach ( CartBanners::get_all() as $banner ) {
			$assignment = $banner['cartbounty'] ?? null;

			if ( ! is_array( $assignment ) || (int) ( $assignment['email'] ?? -1 ) !== $step ) {
				continue;
			}

			$placement = (string) ( $assignment['placement'] ?? '' );

			return array(
				'banner'    => $banner,
				'placement' => isset( self::PLACEMENTS[ $placement ] ) ? $placement : self::DEFAULT_PLACEMENT,
			);
		}

		return null;
	}

	/**
	 * Whether the hook currently firing is the assignment's render spot.
	 *
	 * Free hooks map by placement suffix. Pro hooks only expose two
	 * positions, so placements map to the nearest one — best-effort until
	 * they can be verified against a Pro install.
	 *
	 * @param string $placement Assignment placement key.
	 * @param string $hook      Hook currently firing.
	 * @return bool True when the banner should render on this hook.
	 */
	protected static function placement_matches( string $placement, string $hook ): bool {
		if ( ! isset( self::PLACEMENTS[ $placement ] ) ) {
			$placement = self::DEFAULT_PLACEMENT;
		}

		if ( 0 === strpos( $hook, 'cartbounty_pro_automation_' ) ) {
			$position = in_array( $placement, array( 'before_title', 'after_title' ), true ) ? 'after_title' : 'after_intro';

			return 1 === preg_match( '/_' . $position . '_\d+$/', $hook );
		}

		return 'cartbounty_automation_' . $placement === $hook;
	}

	/**
	 * Extracts the Pro email number from a Pro hook name.
	 *
	 * Pro hook names end in their 1-based email number; assignments use
	 * the free plugin's 0-based step keys, so the number is shifted.
	 *
	 * @param string $hook Hook name.
	 * @return int|null Step number, or null for free hooks.
	 */
	protected static function hook_step( string $hook ): ?int {
		if ( 1 !== preg_match( '/^cartbounty_pro_automation_[a-z]+_[a-z_]+_(\d+)$/', $hook, $matches ) ) {
			return null;
		}

		return max( 0, (int) $matches[1] - 1 );
	}
}
