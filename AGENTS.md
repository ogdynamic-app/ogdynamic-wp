# AGENTS.md

## Quick Start

**This is a WordPress plugin** that connects to the ogdynamic SaaS for dynamic Open Graph image generation.

### Build & Run

- **PHP linting**: `composer lint:php`
- **PHP fix**: `composer fix:php`
- **Frontend dev mode**: `pnpm dev` (runs on `http://127.0.0.1:5173`)
- **Frontend build**: `pnpm build` (admin app + block editor bundle; block only: `pnpm build:block`, watch: `pnpm dev:block`)
- **Type check**: `pnpm typecheck`

### Development Flags

- Enable dev mode: `define( 'OGDYNAMIC_DEV', true );`
- Custom dev server: `define( 'OGDYNAMIC_DEV_SERVER', 'http://custom:5173' );`

## Architecture

### Entry Points

- **Plugin bootstrap**: `ogdynamic.php`
- **Singleton coordinator**: `includes/OGDynamic.php`
- **Admin UI**: `src/admin/main.ts` → Vue 3 app
- **REST API**: `ogdynamic/v1/*` routes in `includes/RestController.php`

### Key Components

| Component | Location | Purpose |
|-----------|----------|---------|
| `OAuth` | `includes/OAuth.php` | OAuth 2.0 with PKCE flow |
| `Template` | `includes/Template.php` | Post type → template mapping |
| `ImageGenerator` | `includes/ImageGenerator.php` | Dynamic image URL construction |
| `MetaTags` | `includes/MetaTags.php` | Output OG/Twitter meta tags |
| `Admin` | `includes/Admin.php` | Admin page & asset loading |
| `Block` | `includes/Block.php` | Registers/renders the `ogdynamic/canvas-image` block |
| `BlockController` | `includes/Controllers/BlockController.php` | Block REST endpoints (canvas proxy, generate & save) |
| `EmailBanners` | `includes/EmailBanners.php` | Email banner model, CDN URL/markup builders, `[ogdynamic_banner]` shortcode |
| `EmailBannerSources` | `includes/Integrations/EmailBannerSources.php` | Shared "abandoned cart data" source catalog + core (site) resolver |
| `SendContext` | `includes/Integrations/SendContext.php` | Normalized per-recipient send data (DTO) — integrations extract, resolution/formatting is centralized |
| `IntegrationManager` | `includes/Integrations/IntegrationManager.php` | Email-plugin integration registry (boots integrations, merges source declarations, dispatches send-time resolution) |
| `FunnelKitIntegration` | `includes/Integrations/FunnelKitIntegration.php` | Registers one FunnelKit merge tag per banner |
| `CartBountyIntegration` | `includes/Integrations/CartBountyIntegration.php` | Injects the configured default banner into CartBounty recovery emails |
| `EmailBannersController` | `includes/Controllers/EmailBannersController.php` | Email banner CRUD REST endpoints |

### Data Flow

1. Admin sets template mappings per post type (stored as options)
2. On frontend: `MetaTags` → `ImageGenerator` → `Template::get_mapping()`. Mappings store the canvas' `width`/`height`; meta tags use the stored dims (null → standard 1200×630) and the Twitter image link is only emitted for Twitter-compatible sizes: 1200×630 renders the `intent=twitter` variant, 1200×600 (Twitter's recommended 2:1) reuses the `og:image` URL as-is, other sizes send just the `og:image` link
3. `ImageGenerator` resolves field values from post/term objects
4. Constructs URL `https://cdn.ogdynamic.com/c/{template_id}?{resolved_params}`; mappings store the canvas' `width`/`height` and only `type: canvas` mappings render — stored `type: design` mappings are pruned on read and the post type is treated as unmapped

### Template Sources (SaaS)

Templates are ogdynamic **canvases** (the design feature has been removed from the plugin):

