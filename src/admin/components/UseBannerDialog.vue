<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from "reka-ui";
import type {
  CartBountyEmail,
  CartBountyPlacement,
  CartBanner,
  IntegrationStatus,
} from "../types";
import { useOgdApi } from "../composables/useOgdApi";
import CopyButton from "./CopyButton.vue";

/**
 * Per-banner "how to use this banner" dialog. Two modes:
 * - `funnelkit`: banner-specific merge tag + insertion steps.
 * - `cartbounty`: email/placement assignment saved onto the banner.
 */
const props = defineProps<{
  banner: CartBanner;
  mode: "funnelkit" | "cartbounty";
  status?: IntegrationStatus;
}>();

const emit = defineEmits<{
  /** Fired after a CartBounty assignment was saved. */
  saved: [];
}>();

const open = defineModel<boolean>("open", { default: false });

const api = useOgdApi();

const mergeTag = computed(() => `{{ogd_cart_banner_${props.banner.id}}}`);

const emails = computed<CartBountyEmail[]>(
  () => props.status?.emails ?? [],
);

const placements = computed<CartBountyPlacement[]>(
  () => props.status?.placements ?? [],
);

const emailOptions = computed(() => [
  { value: "", label: "No email" },
  ...emails.value.map((email) => ({
    value: String(email.id),
    label: email.name + (email.enabled ? "" : " (disabled)"),
  })),
]);

const placementOptions = computed(() =>
  placements.value.map((placement) => ({
    value: placement.value,
    label: placement.label,
  })),
);

// A saved email that no longer exists in CartBounty falls back to "No
// email" — the next save clears the stale assignment.
const savedEmail = computed(() => {
  const email = props.banner.cartbounty?.email;
  return email !== undefined &&
    emails.value.some((item) => item.id === email)
    ? String(email)
    : "";
});

const savedPlacement = computed(
  () =>
    props.banner.cartbounty?.placement ??
    placements.value[0]?.value ??
    "after_intro",
);

const emailId = ref("");
const placement = ref("");

// Reset the form each time the dialog opens so it reflects the banner's
// current assignment. `immediate` covers mounting with open already true
// (the parent sets banner + open in one click, so this component mounts
// mid-open and a plain watch would never fire); watching the banner too
// covers the parent swapping banners while open.
watch(
  [open, () => props.banner],
  ([isOpen]) => {
    if (isOpen) {
      emailId.value = savedEmail.value;
      placement.value = savedPlacement.value;
    }
  },
  { immediate: true },
);

const emailName = computed(
  () =>
    emails.value.find((email) => String(email.id) === emailId.value)?.name ??
    "",
);

const placementName = computed(
  () =>
    placements.value.find((item) => item.value === placement.value)?.label ??
    "",
);

const assignmentChanged = computed(
  () =>
    emailId.value !== savedEmail.value || placement.value !== savedPlacement.value,
);

const assignmentNote = computed(() => {
  const email = emailId.value
    ? emailName.value || "the email"
    : "";
  const place = placementName.value
    ? placementName.value.toLowerCase()
    : "";

  if (!email) {
    return "No email selected — saving removes this banner from CartBounty emails.";
  }

  return `Every recipient of ${email} sees this banner${place ? ` ${place}` : ""}, personalized with their own name, products and cart total. Assigning it to an email that already has a banner replaces that banner.`;
});

