# Feature Gating — Zero Execution for Unused Features

**Goal:** a customer-facing request must only execute what that customer actually
uses. Hook registrations, filters, and DB queries for unused features — OG
mappings, cart banners, and every current/future email-plugin integration —
must not run at all, not merely be cheap. We plan ~10–20 integrations; no
customer uses them all, so integration boot must be usage-gated, not just
availability-gated.

**Principles:**

1. **Truth lives in the database, mirrored into one autoloaded bitmask.** The
   bits are derived, never primary — every refresh recomputes them from the
   actual option rows, so the mask can never silently contradict reality for
   long.
2. **Refresh strictly admin-side.** Writes to the mask happen only where the
   customer is operating our plugin (admin page loads, our REST mutations,
   activation). Frontend, cron, and email-send paths only ever *read* the mask
   — and reading it costs nothing (it rides in WordPress' `alloptions` cache).
3. **Fail open, never fail off.** A missing mask, a lost option, or a bit the
   stored mask doesn't know about (new feature added by a plugin update) falls
   back to today's ungated behavior. A perf optimization must never be able to
   break a customer's rendering.
4. **Gate at the boot choke points, not deep in leaf code.** Skipping
   `add_action`/`add_shortcode` beats guarding inside callbacks — no hook
   registration, no callback invocation, no query.

---

## 1. Execution inventory — what runs today on every frontend request

`OGDynamic::boot()` (`plugins_loaded`) runs five steps unconditionally:

| Boot step | Cost on a frontend page view today | Gated after this plan |
|---|---|---|
| `RestController::init()` | 1 `add_action( 'rest_api_init' )`. The callback **never fires** on page views (`rest_api_init` only runs for `/wp-json/` requests); REST must register unconditionally because REST requests don't run under `is_admin()`. | **Unchanged on purpose** — already zero page-load cost |
| `Block::register()` | `register_block_type()` + `block.json` file read (needed on frontend: posts containing the block must render). Editor assets already gated behind `is_admin()`. | Deferred — see Appendix A |
| `CartBanners::register_shortcode()` | 1 `add_shortcode` | Skipped when the banners bit is off |
| `IntegrationManager::register()` | For each integration: availability check (free `class_exists`/`defined`) then `init()` — FunnelKit: 1 hook; CartBounty: 1 filter + 5 actions + 2 AJAX actions (+60 more when Pro is active). FunnelKit's send-time callback also runs a `get_all()` LIKE query per send on banner-less sites. **This grows ~linearly to hundreds of registrations with 10–20 integrations.** | Per-integration usage bit — unused integration costs one `class_exists` + one bit test, **0 registrations** |
| `MetaTags::register()` (non-admin only) | Several `wp_head`/SEO-plugin hook registrations; the callback fires on every page view and performs **1–2 `wp_options` SELECTs** (`Template::get_mapping()` + `default` fallback miss) — the plugin's only frontend DB bill | Skipped entirely when the OG-mappings bit is off |

Transitive effects of gating the boot steps: `IntegrationManager::context()`,
`resolve_sources()`, `CartBanners::get_all()`/`get()` are only reached through
paths that the guards turn off (no shortcode, no merge tags, no wp_head
callback), so they need no separate boot gating — they get read-guards anyway
as defense in depth (§4).

---

## 2. The `FeatureFlags` class (`includes/FeatureFlags.php`)

One class owns every feature flag: the bit constants, the read, and the write.
`Settings` stays a plain option store; `FeatureFlags` holds the semantics.

```php
namespace OGDynamic;

class FeatureFlags {

	/** Bit 0 — any OG template mapping exists (ogdy_mapping_* rows). */
	public const OG_MAPPINGS = 1;

	/** Bit 1 — any cart recovery banner exists (ogdy_cart_banner_* rows). */
	public const CART_BANNERS = 2;

	/** Bit 2 — a banner is assigned to a CartBounty email (integration in use). */
	public const CARTBOUNTY = 4;

	// Future integrations append constants here — bits 3, 4, … (PHP int
	// gives 62 usable bits; see §3 for the allocation workflow).

	/** Settings key → ogdy_unused_features. The plugin's ONLY autoloaded option. */
	private const OPTION_KEY = 'unused_features';

	/** Feature → option prefix the bit is derived from (core bits). */
	private const PREFIXES = array(
		self::OG_MAPPINGS  => Template::POST_TYPE_KEY_PREFIX, // 'mapping_'
		self::CART_BANNERS => CartBanners::OPTION_PREFIX,     // 'cart_banner_'
	);

	/**
	 * Whether a feature is in use. Reads the autoloaded mask — no query.
	 *
	 * The mask stores the bits confirmed UNUSED (inverted), which makes
	 * every failure mode fail open: a missing mask (legacy install / option
	 * lost / hand-edited garbage → Settings::get() yields null or a
	 * non-int) gates nothing, and a bit absent from the stored mask — one
	 * added by a plugin update before the next admin-side refresh — gates
	 * nothing until that refresh settles it.
	 */
	public static function enabled( int $feature ): bool {
		$unused = Settings::get( self::OPTION_KEY, null );

		return null === $unused || ( ( (int) $unused ) & $feature ) === 0;
	}

	/** Marks one bit used (clears) or unused (sets), read-modify-write. */
	public static function set( int $feature, bool $used ): void { /* … */ }

	/** Recomputes one core bit from DB truth (indexed prefix LIKE, LIMIT 1). */
	public static function refresh( int $feature ): void { /* … */ }

	/**
	 * Recomputes every bit — core prefixes + every integration's is_used().
	 * Admin-side only (§5). Loads the banners once and derives all
	 * integration bits from that single scan.
	 */
	public static function refresh_all(): void { /* … */ }
}
```

### Mask storage format

`ogdy_unused_features` = a plain **int**: the bitmask of features the customer
does **NOT** use (inverted), autoloaded (`Settings::update( $key, $value,
true )` — the only autoloaded option in the plugin; a few bytes in
`alloptions`). Inverting is the upgrade-safety mechanism — a forward mask
would need a second `known` int to tell "checked, genuinely unused" apart
from "bit added after this mask was written"; the inverted representation
gets both from absence:

- bit **set** → confirmed unused → gated
- bit **clear** → confirmed in use (or never measured) → runs
- option **missing** (legacy install, hand-deleted, garbage) → null → everything runs
- bit added by a future update → absent from the stored mask → runs

Scenario: 0.7.0 introduces the Mailster integration (bit 3). A site updates
and — before anyone opens the admin — a Mailster cron send runs. The stored
mask was written by 0.6.0 and has bit 3 clear, so `enabled( bit 3 )` fails
**open** and the banner renders. The first admin page load runs
`refresh_all()`, which sets bit 3 if Mailster is genuinely unused, and from
then on the bit is honest. No migration code, no version constants, no
activation hook required for correctness.

### Known limitation (accepted)

`set()` is a read-modify-write; two concurrent admin saves of *different*
features can race and lose one bit (millisecond window, admin-only,
self-healing on the affected feature's next refresh since every refresh
recomputes from DB truth). An atomic SQL bitwise UPDATE would avoid it but
means hand-managing the option row and its autoload flag — not worth it.

---

## 3. Integration gating — the 10–20 integrations story

Availability (is the target plugin active?) is a free live check and stays
live. **Usage** (does the customer actually send our banners through it?) is
what the bits encode — that's the part that requires reading our DB, so it's
the part we mirror.

### How a boot decision is made

```php
// IntegrationManager::register()
foreach ( self::integrations() as $integration ) {
	if ( ! $integration::is_available() ) {
		continue; // Free live check — plugin not active.
	}

	$bit = method_exists( $integration, 'feature_bit' )
		? $integration::feature_bit()
		: FeatureFlags::CART_BANNERS; // Sensible default, also fail-open via §2.

	if ( ! FeatureFlags::enabled( $bit ) ) {
		continue; // Customer never used it → zero hook registrations.
	}

	$integration::init();
}
```

A site with FunnelKit and CartBounty both active but no banners configured:
today ~10 hook registrations (+60 with Pro) per request; after: **0** — just
two `class_exists` calls and two bit tests against the cached mask.

### Usage truth per integration

Each integration declares two **static** methods (checked with
`method_exists`, deliberately *not* added to the `Integration` interface —
third-party classes registered via `ogdynamic_integrations` must not fatal for
lacking them; they get the CART_BANNERS default):

```php
/** FeatureFlags bit this integration gates its boot on. */
public static function feature_bit(): int;

/** Whether the customer uses this integration, derived from all banners. */
public static function is_used( array $banners ): bool;
```

| Integration | Bit | `is_used()` truth |
|---|---|---|
| FunnelKit | `CART_BANNERS` (reused — every banner registers a merge tag, so "any banner" *is* the truth; a separate bit would always equal it) | `$banners !== array()` |
| CartBounty | `CARTBOUNTY` | any banner carries a `cartbounty` assignment |
| *Future* | own constant | whatever its assignment/link data encodes |

`refresh_all()` loads all banner options **once** (admin-side), then derives
every integration bit from that single in-memory scan — adding integration
#11 costs one method call, not another query.

### Checklist for adding integration #N (the workflow this plan buys)

1. Add one bit constant to `FeatureFlags` (bits 3, 4, … — 62 fit in a PHP int).
2. Implement `feature_bit()` + `is_used()` on the integration class.
3. Done — boot gating, refresh, and fail-open upgrades are automatic.

### Accepted imprecision

FunnelKit boots whenever banners exist, even if the customer only sends via
CartBounty — "pasted a merge tag into an email" is not observable from our
tables, and a bit that can't be recomputed from truth would violate Principle
1. Cost of the imprecision: one `add_action` on requests where banners exist.

---

## 4. Guard placement

| Location | Guard | Effect when bit off |
|---|---|---|
| `OGDynamic::boot()` — `MetaTags::register()` call | wrap in `FeatureFlags::enabled( FeatureFlags::OG_MAPPINGS )` | **No wp_head/SEO hooks registered at all** — saves the hook chain, the callback, and the 1–2 mapping SELECTs on every page view |
| `OGDynamic::boot()` — `CartBanners::register_shortcode()` call | wrap in `enabled( CART_BANNERS )` | no `add_shortcode` |
| `IntegrationManager::register()` | per-integration gate (§3) | zero registrations per unused integration |
| `Template::get_mapping()` top | `enabled( OG_MAPPINGS )` → `return array()` | defense in depth for any caller that registers its own hooks (SEO-plugin integrations, third parties) |
| `CartBanners::get_all()` | `enabled( CART_BANNERS )` → `return array()` | skips the LIKE scan — including the one FunnelKit's boot performs per send on banner-less sites; admin REST list stays correct because bits are recomputed on every mutation |
| `CartBanners::get()` | `enabled( CART_BANNERS )` → `return null` | skips the shortcode's option read |

Boot-level guards (rows 1–3) are the real win; the read-guards (rows 4–6)
make the invariant local and obvious so future callers can't reintroduce the
queries. REST route registration and `Block::register()` stay unconditional
(§1, Appendix A).

---

## 5. Refresh triggers — admin-side only

| Trigger | What runs | Why |
|---|---|---|
| `admin_init` (real page loads only: `is_admin() && ! wp_doing_ajax()`) | `FeatureFlags::refresh_all()` | Self-heals *everything* — manual DB edits, plugin updates, bits added by new versions — on every admin visit. Cost: 2 indexed prefix LIKEs + one banner scan, i.e. the same queries the admin page already makes. **The `wp_doing_ajax()` exclusion matters:** `admin_init` also fires on every `admin-ajax.php` request, including shopper-triggered AJAX from other plugins — refreshing there would *add* queries to customer-facing requests, the exact opposite of the goal. |
| `CartBanners::save()` / `delete()` | `refresh( CART_BANNERS )` + integration bits (assignment may have changed) | Mutations through the REST controllers refresh immediately, so the SPA and any concurrent cron send see fresh bits without an admin reload. Living in the model methods (not the controllers) covers every caller. |
| `Template::update_mapping()` / `delete_mapping()` | `refresh( OG_MAPPINGS )` | same |
| `Template::get_mapping()` legacy-design prune branch | `refresh( OG_MAPPINGS )` (only when it actually deleted) | the prune is already a write path; the bit must follow its own truth |
| `register_activation_hook` | `refresh_all()` | seeds the mask on first activate so it exists from request #1 |

Never refreshed on: frontend reads, cron/ActionScheduler sends, email sends.
Those paths only call `enabled()` — an array fetch from the object-cached
`alloptions` blob, no query even on first hit with an external object cache.

---

## 6. Outcome per customer persona

| Persona | Frontend ogdy cost today | After |
|---|---|---|
| Installed, configured nothing (mask all bits set) | 1–2 option SELECTs + ~8 hook regs + every available integration's init | **0 queries, 0 feature hooks** — boot loop does a few `class_exists` + cached bit tests |
| OG mappings only | 1–2 SELECTs (used) + shortcode + all integrations booted | 1–2 SELECTs (used); shortcode + integrations skipped |
| Banners via CartBounty only, no OG mappings | wp_head chain + 1–2 SELECTs (unused!) + CartBounty + FunnelKit hooks | **0 mapping queries, no wp_head hooks**; CartBounty boots (used), FunnelKit boots (accepted §3) |
| Everything used | full stack | identical to today — no regression |

---

## 7. Implementation phases

Each phase lands complete with tests and lint, **uncommitted for manual
review** (standing workflow), and is independently shippable.

### Phase 1 — core mask + OG/banner gating
- `includes/FeatureFlags.php`: constants `OG_MAPPINGS`/`CART_BANNERS`, mask
  option (inverted int — confirmed-unused bits), `enabled()`/`set()`/`refresh()`/`refresh_all()`
  (integration-bit loop lands in Phase 2; `refresh_all()` initially covers
  core bits only).
- Guards: `MetaTags::register()` and `CartBanners::register_shortcode()` wraps
  in `boot()`, `Template::get_mapping()`, `CartBanners::get_all()`/`get()`.
- Refresh triggers: `admin_init` (page loads only), model mutation hooks,
  activation hook.
- Smoke: query-count matrix, bit transitions via throwaway mapping/banner
  create→delete, missing-mask fallback, fail-open simulation (bit absent from
  stored mask / hand-edited garbage value).

### Phase 2 — integration gating
- Bit `CARTBOUNTY`, `feature_bit()`/`is_used()` statics on both integrations,
  the `IntegrationManager::register()` gate, integration-bit derivation in
  `refresh_all()`.
- Smoke: FunnelKit merge tags absent on banner-less site, present after save;
  CartBounty hooks registered only while an assignment exists; delete last
  assignment → hooks gone on next request; third-party integration without
  the statics falls back to CART_BANNERS.

### Phase 3 (deferred) — see Appendix A.

### Verification (both phases)
- `composer lint:php` (pure PHP — no frontend change, but `pnpm typecheck`
  + `pnpm build` run once to confirm no drift)
- wp-cli `$wpdb->num_queries` deltas for the §6 matrix
- E2E: FunnelKit abandoned-cart send and CartBounty recovery email still
  render personalized banners on a fully-flagged site
- `wp option delete ogdy_unused_features` → legacy behavior (queries return,
  banners render) until the next admin load reseeds

---

## 8. Documentation updates (land with the code)

- `AGENTS.md`: `FeatureFlags` row in Key Components; a "Performance gating"
  section (mask semantics, fail-open rules, refresh triggers, the
  `wp_doing_ajax` exclusion, integration `feature_bit()`/`is_used()`
  contract); update the Data Flow / integration bullets that say runs
  unconditionally.
- `CHANGELOG.md`: extend the 0.6.0 "Changed" perf bullet (release not yet
  tagged/shipped) — one line, not a new Unreleased entry.

---

## Appendix A — deferred: gating `Block::register()`

The block's `register_block_type()` must run on frontend requests or posts
containing the block stop rendering. A usage bit would need truth like
"any `ogd-{hash}` attachment slug exists" (indexed `wp_posts.post_name` LIKE)
— but the inference is unsound in the edge where a customer deletes the
attachments while keeping the block in posts (render falls back to the saved
`imageUrl`, which the bit would have turned off). The cost being saved is one
registry insert + a `block.json` file read. Risk/rationale says defer; if
block usage ever shows up in profiling, revisit with a truth source that
survives attachment deletion (e.g. a `has_ogd_block` flag maintained by the
REST generate endpoint — but that's speculative write-side complexity today).