- **Canvas**: user-designed in the SaaS canvas editor. List/detail via `GET v1/canvases`, `GET v1/canvases/{id}` (scope `canvases:read`). The index supports filters: `status` (lowercase enum, e.g. `public`), `width`/`height` (exact-match integers, 1–10000), and returns a **Laravel cursor pagination** response (`data[]` + `next_cursor`; page via `&cursor=…`). Both canvas pickers fetch `v1/canvases?status=public` page one and infinite-scroll (load the next page when the dropdown list is scrolled near its end or too short to scroll) — all public canvases are selectable, regardless of size. The block editor picker is a `ComboboxControl` wrapper (`CanvasCombobox` in `src/block/Edit.tsx`) whose shared paged store lives in `src/block/api.ts`; scroll position is re-anchored across page appends because ComboboxControl auto-selects the first suggestion when the list changes. Overridable fields come from `override_fields[]` — `{field, name, type, default}` where `field` is the layer's `fieldId` (the query-param name) and image-type layers take a URL value. Only **public** canvases render OG images; the mapping page warns when a saved mapping points to a private or non-1200×630 canvas (such canvases still load by id).

### API Endpoints

- `GET /ogdynamic/v1/connection` - Get connection status
- `POST /ogdynamic/v1/connection/oauth/start` - Start OAuth flow
- `DELETE /ogdynamic/v1/connection` - Disconnect
- `GET /ogdynamic/v1/templates` - List available templates
- `GET /ogdynamic/v1/templates/{post_type}` - Get template for post type
- `PUT /ogdynamic/v1/templates/{post_type}` - Update template mapping (optional `type`: `canvas` only, default `canvas`; optional `width`/`height` ints, default 1200/630, the canvas' dimensions)
- `DELETE /ogdynamic/v1/templates/{post_type}` - Remove template mapping
- `GET /ogdynamic/v1/block/canvases` - Public canvases for the block (`edit_posts`); accepts optional `cursor` and returns `data[]` + `next_cursor` (null when exhausted)
- `GET /ogdynamic/v1/block/canvases/{id}` - Canvas incl. `override_fields` for the block (`edit_posts`)
- `POST /ogdynamic/v1/block/generate` - `canvas_id`, `params` (flat string map), optional `post_id`, `alt`, `format` (`png`|`webp`|`jpeg`, default `png`); saves the rendered image to the media library (`edit_posts` + `upload_files`)
- `GET /ogdynamic/v1/email-banners` - All banners with the source catalog, integration statuses, and the integration→default-banner map (`manage_options`)
- `PUT /ogdynamic/v1/email-banners/integrations/{slug}` - Set/clear an integration's default banner: `banner_id` (12-hex or `''` to clear) (`manage_options`)
- `POST /ogdynamic/v1/email-banners` - Create banner: `name` (required), `canvas_id` (ULID), `map` (`{attr_key, key, value?}` — `key` is a source key, `custom`, or `''`), `link` (`recovery`|`none`|URL, default `recovery`), `display_width` (100–1200, default 600), plus canvas snapshot `canvas_name`/`width`/`height` (`manage_options`)
- `PUT /ogdynamic/v1/email-banners/{id}` - Update banner (same args)
- `DELETE /ogdynamic/v1/email-banners/{id}` - Delete banner

### Canvas Image Block

`ogdynamic/canvas-image` (`blocks/canvas-image/block.json`, TypeScript/React source in `src/block/`, built by `vite.block.config.ts` into `dist/block/ogdynamic-block.{js,css}` as a classic IIFE script — `@wordpress/*` packages are externals mapped to `wp.*` globals, JSX uses the `createElement` pragma, script deps are declared in `Block::register_block()`).

- Editor: the inspector lists public canvases and the selected canvas' `override_fields`; typed values stay in local state and are only committed to the `params` attribute on blur, which is what changes the live preview `<img>` URL (`cdn/c/{id}?{params}`). A Format dropdown (PNG, WebP, JPEG) appends `config[format]=FORMAT` to the preview and render URLs for non-PNG choices. **Generate** posts to `POST /block/generate`.
- Attributes JSON stores `canvasId`, `canvasName`, `params`, `width`, `height`, `format`, `alt`, `attachmentId`, `imageUrl`, `savedQuery`, `savedAt`. `savedQuery` is compared with the current params to show "Unsaved changes" and disable the button when nothing changed.
- Server: `BlockController` proxies `v1/canvases` with the stored OAuth token (editors never get the token; permission `edit_posts`, generate also needs `upload_files`). Generate sideloads the CDN image via `download_url()` + `media_handle_sideload()`, tags the attachment with `_ogdynamic_hash` / `_ogdynamic_canvas_id` / `_ogdynamic_params` / `_ogdynamic_source_url`, and reuses an existing attachment with the same hash (md5 of canvas id + canvas `updated_at` revision + sorted flat query) instead of downloading again; a non-PNG `format` appends `config[format]=FORMAT` to the render URL and hash. Only public canvases are accepted.
- Frontend: `Block::render()` (dynamic block, `save` returns null) outputs `<figure>` + `wp_get_attachment_image()` of `attachmentId` (falls back to `imageUrl`); nothing is output until an image has been saved.

### Email Banners (FunnelKit Integration)

Personalized banner images for abandoned-cart emails. An **email banner** pairs a canvas with a field mapping (each banner in its own option `ogdy_banner_{12-hex id}` — the `Template::option_name()` pattern, so saves touch only their own row and uninstall's prefix sweep cleans them). Admin UI: `src/admin/views/EmailBannersView.vue` (list + FunnelKit status card) and `EmailBannerEditView.vue` (canvas picker + field mapping + live preview), route `/email-banners`.

- **Merge tags**: `FunnelKitIntegration` (available when `BWFAN_Merge_Tag_Loader` exists) hooks `bwfan_merge_tags_loaded` and registers **one FunnelKit merge tag per banner** under the group "ogdynamic": `{{ogd_banner_{id}}}`. Registration reads only the local option — **no SaaS calls** — so it is cheap and cron-safe (FunnelKit sends via ActionScheduler/cron; `IntegrationManager::register()` boots unconditionally from `OGDynamic::boot()`). Per-banner tags use `class_alias(BannerTag::class, 'OgdFkTag_{ID}')` (`BannerTag extends \BWFAN_Merge_Tag`, loadable only while FunnelKit is active) — but the banner payload is passed to each instance via `BannerTag::$next_banner` at registration time because **PHP 8.5 resolves `static::class` on an aliased class to the original class name** (a static map keyed by `static::class` — the pattern the countdownbuilder plugin uses — collapses every alias onto one entry there), and `get_slug()` is overridden with the tag name so registry slugs stay unique per banner.
- **Send time** (`BannerTag::parse_shortcode()`): merge tags receive per-recipient data via `BWFAN_Merge_Tag_Loader::get_data()` — `cart_details` (fallback `BWFAN_Model_Abandonedcarts::get()` via `cart_abandoned_id`), `contact_id`, `is_preview`. The tag returns the full email-safe `<img>` markup (`EmailBanners::image_markup()`), optionally wrapped in the cart recovery URL. URL-only rendering: the CDN renders at email open, zero send-time downloads.
- **Source architecture (integration-agnostic by design)**: the banner mapping stores abstract source keys (`customer_first_name`, `product_title`, …) defined in the **shared catalog** `Integrations/EmailBannerSources.php` — never integration-specific data — so saved mappings stay portable across integrations. Each `Integration` declares `resolvable_sources()` (which catalog keys it can fill) and `send_context()` (extracts its plugin's send-time data into a normalized **`SendContext`** DTO — extraction only). Resolution and presentation live once in `SendContext`: `from_array()` sanitizes/casts every field (single validation point; unknown keys ignored, unparseable values dropped), `get( $key )` formats per catalog key (names `ucwords`-capitalized, prices `wc_price`-formatted with the cart currency, full name joined) — integrations cannot drift apart in presentation. `IntegrationManager::sources()` builds the mapping-UI dropdown as core sources (site name/tagline, resolved from plain WP data with no integration) **plus the union of active integrations' declared keys** — deactivating an integration honestly shrinks the dropdown, while REST map validation accepts the *full* catalog (banners saved earlier stay editable). At render time `IntegrationManager::resolve_sources( $keys )` batches a whole mapping into **one** context build: core first, then the first active integration whose context carries data (an integration mid-send has data; the others build empty contexts). The context is deliberately **not memoized across the request** — batch senders render several recipients per process, and a cached context would leak one recipient's data into the next email.
- **Attribute overrides**: any tag attr named like a canvas field overrides that param for the send, e.g. `{{ogd_banner_abc coupon="SAVE10"}}`; `link`/`width`/`alt` override the saved settings. In the FunnelKit builder (`is_preview`) the banner renders the **plain canvas with its designed default field values** — no recipient data is substituted in previews (placeholder person/product values would mislead users into thinking they are real content).
- **Universal shortcode**: `[ogdynamic_banner id="…" width alt link key="value" …]` (registered unconditionally) — outside a send, dynamic sources resolve empty and canvas defaults render; non-reserved attrs become params.
- **Sources catalog** (`Integrations/EmailBannerSources.php`, shared): Customer first/last/full name, Cart total/items count, First product title/price/image (URL — image-type canvas fields)/SKU, Site name/tagline. Map entries may also be `custom` (static value) or `''` (canvas default). Only whitelisted sources resolve — email addresses never enter URLs. FunnelKit's context builder lives in `Integrations/FunnelKit/Sources.php`; a future integration implements the `Integration` interface (availability, init, `resolvable_sources()`, `send_context()`) and gets the catalog, dropdown, previews, REST, and shortcode for free.
- **CartBounty integration** (`Integrations/CartBountyIntegration.php`): CartBounty's free WordPress recovery emails have a fixed template (no merge tags), so this integration injects **one configured default banner** (per-integration setting via `IntegrationManager::get/set_integration_banner()`, option `ogdy_integration_banner_cartbounty`) on the `cartbounty_automation_after_intro` action. The recipient's cart row is captured on the `cartbounty_automation_unsubscribe_url` filter, which fires exactly once per real email render right before the template actions — the URL ends in `-{cart_id}`, identifying the row in CartBounty's own table (see `CartBounty/Sources.php`; recovery URL mirrors `create_cart_url()`'s hmac formula). The capture is cleared after each render so a following test/preview render (which fires no filter) can never leak the previous recipient's data. Test/preview emails render canvas defaults. Pro's template hooks (`cartbounty_pro_automation_{template}_{after_intro|after_title}_{step}` for templates light/rows/columns, steps 1–3) are registered to the same renderer as best-effort for Pro installs.
- Admin SPA list/edit views preview the plain canvas URL via `src/admin/bannerPreview.ts` — no query params; personalization applies at send time only.
- **Extension filters** (for other plugins; every filter falls back to its pre-filter value when a callback returns garbage — one broken extension can never disable banner rendering site-wide):
  - `ogdynamic_integrations` — register additional `Integration` implementations (class strings; entries not implementing the interface are ignored). Booted, status-carded, and context-dispatched like built-ins — this is how a third party can ship its own email-plugin adapter as a separate plugin
  - `ogdynamic_email_banner_sources` — offer additional mapping sources (`{key, label, group?, type: 'text'|'image'}` entries appended to the offered list; keys are sanitized `a-z0-9_` 2–64 chars, invalid entries dropped). REST map validation accepts whatever keys are offered
  - `ogdynamic_email_banner_resolved_sources` — fill/override resolved source values at render time (`$resolved, $keys`); non-string values are dropped
  - `ogdynamic_email_banner_params` — adjust canvas params before the render URL is built (`$params, $banner`; e.g. append UTM tags). Everything returned passes the same sanitization (key regex, length caps, non-scalars dropped)
  - `ogdynamic_email_banner_markup` — adjust the final `<img>`/link markup (`$markup, $banner, $params, $args`); non-string returns ignored

### Template Fields

Supported sources by post type (see `Template::get_sources()`):
- **All**: `site_name`, `site_tagline`
- **Posts/Pages**: `post_title`, `excerpt`, `trimmed_content`, `featured_image`, `author_name`, `published_date`, `modified_date`, `category`, `tags`
- **Products**: All post fields + `product_short_description`, `product_price`, `regular_price`, `sale_price`, `sku`, `product_category`, `product_tags`, `product_attributes`, `stock_status`, `rating`, `review_count`

**ACF**: When Advanced Custom Fields is active, fields from field groups whose location rules target the post type are appended as mappable sources — key `acf_{acf_field_key}`, label `{Field label}`, `group` set to the field group title, and an `ACF` badge; the source dropdown groups core sources under "WordPress" and ACF ones under each field group title (see `Template::get_acf_sources()`). At render time (`ImageGenerator::acf_field_value()`): image/file/gallery fields resolve to an attachment URL in any return format (galleries use the first image), link fields to their URL, multi-value fields join scalar members, post titles, and term names with `, `, and everything else is cast to a tag-free string. Repeater/flexible-content/group fields are excluded (no single scalar value). Sources vanish gracefully (value `''`) if ACF is deactivated after mappings are saved.

## Conventions

- **Namespace**: `OGDynamic\` (PSR-4 → `includes/`)
- **Option prefix**: `ogdy_` (see `Settings::PREFIX`)
- **Admin page**: `toplevel_page_ogdynamic`
- **Vue router**: Hash mode (`createWebHashHistory`)
- **REST version**: `ogdynamic/v1`
- **Client ID**: Hardcoded constant `OGDYNAMIC_CLIENT_ID`

### PHP Standards

- PHP_CodeSniffer with WordPress Coding Standards
- Minimum PHP: 7.4
- Autoload via Composer (required)

### Frontend Stack

- Vue 3 + TypeScript + Composition API
- Vite 8 + `@vitejs/plugin-vue`
- Tailwind CSS 4 + `@tailwindcss/vite`
- Reka UI 2.9 (design system)
- Vue Router 4.6 (hash history)

### Build Output

- Dev: Vite serves from `/src/admin/main.ts`
- Production: `dist/admin/.vite/manifest.json` with entry `src/admin/main.ts`

## Gotchas

1. **Composer required**: Plugin checks for `vendor/autoload.php` and shows admin notice if missing
2. **SEO plugin conflicts**: `MetaTags` hooks into Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework, Squirrly, and Slim SEO to avoid duplicate meta output
3. **OAuth callback**: Handled via `ogdynamic-oauth-callback` admin page (no menu)
4. **Template ID format**: 26-char ULID string (regex: `/^[0-9A-HJKMNP-TV-Z]{26}$/i`)
5. **Transient keys**: All prefixed with `ogdy_` (via `Settings::option_name()`)
6. **Dev mode bypasses build**: Loads `main.ts` directly via Vite client, no manifest needed
7. **OAuth scope includes `canvases:read`**: tokens issued before this scope was added lack canvas access — sites must disconnect/reconnect; the template editor shows a reconnect notice when the canvas list fails
8. **Canvas query params are flat strings**: the render service ignores array-style params (`tags[]=`). Multi-value sources (`tags`, `product_tags`, `product_attributes`) are flagged `multi` in `Template::get_sources()` and excluded from mappings in the mapping UI (dropdown, auto-map, and saved-entry pruning in `syncFieldMap()`). The twitter image variant uses flat `intent=twitter`
9. **Render URL params decode HTML entities**: WordPress can store field values entity-encoded (`&#8211;` for an en dash — common with feed-imported posts), and `http_build_query()` URL-encodes them literally, so the canvas would render the entity text. `ImageGenerator::build_query()` decodes one entity pass (`html_entity_decode`, `ENT_QUOTES | ENT_HTML5`) on every resolved value before encoding; double-encoded entities survive as one layer

## Testing

No test suite configured. Manual testing required:

1. Activate plugin
2. Run `composer install` (autoloader required)
3. Run `pnpm build` (production) or `pnpm dev` (development)
4. Navigate to WordPress admin → ogdynamic
5. Connect via OAuth
6. Configure template mappings
7. View frontend to verify OG meta tags

## External Services

- **API**: `https://ogdynamic.com/api` (`v1/canvases`, `v1/me`)
- **CDN**: `https://cdn.ogdynamic.com/` (`c/{canvas_id}`)
- **SaaS editor**: `https://ogdynamic.com/canvases/create`, `/canvases/{id}/edit`
- **OAuth endpoint**: `/oauth/token`, `/oauth/authorize`
- **Redirect URI**: `admin.php?page=ogdynamic-oauth-callback`
