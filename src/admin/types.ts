export type OGDCanvasOverrideField = {
  field: string;
  name: string;
  type: string;
  default: string | null;
};

export type OGDCanvasSummary = {
  id: string;
  user_id: number;
  name: string;
  width: number;
  height: number;
  status: string | null;
  image_url: string;
  created_at: string;
  updated_at: string;
};

export type OGDCanvas = OGDCanvasSummary & {
  background: unknown;
  items: unknown;
  override_fields: OGDCanvasOverrideField[];
};

export type PostTypeOption = {
  name: string;
  label: string;
  description?: string;
  /** Pill shown on the template list card (e.g. "WooCommerce"). */
  badge?: string;
};

export type RuntimeConfig = {
  restUrl: string;
  apiUrl: string;
  cdnUrl: string;
  nonce: string;
  apiKey: string;
};

export type ApiData<T> = {
  data: T;
};

/** Laravel cursor pagination response from the cloud API. */
export type CursorPage<T> = {
  data: T[];
  next_cursor?: string | null;
  /** API resources wrap the paginator, nesting the cursor inside `meta`. */
  meta?: { next_cursor?: string | null };
};

export type CartBannerMapEntry = {
  attr_key: string;
  /** Source key, `custom`, or `''` (canvas default). */
  key: string;
  /** Static value used when `key` is `custom`. */
  value?: string;
};

/** CartBounty assignment stored on the banner: one email + one position. */
export type CartBountyAssignment = {
  /** CartBounty automation step key (0 = First email). */
  email: number;
  /** Template position key (see CartBountyIntegration::PLACEMENTS). */
  placement: string;
};

export type CartBanner = {
  id: string;
  name: string;
  canvas_id: string;
  canvas_name: string;
  width: number;
  height: number;
  /** `recovery`, `none`, or a URL. */
  link: string;
  display_width: number;
  map: CartBannerMapEntry[];
  /** CartBounty email assignment, or null when the banner is unused there. */
  cartbounty?: CartBountyAssignment | null;
  created_at: string;
  updated_at: string;
};

export type CartBannerSource = {
  key: string;
  label: string;
  group: string;
  /** `text` or `image`. */
  type: string;
};

/** CartBounty automation email listed for the assignment dialog. */
export type CartBountyEmail = {
  id: number;
  name: string;
  enabled: boolean;
};

/** CartBounty banner position listed for the assignment dialog. */
export type CartBountyPlacement = {
  value: string;
  label: string;
};

export type IntegrationStatus = {
  slug: string;
  label: string;
  available: boolean;
  active: boolean;
  note: string;
  /** CartBounty: automation emails and banner positions for its dialog. */
  emails?: CartBountyEmail[];
  placements?: CartBountyPlacement[];
};

export type CartBannersPayload = {
  data: CartBanner[];
  sources: CartBannerSource[];
  integrations: IntegrationStatus[];
};

export interface User {
  id: number;
  name: string;
  email: string;
}

declare global {
  interface Window {
    ogdynamicAdmin: RuntimeConfig;
  }
}
