import type { EmailBanner, EmailBannerSource } from "./types";

/**
 * Builds the sample-data query string for a banner, mirroring
 * EmailBanners::sample_params() on the PHP side: custom values pass
 * through, mapped sources use their catalog sample, empty values drop,
 * and keys are sorted so identical mappings give identical URLs.
 */
export function bannerSampleParams(
  banner: Pick<EmailBanner, "map">,
  sources: EmailBannerSource[],
): string {
  const samples = new Map(sources.map((source) => [source.key, source]));
  const params: Record<string, string> = {};

  for (const entry of banner.map) {
    if (!entry.attr_key) {
      continue;
    }

    if (entry.key === "custom") {
      params[entry.attr_key] = entry.value ?? "";
    } else if (entry.key && samples.has(entry.key)) {
      params[entry.attr_key] = samples.get(entry.key)?.sample ?? "";
    }
  }

  const query = Object.entries(params)
    .filter(([, value]) => value !== "")
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join("&");

  return query;
}

/** Full CDN preview URL for a banner rendered with sample data. */
export function bannerPreviewUrl(
  banner: Pick<EmailBanner, "map" | "canvas_id">,
  sources: EmailBannerSource[],
): string {
  const query = bannerSampleParams(banner, sources);

  return `${window.ogdynamicAdmin.cdnUrl}c/${banner.canvas_id}${query ? `?${query}` : ""}`;
}