async function saveAssignment() {
  const cartbounty = emailId.value
    ? { email: Number(emailId.value), placement: placement.value }
    : null;

  try {
    await api.request<{ data: CartBanner }>(
      `cart-banners/${props.banner.id}`,
      {
        method: "PUT",
        body: {
          name: props.banner.name,
          canvas_id: props.banner.canvas_id,
          canvas_name: props.banner.canvas_name,
          width: props.banner.width,
          height: props.banner.height,
          link: props.banner.link,
          display_width: props.banner.display_width,
          map: props.banner.map,
          cartbounty,
        },
      },
    );

    open.value = false;
    emit("saved");
  } catch {
    // Error handled by API error state.
  }
}
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay
        class="ogd:fixed ogd:inset-0 ogd:z-99990 ogd:bg-gray-900/40 backdrop-filter:ogd:backdrop-blur-[2px] ogd:data-[state=open]:animate-overlay-in ogd:data-[state=closed]:animate-overlay-out"
      />
      <DialogContent
        class="ogd:fixed ogd:top-1/2 ogd:left-1/2 ogd:z-99995 ogd:max-h-[85vh] ogd:w-full ogd:max-w-lg ogd:-translate-x-1/2 ogd:-translate-y-1/2 ogd:overflow-y-auto ogd:rounded-[20px] ogd:border! ogd:border-gray-100! ogd:bg-white ogd:p-6 ogd:shadow-xl ogd:data-[state=open]:animate-dialog-in ogd:data-[state=closed]:animate-dialog-out focus:ogd:outline-none"
      >
        <!-- FunnelKit mode: banner-specific merge tag + steps. -->
        <template v-if="mode === 'funnelkit'">
          <DialogTitle
            class="ogd:m-0 ogd:font-display ogd:text-lg ogd:font-bold ogd:text-gray-900"
          >
            Use in FunnelKit Automations
          </DialogTitle>
          <DialogDescription
            class="ogd:mt-1 ogd:mb-0 ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
          >
            Insert “{{ banner.name }}” into an abandoned cart email.
          </DialogDescription>

          <template v-if="status?.available">
            <p
              class="ogd:mt-4 ogd:mb-3 ogd:text-sm ogd:leading-relaxed ogd:text-gray-600"
            >
              Copy this code and place it wherever you want the banner in your
              email — it works anywhere in the body.
            </p>

            <div class="ogd:rounded-[14px] ogd:border ogd:border-gray-100 ogd:bg-gray-50 ogd:p-4">
              <CopyButton :value="mergeTag" />
            </div>

            <p
              class="ogd:mt-4 ogd:mb-0 ogd:rounded-[14px] ogd:border ogd:border-primary/20 ogd:bg-primary-soft ogd:px-3.5 ogd:py-3 ogd:text-[13px] ogd:leading-relaxed ogd:text-gray-600"
            >
              Every real send renders this banner personalized with the
              recipient's own name, products and cart total — and clicking it
              restores their cart. Test emails and builder previews show the
              canvas defaults.
            </p>
          </template>

          <template v-else>
            <p
              class="ogd:mt-4 ogd:mb-4 ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
            >
              Your merge tag is ready — install and activate
              <a
                class="ogd:font-semibold ogd:text-primary ogd:underline"
                href="https://wordpress.org/plugins/wp-marketing-automations/"
                target="_blank"
                rel="noreferrer"
                >FunnelKit Automations</a
              >
              to insert it into its abandoned cart emails. The tag also works
              as the WordPress shortcode
              <code
                class="ogd:rounded-md ogd:bg-gray-100 ogd:px-1.5 ogd:py-0.5 ogd:text-[12px] ogd:text-gray-800"
              >
                [ogdynamic_cart_banner id="{{ banner.id }}"]</code
              >
              anywhere shortcodes are processed.
            </p>
          </template>
        </template>

        <!-- CartBounty mode: email + placement assignment. -->
        <template v-else>
          <DialogTitle
            class="ogd:m-0 ogd:font-display ogd:text-lg ogd:font-bold ogd:text-gray-900"
          >
            Use in CartBounty
          </DialogTitle>
          <DialogDescription
            class="ogd:mt-1 ogd:mb-0 ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
          >
            Assign “{{ banner.name }}” to one recovery email and pick where it
            renders inside it.
          </DialogDescription>

          <template v-if="status?.available">
            <div class="ogd:mt-4 ogd:grid ogd:gap-4">
              <div>
                <label
                  class="ogd:mb-1.5 ogd:block ogd:text-[13px] ogd:font-semibold ogd:text-gray-700"
                  for="ogd-cartbounty-email"
                >
                  Which email
                </label>
                <select
                  id="ogd-cartbounty-email"
                  v-model="emailId"
                  class="ogd:w-full ogd:appearance-none ogd:rounded-full! ogd:border ogd:border-gray-200! ogd:bg-gray-50 ogd:px-5! ogd:py-2.5 ogd:text-[13.5px]! ogd:text-gray-900! ogd:outline-none! ogd:transition ogd:focus:border-primary! ogd:focus:bg-white! ogd:focus:shadow-[0_0_0_3px_rgba(99,91,255,0.12)]"
                >
                  <option
                    v-for="option in emailOptions"
                    :key="option.value"
                    :value="option.value"
                  >
                    {{ option.label }}
                  </option>
                </select>
              </div>

              <div>
                <label
                  class="ogd:mb-1.5 ogd:block ogd:text-[13px] ogd:font-semibold ogd:text-gray-700"
                  for="ogd-cartbounty-placement"
                >
                  Where in the email
                </label>
                <select
                  id="ogd-cartbounty-placement"
                  v-model="placement"
                  class="ogd:w-full ogd:appearance-none ogd:rounded-full! ogd:border ogd:border-gray-200! ogd:bg-gray-50 ogd:px-5! ogd:py-2.5 ogd:text-[13.5px]! ogd:text-gray-900! ogd:outline-none! ogd:transition ogd:focus:border-primary! ogd:focus:bg-white! ogd:focus:shadow-[0_0_0_3px_rgba(99,91,255,0.12)]"
                  :disabled="!emailId"
                >
                  <option
                    v-for="option in placementOptions"
                    :key="option.value"
                    :value="option.value"
                  >
                    {{ option.label }}
                  </option>
                </select>
              </div>
            </div>

            <p
              class="ogd:mt-4 ogd:mb-0 ogd:text-[13px] ogd:leading-relaxed ogd:text-gray-500"
            >
              {{ assignmentNote }}
            </p>

            <div
              v-if="api.error.value"
              class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-destructive/25 ogd:bg-destructive/5 ogd:px-3.5 ogd:py-3 ogd:text-destructive"
            >
              {{ api.error.value }}
            </div>

            <div class="ogd:mt-5 ogd:flex ogd:justify-end ogd:gap-2.5">
              <DialogClose
                class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:rounded-full ogd:border ogd:border-gray-200 ogd:bg-white ogd:px-4 ogd:py-2 ogd:text-[12.5px] ogd:font-semibold ogd:text-gray-700 ogd:transition ogd:hover:border-gray-300"
              >
                Cancel
              </DialogClose>
              <button
                class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:rounded-full ogd:border ogd:border-transparent ogd:bg-primary ogd:px-4 ogd:py-2 ogd:text-[12.5px] ogd:font-semibold ogd:text-white! ogd:transition ogd:hover:border-primary-hover ogd:hover:bg-primary-hover ogd:disabled:cursor-not-allowed ogd:disabled:opacity-50"
                type="button"
                :disabled="!assignmentChanged || api.loading.value"
                @click="saveAssignment"
              >
                {{ api.loading.value ? "Saving…" : "Save assignment" }}
              </button>
            </div>
          </template>

          <template v-else>
            <p
              class="ogd:mt-4 ogd:mb-4 ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
            >
              Install and activate
              <a
                class="ogd:font-semibold ogd:text-primary ogd:underline"
                href="https://wordpress.org/plugins/woo-save-abandoned-carts/"
                target="_blank"
                rel="noreferrer"
                >CartBounty</a
              >
              to assign this banner to its abandoned cart recovery emails.
            </p>
          </template>
        </template>

        <DialogClose
          class="ogd:absolute ogd:top-5 ogd:right-5 ogd:flex ogd:size-7 ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:rounded-full ogd:text-gray-400 ogd:transition hover:ogd:bg-gray-50 hover:ogd:text-gray-600 focus:ogd:outline-none"
          aria-label="Close"
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
            <path d="M18 6 6 18" />
            <path d="m6 6 12 12" />
          </svg>
        </DialogClose>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
