<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from "vue";
import { RouterLink, useRouter } from "vue-router";
import type {
  EmailBanner,
  EmailBannersPayload,
  IntegrationStatus,
} from "../types";
import { useOgdApi } from "../composables/useOgdApi";
import CopyButton from "../components/CopyButton.vue";

const router = useRouter();
const api = useOgdApi();
const deleteApi = useOgdApi();

const isLoading = ref(true);
const banners = shallowRef<EmailBanner[]>([]);
const integrations = shallowRef<IntegrationStatus[]>([]);

const confirmingDelete = ref("");

const funnelKit = computed<IntegrationStatus | undefined>(() =>
  integrations.value.find((item) => item.slug === "funnelkit"),
);

const cartBounty = computed<IntegrationStatus | undefined>(() =>
  integrations.value.find((item) => item.slug === "cartbounty"),
);

const integrationApi = useOgdApi();

const cartBountyBannerId = ref("");
const integrationSaved = ref("");

const cartBountyBannerOptions = computed(() => [
  { value: "", label: "No banner" },
  ...banners.value.map((banner) => ({
    value: banner.id,
    label: banner.name,
  })),
]);

async function saveCartBountyBanner() {
  integrationSaved.value = "";

  try {
    await integrationApi.request<{ data: Record<string, string> }>(
      `email-banners/integrations/cartbounty`,
      {
        method: "PUT",
        body: { banner_id: cartBountyBannerId.value },
      },
    );
    integrationSaved.value = cartBountyBannerId.value
      ? "Banner will be inserted into CartBounty recovery emails."
      : "Banner removed from CartBounty emails.";
  } catch {
    // Error handled by API error state.
  }
}

const funnelKitSteps = [
  "Open FunnelKit Automations and edit your abandoned cart automation email (or create one from the Cart Abandoned trigger).",
  "Click inside any text block, then open the Merge tags picker above the editor.",
  "Pick the ogdynamic group and select your banner by name — the tag is inserted for you.",
  "Send a test email to check the placement, then activate the automation.",
];

function mergeTagCode(banner: EmailBanner): string {
  return `{{ogd_banner_${banner.id}}}`;
}

function linkLabel(banner: EmailBanner): string {
  if (banner.link === "recovery") {
    return "Cart recovery link";
  }

  return banner.link === "none" ? "No link" : "Custom link";
}

async function load() {
  isLoading.value = true;

  try {
    const payload = await api.request<EmailBannersPayload>("email-banners");
    banners.value = payload.data ?? [];
    integrations.value = payload.integrations ?? [];
    cartBountyBannerId.value =
      payload.integration_banners?.cartbounty ?? "";
  } catch {
    // Error handled by API error state.
  } finally {
    isLoading.value = false;
  }
}

async function remove(banner: EmailBanner) {
  if (confirmingDelete.value !== banner.id) {
    confirmingDelete.value = banner.id;
    return;
  }

  confirmingDelete.value = "";

  try {
    await deleteApi.request<{ data: [] }>(`email-banners/${banner.id}`, {
      method: "DELETE",
    });
    banners.value = banners.value.filter((item) => item.id !== banner.id);
  } catch {
    // Error handled by API error state.
  }
}

onMounted(() => {
  void load();
});
</script>

