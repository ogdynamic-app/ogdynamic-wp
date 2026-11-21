<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from "vue";
import { RouterLink } from "vue-router";
import type {
  CartBanner,
  CartBannersPayload,
  IntegrationMode,
  IntegrationStatus,
} from "../types";
import {
  INTEGRATION_SLUG_CARTBOUNTY,
  INTEGRATION_SLUG_FUNNELKIT,
} from "../types";
import { useOgdApi } from "../composables/useOgdApi";
import UseBannerDialog from "../components/UseBannerDialog.vue";
import AppButton from "../components/AppButton.vue";
// Emitted as real asset files (assetsInlineLimit: 0), not data URIs.
import funnelKitMark from "../assets/icons/funnelkit-mark.png";
import cartBountyMark from "../assets/icons/cartbounty-mark.svg";

const api = useOgdApi();
const deleteApi = useOgdApi();

const isLoading = ref(true);
const banners = shallowRef<CartBanner[]>([]);
const integrations = shallowRef<IntegrationStatus[]>([]);

const confirmingDelete = ref("");

const funnelKit = computed<IntegrationStatus | undefined>(() =>
  integrations.value.find(
    (item) => item.slug === INTEGRATION_SLUG_FUNNELKIT,
  ),
);

const cartBounty = computed<IntegrationStatus | undefined>(() =>
  integrations.value.find(
    (item) => item.slug === INTEGRATION_SLUG_CARTBOUNTY,
  ),
);

// Per-banner usage dialog ("Use in FunnelKit" / "Use in CartBounty").
const dialogOpen = ref(false);
const dialogMode = ref<IntegrationMode>(INTEGRATION_SLUG_FUNNELKIT);
const dialogBanner = ref<CartBanner | undefined>();

function openDialog(banner: CartBanner, mode: IntegrationMode) {
  dialogMode.value = mode;
  dialogBanner.value = banner;
  dialogOpen.value = true;
}

function dialogStatus(mode: IntegrationMode) {
  return mode === INTEGRATION_SLUG_FUNNELKIT
    ? funnelKit.value
    : cartBounty.value;
}

async function onDialogSaved() {
  await load();
}

function linkLabel(banner: CartBanner): string {
  if (banner.link === "recovery") {
    return "Cart recovery link";
  }

  return banner.link === "none" ? "No link" : "Custom link";
}

/** Integrations a banner is concretely wired into, shown as card badges. */
type BannerIntegration = {
  label: string;
  detail: string;
};

function bannerIntegrations(banner: CartBanner): BannerIntegration[] {
  const list: BannerIntegration[] = [];

  // Only the CartBounty assignment is per-banner and badge-worthy; FunnelKit
  // registers a merge tag for every banner automatically, so a FunnelKit
  // badge would carry no information. The badge only shows while CartBounty
  // is active — a deactivated plugin renders nothing, so a badge would
  // advertise an integration that is not in use (the saved assignment is
  // preserved and the badge returns when the plugin comes back).
  const assignment = banner.cartbounty;

  if (assignment && cartBounty.value?.available) {
    const email = cartBounty.value.emails?.find(
      (item) => item.id === assignment.email,
    );
    const placement = cartBounty.value.placements?.find(
      (item) => item.value === assignment.placement,
    );

    list.push({
      label: "CartBounty",
      detail: [email?.name, placement?.label.toLowerCase()]
        .filter(Boolean)
        .join(" · "),
    });
  }

  return list;
}

async function load() {
  isLoading.value = true;

  try {
    const payload = await api.request<CartBannersPayload>("cart-banners");
    banners.value = payload.data ?? [];
    integrations.value = payload.integrations ?? [];
  } catch {
    // Error handled by API error state.
  } finally {
    isLoading.value = false;
  }
}

async function remove(banner: CartBanner) {
  if (confirmingDelete.value !== banner.id) {
    confirmingDelete.value = banner.id;
    return;
  }

  confirmingDelete.value = "";

  try {
    await deleteApi.request<{ data: [] }>(`cart-banners/${banner.id}`, {
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
          Cart Recovery Banners
        </h1>
        <p
          class="ogd:mt-2 ogd:mb-0 ogd:max-w-140 ogd:text-[15px] ogd:leading-relaxed ogd:text-gray-500"
        >
          Personalized banner images for your abandoned cart emails — every
          recipient sees their own cart, name and products.
        </p>
      </div>
      <AppButton size="lg" to="/cart-banners/new">
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
      </AppButton>
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
          No cart banners yet
        </h2>
        <p
          class="ogd:mb-5 ogd:mt-0 ogd:text-center! ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
        >
          Pick a canvas, map its fields to cart and customer data, and drop
          the banner into your abandoned cart emails.
        </p>
        <AppButton size="lg" to="/cart-banners/new">
          Create your first banner
        </AppButton>
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
              <div class="ogd:flex ogd:flex-wrap ogd:items-center ogd:gap-2">
                <h2
                  class="ogd:!m-0 ogd:truncate ogd:font-display ogd:text-lg ogd:font-bold ogd:tracking-[-0.02em] ogd:text-gray-900"
                >
                  {{ banner.name }}
                </h2>
                <span
                  v-for="badge in bannerIntegrations(banner)"
                  :key="badge.label"
                  class="ogd:inline-flex ogd:items-center ogd:rounded-full ogd:bg-primary-soft ogd:px-2 ogd:py-px ogd:text-[10.5px] ogd:font-semibold ogd:text-primary"
                  title="Assigned to this CartBounty recovery email"
                >
                  {{ badge.label }}{{ badge.detail ? " · " + badge.detail : "" }}
                </span>
              </div>
              <p class="ogd:text-sm ogd:text-gray-500">
                {{ banner.canvas_name || "Canvas" }}
                <span v-if="banner.width && banner.height">
                  · {{ banner.width }}×{{ banner.height }}</span
                >
                · {{ linkLabel(banner) }}
              </p>
              <div class="ogd:flex ogd:gap-x-3">
                <AppButton
                  v-if="funnelKit?.available"
                  variant="secondary"
                  @click="openDialog(banner, INTEGRATION_SLUG_FUNNELKIT)"
                >
                  <img
                    :src="funnelKitMark"
                    alt=""
                    class="ogd:size-5"
                    aria-hidden="true"
                  />
                  Use in FunnelKit
                </AppButton>
                <AppButton
                  v-if="cartBounty?.available"
                  variant="secondary"
                  @click="openDialog(banner, INTEGRATION_SLUG_CARTBOUNTY)"
                >
                  <img
                    :src="cartBountyMark"
                    alt=""
                    class="ogd:size-5"
                    aria-hidden="true"
                  />
                  Use in CartBounty
                  <svg
                    v-if="banner.cartbounty"
                    class="ogd:size-3.5 ogd:text-success-text"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path d="M20 6 9 17l-5-5" />
                  </svg>
                </AppButton>
              </div>
            </div>
            <div class="ogd:flex ogd:flex-wrap ogd:gap-2.5">
              <AppButton variant="secondary" :to="`/cart-banners/${banner.id}`">
                Edit
              </AppButton>
              <AppButton
                :variant="
                  confirmingDelete === banner.id ? 'danger-solid' : 'danger'
                "
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
              </AppButton>
            </div>
          </div>
        </article>
      </div>
    </template>

    <!-- Per-banner usage dialog. -->
    <UseBannerDialog
      v-if="dialogBanner"
      v-model:open="dialogOpen"
      :banner="dialogBanner"
      :mode="dialogMode"
      :status="dialogStatus(dialogMode)"
      @saved="onDialogSaved"
    />
  </section>
</template>
