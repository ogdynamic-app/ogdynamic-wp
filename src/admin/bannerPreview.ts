import type { EmailBanner } from "./types";

/**
 * Preview URL for a banner: the plain canvas render with its designed
 * default field values. No recipient data is substituted — placeholder
 * person/product values in previews mislead users into thinking they are
 * real content. Personalization applies when the email is sent.
 */
export function bannerPreviewUrl(
  banner: Pick<EmailBanner, "canvas_id">,
): string {
  return `${window.ogdynamicAdmin.cdnUrl}c/${banner.canvas_id}`;
}