<template>
  <section>
    <div
      class="ogd:flex ogd:flex-wrap ogd:items-end ogd:justify-between ogd:gap-4"
    >
      <div>
        <h1
          class="ogd:m-0 ogd:font-display ogd:text-3xl ogd:font-bold ogd:tracking-[-0.03em] ogd:text-gray-900"
        >
          Email Banners
        </h1>
        <p
          class="ogd:mt-2 ogd:mb-0 ogd:max-w-140 ogd:text-[15px] ogd:leading-relaxed ogd:text-gray-500"
        >
          Personalized banner images for your marketing emails — every
          recipient sees their own cart, name and products.
        </p>
      </div>
      <RouterLink
        class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:gap-2 ogd:rounded-full ogd:border ogd:border-transparent ogd:bg-primary ogd:px-4.5 ogd:py-2.5 ogd:text-[13px] ogd:font-semibold ogd:text-white! ogd:no-underline ogd:transition ogd:hover:border-primary-hover ogd:hover:bg-primary-hover"
        to="/email-banners/new"
      >
        <svg
          class="ogd:size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M5 12h14" />
          <path d="M12 5v14" />
        </svg>
        Create Banner
      </RouterLink>
    </div>

    <!-- List skeleton while the banners load. -->
    <div v-if="isLoading" class="ogd:mt-7 ogd:grid ogd:gap-4">
      <div
        v-for="item in 2"
        :key="item"
        class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-5"
      >
        <div class="ogd:animate-pulse">
          <div class="ogd:h-4 ogd:w-36 ogd:rounded-md ogd:bg-gray-100"></div>
          <div class="ogd:mt-2 ogd:h-3 ogd:w-48 ogd:rounded-md ogd:bg-gray-100"></div>
        </div>
      </div>
    </div>

    <template v-else>
      <!-- FunnelKit integration status card. -->
      <article
        v-if="funnelKit"
        class="ogd:mt-7 ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
      >
        <div class="ogd:flex ogd:flex-wrap ogd:items-start ogd:justify-between ogd:gap-4">
          <div class="ogd:flex ogd:items-start ogd:gap-4">
            <div
              class="ogd:flex ogd:size-12 ogd:shrink-0 ogd:items-center ogd:justify-center ogd:rounded-[14px]"
              :class="
                funnelKit.available
                  ? 'ogd:bg-primary-soft ogd:text-primary'
                  : 'ogd:bg-gray-100 ogd:text-gray-400'
              "
            >
              <svg
                class="ogd:size-6"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <path
                  d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"
                />
                <line x1="4" y1="22" x2="4" y2="15" />
              </svg>
            </div>
            <div>
              <div class="ogd:flex ogd:items-center ogd:gap-2.5">
                <h2
                  class="ogd:m-0 ogd:font-display ogd:text-base ogd:font-bold ogd:text-gray-900"
                >
                  {{ funnelKit.label }}
                </h2>
                <span
                  class="ogd:rounded-full ogd:px-2 ogd:py-px ogd:text-[10px] ogd:font-semibold ogd:tracking-wide ogd:uppercase"
                  :class="
                    funnelKit.available
                      ? 'ogd:bg-success-soft ogd:text-success-text'
                      : 'ogd:bg-gray-100 ogd:text-gray-500'
                  "
                >
                  {{
                    funnelKit.available ? "Connected" : "Not installed"
                  }}
                </span>
              </div>
              <p
                class="ogd:mt-1.5 ogd:mb-0 ogd:max-w-140 ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
              >
                {{ funnelKit.note }}
              </p>
            </div>
          </div>
          <a
            v-if="!funnelKit.available"
            class="ogd:inline-flex ogd:items-center ogd:rounded-full ogd:border ogd:border-gray-200 ogd:bg-white ogd:px-4 ogd:py-2 ogd:text-[13px] ogd:font-semibold ogd:text-gray-700 ogd:no-underline ogd:transition ogd:hover:border-primary ogd:hover:text-primary"
            href="https://wordpress.org/plugins/wp-marketing-automations/"
            target="_blank"
            rel="noreferrer"
          >
            Get FunnelKit Automations
          </a>
        </div>

        <div
          v-if="funnelKit.available && banners.length"
          class="ogd:mt-5 ogd:rounded-[14px] ogd:border ogd:border-gray-100 ogd:bg-gray-50 ogd:p-4"
        >
          <p
            class="ogd:m-0 ogd:text-[12.5px] ogd:font-semibold ogd:tracking-wide ogd:text-gray-400 ogd:uppercase"
          >
            Insert a banner in FunnelKit
          </p>
          <ol
            class="ogd:mt-2 ogd:mb-0 ogd:list-decimal ogd:pl-5 ogd:text-sm ogd:leading-relaxed ogd:text-gray-600"
          >
            <li v-for="step in funnelKitSteps" :key="step">{{ step }}</li>
          </ol>
          <p
            class="ogd:mt-3 ogd:mb-0 ogd:border-t ogd:border-gray-100 ogd:pt-3 ogd:text-[12.5px] ogd:leading-relaxed ogd:text-gray-500"
          >
            Every real send renders the banner personalized with the
            recipient's own name, products and cart total — and clicking it
            restores their cart. Test emails and builder previews show the
            canvas defaults.
          </p>
        </div>
      </article>

      <!-- CartBounty integration card: one default banner, no merge tags. -->
      <article
        v-if="cartBounty"
        class="ogd:mt-4 ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
      >
        <div class="ogd:flex ogd:items-start ogd:gap-4">
          <div
            class="ogd:flex ogd:size-12 ogd:shrink-0 ogd:items-center ogd:justify-center ogd:rounded-[14px]"
            :class="
              cartBounty.available
                ? 'ogd:bg-primary-soft ogd:text-primary'
                : 'ogd:bg-gray-100 ogd:text-gray-400'
            "
          >
            <svg
              class="ogd:size-6"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <circle cx="8" cy="21" r="1" />
              <circle cx="19" cy="21" r="1" />
              <path
                d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"
              />
            </svg>
          </div>
          <div class="ogd:flex-1">
            <div class="ogd:flex ogd:items-center ogd:gap-2.5">
              <h2
                class="ogd:m-0 ogd:font-display ogd:text-base ogd:font-bold ogd:text-gray-900"
              >
                {{ cartBounty.label }}
              </h2>
              <span
                class="ogd:rounded-full ogd:px-2 ogd:py-px ogd:text-[10px] ogd:font-semibold ogd:tracking-wide ogd:uppercase"
                :class="
                  cartBounty.available
                    ? 'ogd:bg-success-soft ogd:text-success-text'
                    : 'ogd:bg-gray-100 ogd:text-gray-500'
                "
              >
                {{ cartBounty.available ? "Connected" : "Not installed" }}
              </span>
            </div>
            <p
              class="ogd:mt-1.5 ogd:mb-0 ogd:max-w-140 ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
            >
              {{ cartBounty.note }}
            </p>

            <div
              v-if="cartBounty.available"
              class="ogd:mt-4 ogd:flex ogd:flex-wrap ogd:items-center ogd:gap-2.5"
            >
              <select
                v-model="cartBountyBannerId"
                class="ogd:w-56 ogd:appearance-none ogd:rounded-full! ogd:border ogd:border-gray-200! ogd:bg-gray-50 ogd:px-6! ogd:py-1.5 ogd:text-[13.5px]! ogd:text-gray-900! ogd:outline-none! ogd:transition ogd:focus:border-primary! ogd:focus:bg-white! ogd:focus:shadow-[0_0_0_3px_rgba(99,91,255,0.12)]"
                :disabled="banners.length === 0 || integrationApi.loading.value"
              >
                <option
                  v-for="option in cartBountyBannerOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </option>
              </select>
              <button
                class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:gap-2 ogd:rounded-full ogd:border ogd:border-transparent ogd:bg-primary ogd:px-4 ogd:py-2 ogd:text-[12.5px] ogd:font-semibold ogd:text-white! ogd:transition ogd:hover:border-primary-hover ogd:hover:bg-primary-hover ogd:disabled:cursor-not-allowed ogd:disabled:opacity-50"
                type="button"
                :disabled="banners.length === 0 || integrationApi.loading.value"
                @click="saveCartBountyBanner"
              >
                {{ integrationApi.loading.value ? "Saving…" : "Save" }}
              </button>
            </div>
            <p
              v-if="cartBounty.available && banners.length === 0"
              class="ogd:mt-3 ogd:mb-0 ogd:text-[12.5px] ogd:text-gray-400"
            >
              Create a banner above to insert it into CartBounty emails.
            </p>
            <div
              v-if="integrationApi.error.value"
              class="ogd:mt-3 ogd:rounded-[14px] ogd:border ogd:border-destructive/25 ogd:bg-destructive/5 ogd:px-3.5 ogd:py-3 ogd:text-destructive"
            >
              {{ integrationApi.error.value }}
            </div>
            <div
              v-if="integrationSaved"
              class="ogd:mt-3 ogd:rounded-[14px] ogd:border ogd:border-success/25 ogd:bg-success-soft ogd:px-3.5 ogd:py-3 ogd:text-success-text"
            >
              {{ integrationSaved }}
            </div>
          </div>
          <a
            v-if="!cartBounty.available"
            class="ogd:inline-flex ogd:items-center ogd:rounded-full ogd:border ogd:border-gray-200 ogd:bg-white ogd:px-4 ogd:py-2 ogd:text-[13px] ogd:font-semibold ogd:text-gray-700 ogd:no-underline ogd:transition ogd:hover:border-primary ogd:hover:text-primary"
            href="https://wordpress.org/plugins/woo-save-abandoned-carts/"
            target="_blank"
            rel="noreferrer"
          >
            Get CartBounty
          </a>
        </div>
      </article>

      <div
        v-if="api.error.value"
        class="ogd:mt-5 ogd:rounded-[14px] ogd:border ogd:border-destructive/25 ogd:bg-destructive/5 ogd:px-3.5 ogd:py-3 ogd:text-destructive"
      >
        {{ api.error.value }}
      </div>

      <!-- Empty state. -->
      <div
        v-if="!banners.length"
        class="ogd:mt-5 ogd:rounded-[20px] ogd:border ogd:border-dashed ogd:border-gray-200 ogd:bg-white ogd:px-6 ogd:py-12 ogd:text-center"
      >
        <div
          class="ogd:mx-auto ogd:flex ogd:size-12 ogd:items-center ogd:justify-center ogd:rounded-[14px] ogd:bg-primary-soft ogd:text-primary"
        >
          <svg
            class="ogd:size-6"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <rect width="18" height="14" x="3" y="5" rx="2" />
            <path d="m3 15 4.5-4.5a2 2 0 0 1 2.8 0L15 15" />
            <path d="m14 14 1.5-1.5a2 2 0 0 1 2.8 0L21 15" />
            <circle cx="16" cy="9" r="1" />
          </svg>
        </div>
        <h2
          class="ogd:mt-4 ogd:mb-1 ogd:font-display ogd:text-lg ogd:font-bold ogd:text-gray-900"
        >
          No email banners yet
        </h2>
        <p
          class="ogd:mb-5 ogd:mt-0 ogd:text-center! ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
        >
          Pick a canvas, map its fields to cart and customer data, and drop
          the banner into your abandoned cart emails with one merge tag.
        </p>
        <RouterLink
          class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:gap-2 ogd:rounded-full ogd:border ogd:border-transparent ogd:bg-primary ogd:px-4.5 ogd:py-2.5 ogd:text-[13px] ogd:font-semibold ogd:text-white! ogd:no-underline ogd:transition ogd:hover:border-primary-hover ogd:hover:bg-primary-hover"
          to="/email-banners/new"
        >
          Create your first banner
        </RouterLink>
      </div>

      <!-- Banner cards. -->
      <div v-else class="ogd:mt-5 ogd:grid ogd:gap-4">
        <article
          v-for="banner in banners"
          :key="banner.id"
          class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-5"
        >
          <div
            class="ogd:flex ogd:flex-wrap ogd:items-start ogd:justify-between ogd:gap-4"
          >
            <div class="ogd:min-w-0">
              <h2
                class="ogd:m-0 ogd:truncate ogd:font-display ogd:text-lg ogd:font-bold ogd:tracking-[-0.02em] ogd:text-gray-900"
              >
                {{ banner.name }}
              </h2>
              <p class="ogd:mt-1 ogd:mb-0 ogd:text-sm ogd:text-gray-500">
                {{ banner.canvas_name || "Canvas" }}
                <span v-if="banner.width && banner.height">
                  · {{ banner.width }}×{{ banner.height }}</span
                >
                · {{ linkLabel(banner) }}
              </p>
            </div>
            <div class="ogd:flex ogd:flex-wrap ogd:gap-2.5">
              <CopyButton :value="mergeTagCode(banner)" />
              <RouterLink
                class="ogd:inline-flex ogd:items-center ogd:rounded-full ogd:border ogd:border-gray-200 ogd:bg-white ogd:px-3.5 ogd:py-2 ogd:text-[12.5px] ogd:font-semibold ogd:text-gray-700 ogd:no-underline ogd:transition ogd:hover:border-primary ogd:hover:text-primary"
                :to="`/email-banners/${banner.id}`"
              >
                Edit
              </RouterLink>
              <button
                class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:rounded-full ogd:border ogd:px-3.5 ogd:py-2 ogd:text-[12.5px] ogd:font-semibold ogd:transition ogd:disabled:cursor-not-allowed ogd:disabled:opacity-50"
                :class="
                  confirmingDelete === banner.id
                    ? 'ogd:border-destructive ogd:bg-destructive ogd:text-white!'
                    : 'ogd:border-gray-200 ogd:bg-white ogd:text-gray-700 ogd:hover:border-destructive ogd:hover:text-destructive'
                "
                type="button"
                :disabled="deleteApi.loading.value"
                @click="remove(banner)"
              >
                {{
                  deleteApi.loading.value && confirmingDelete === banner.id
                    ? "Deleting…"
                    : confirmingDelete === banner.id
                      ? "Confirm delete"
                      : "Delete"
                }}
              </button>
            </div>
          </div>
        </article>
      </div>
    </template>
  </section>
</template>
