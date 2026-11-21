<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch, onMounted } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import type {
  ApiData,
  CursorPage,
  EmailBanner,
  EmailBannerSource,
  EmailBannersPayload,
  OGDCanvas,
  OGDCanvasSummary,
} from "../types";
import { useOgdApi } from "../composables/useOgdApi";
import { useOgdCloudApi } from "../composables/useOgdCloudApi";
import { bannerPreviewUrl } from "../bannerPreview";
import FormField from "../components/forms/FormField.vue";
import TextInput from "../components/forms/TextInput.vue";
import SelectInput from "../components/forms/SelectInput.vue";
import SearchableSelectInput from "../components/forms/SearchableSelectInput.vue";

type FieldOption = {
  key: string;
  label: string;
  type?: string;
};

const route = useRoute();
const router = useRouter();

const wordpressApi = useOgdApi();
const saveApi = useOgdApi();
const deleteApi = useOgdApi();
const cloudApi = useOgdCloudApi();

const isNew = computed(() => route.params.id === "new");
const bannerId = computed(() => String(route.params.id || ""));

const isLoading = ref(true);
const bannerExists = ref(false);
const success = ref("");

const sources = shallowRef<EmailBannerSource[]>([]);

const canvases = shallowRef<OGDCanvasSummary[]>([]);
const canvas = shallowRef<OGDCanvas>();
const canvasListFailed = ref(false);

// Cursor pagination: the selector lists page one, then loads more as the
// dropdown list is scrolled to its end.
const nextCursor = ref<string | null>(null);
const loadingMoreCanvases = ref(false);
const hasMoreCanvases = computed(() => nextCursor.value !== null);
const canvasesLoading = ref(false);
const canvasLoading = ref(false);
const canvasError = ref("");

const linkModes = [
  { value: "recovery", label: "Cart recovery link" },
  { value: "none", label: "No link" },
  { value: "custom", label: "Custom URL…" },
];

const formPayload = reactive<{
  name: string;
  canvas_id: string;
  canvas_name: string;
  width: number;
  height: number;
  link_mode: string;
  link_url: string;
  display_width: number;
  map: Array<{ attr_key: string; key: string; value?: string }>;
}>({
  name: "",
  canvas_id: "",
  canvas_name: "",
  width: 0,
  height: 0,
  link_mode: "recovery",
  link_url: "",
  display_width: 600,
  map: [],
});

let canvasRequestSeq = 0;

const appUrl = computed(() =>
  window.ogdynamicAdmin.apiUrl.replace(/\/?api\/?$/, ""),
);
const createCanvasUrl = computed(() => `${appUrl.value}/canvases/create`);
const editCanvasUrl = computed(() =>
  formPayload.canvas_id
    ? `${appUrl.value}/canvases/${formPayload.canvas_id}/edit`
    : "",
);

const fields = computed<FieldOption[]>(() => {
  return (
    canvas.value?.override_fields.map((field) => ({
      key: field.field,
      label: field.name,
      type: field.type,
    })) || []
  );
});

const selectorOptions = computed(() => {
  const options = canvases.value.map((c) => ({
    label: c.name,
    value: c.id,
    description: `${c.width}×${c.height}`,
  }));

  // A saved banner can point at a canvas the list has not paged to yet —
  // fall back to the loaded canvas detail so the selector displays its
  // name. Once paging reaches the canvas, the list entry wins.
  const selected = canvas.value;
  if (selected && !options.some((option) => option.value === selected.id)) {
    options.unshift({
      label: selected.name,
      value: selected.id,
      description: `${selected.width}×${selected.height}`,
    });
  }

  return [{ label: "Select your canvas", value: "" }, ...options];
});

const sourceOptions = computed(() => [
  { value: "", label: "Do not override" },
  ...sources.value.map((source) => ({
    value: source.key,
    label: source.label,
    group: source.group,
    badge: source.type === "image" ? "Image" : undefined,
  })),
  { value: "custom", label: "Custom text…", group: "Custom" },
]);

const previewUrl = computed(() =>
  formPayload.canvas_id
    ? bannerPreviewUrl({ canvas_id: formPayload.canvas_id })
    : "",
);

// An image-type canvas field only renders when its value is a URL, so
// mapping it to a text source (or vice versa) is almost always a mistake.
const imageFieldMismatches = computed(() =>
  formPayload.map
    .map((entry) => {
      const field = fields.value.find((item) => item.key === entry.attr_key);
      if (!field) {
        return null;
      }

      const source = sources.value.find((item) => item.key === entry.key);
      if (!source) {
        return null;
      }

      const isImageField = field.type === "image";
      const isImageSource = source.type === "image";

      return isImageField !== isImageSource ? field.label : null;
    })
    .filter((label): label is string => label !== null),
);

const fieldMap = computed(() => {
  return (key: string) =>
    formPayload.map.find((map) => map.attr_key === key)?.key || "";
});

const fieldValue = computed(() => {
  return (key: string) =>
    formPayload.map.find((map) => map.attr_key === key)?.value || "";
});

watch(
  () => formPayload.canvas_id,
  async (canvasId) => {
    success.value = "";
    await loadSelectedCanvas(canvasId);
  },
);

async function loadBanner() {
  const payload = await wordpressApi.request<EmailBannersPayload>(
    "email-banners",
  );

  sources.value = payload.sources ?? [];

  if (isNew.value) {
    return;
  }

  const banner = (payload.data ?? []).find(
    (item) => item.id === bannerId.value,
  );

  if (!banner) {
    bannerExists.value = false;
    return;
  }

  bannerExists.value = true;
  formPayload.name = banner.name;
  formPayload.canvas_id = banner.canvas_id;
  formPayload.canvas_name = banner.canvas_name;
  formPayload.width = banner.width;
  formPayload.height = banner.height;
  formPayload.display_width = banner.display_width || 600;
  formPayload.map = (banner.map ?? []).map((entry) => ({ ...entry }));

  if (banner.link === "recovery" || banner.link === "none") {
    formPayload.link_mode = banner.link;
    formPayload.link_url = "";
  } else {
    formPayload.link_mode = "custom";
    formPayload.link_url = banner.link;
  }
}

async function loadCanvases() {
  canvasListFailed.value = false;
  // Drop the stale cursor while refetching so a concurrent load-more
  // cannot append old pages to the fresh list.
  nextCursor.value = null;

  canvasesLoading.value = true;

  try {
    const payload = await cloudApi.request<CursorPage<OGDCanvasSummary>>(
      `v1/canvases?status=public`,
    );
    canvases.value = Array.isArray(payload.data) ? payload.data : [];
    nextCursor.value = readCursor(payload);
  } catch {
    canvasListFailed.value = true;
  } finally {
    canvasesLoading.value = false;
  }
}

/** Appends the next cursor page to the canvas list (infinite scroll). */
async function loadMoreCanvases() {
  if (loadingMoreCanvases.value || nextCursor.value === null) {
    return;
  }

  loadingMoreCanvases.value = true;

  try {
    const payload = await cloudApi.request<CursorPage<OGDCanvasSummary>>(
      `v1/canvases?status=public&cursor=${encodeURIComponent(nextCursor.value)}`,
    );
    const items = Array.isArray(payload.data) ? payload.data : [];
    const known = new Set(canvases.value.map((item) => item.id));

    canvases.value = [
      ...canvases.value,
      ...items.filter((item) => !known.has(item.id)),
    ];

    // An empty page means the cursor is unreliable — stop paging.
    nextCursor.value = items.length > 0 ? readCursor(payload) : null;
  } catch {
    // Keep the loaded list and cursor; scrolling retries the page.
  } finally {
    loadingMoreCanvases.value = false;
  }
}

function readCursor(payload: CursorPage<OGDCanvasSummary>): string | null {
  // Laravel puts the cursor at the top level, but API resources nest the
  // paginator inside `meta` — accept both shapes.
  const cursor = payload.next_cursor ?? payload.meta?.next_cursor ?? null;

  return typeof cursor === "string" && cursor !== "" ? cursor : null;
}

async function loadSelectedCanvas(canvasId: string) {
  const seq = ++canvasRequestSeq;
  canvasError.value = "";

  if (!canvasId) {
    canvas.value = undefined;
    formPayload.canvas_name = "";
    formPayload.width = 0;
    formPayload.height = 0;
    return;
  }

  canvasLoading.value = true;

  try {
    const { data } = await cloudApi.request<ApiData<OGDCanvas>>(
      `v1/canvases/${canvasId}`,
    );

    if (seq !== canvasRequestSeq) {
      return;
    }

    canvas.value = data;
    formPayload.canvas_name = data.name;
    formPayload.width = data.width;
    formPayload.height = data.height;
    syncFieldMap();
  } catch {
    // Keep the previously loaded canvas; surface the failure in the banner.
    canvasError.value = cloudApi.error.value || "Request failed.";
  } finally {
    canvasLoading.value = false;
  }
}

function setFieldMapValue(attrKey: string, key: string) {
  const map = formPayload.map.find((entry) => entry.attr_key === attrKey);

  if (map) {
    map.key = key;
    if (key !== "custom") {
      delete map.value;
    }
  } else {
    formPayload.map.push({ attr_key: attrKey, key });
  }
}

function setFieldValue(attrKey: string, value: string) {
  const map = formPayload.map.find((entry) => entry.attr_key === attrKey);

  if (map) {
    map.value = value;
  }
}

function syncFieldMap() {
  // Drop entries for fields the selected canvas no longer has; keep
  // custom values only for entries still set to custom.
  const fieldKeys = new Set(fields.value.map((field) => field.key));
  formPayload.map = formPayload.map.filter(
    (entry) => fieldKeys.has(entry.attr_key),
  );

  for (const field of fields.value) {
    if (formPayload.map.some((entry) => entry.attr_key === field.key)) continue;

    const source = findMatchingSource(field);
    if (source) {
      formPayload.map.push({ attr_key: field.key, key: source.key });
    }
  }
}

function findMatchingSource(
  field: FieldOption,
): EmailBannerSource | undefined {
  const normalizedField = normalizeMappingKey(field.key);
  const normalizedLabel = normalizeMappingKey(field.label);
  const fieldKeys = [normalizedField, normalizedLabel].filter(Boolean);

  return sources.value.find((source) => {
    const sourceKeys = [
      normalizeMappingKey(source.key),
      normalizeMappingKey(source.label),
    ];
    return fieldKeys.some(
      (fieldKey) =>
        sourceKeys.some(
          (sourceKey) =>
            sourceKey === fieldKey ||
            sourceKey.endsWith(fieldKey) ||
            fieldKey.endsWith(sourceKey),
        ),
    );
  });
}

function normalizeMappingKey(key: string): string {
  return key.replace(/url$/i, "").replace(/[^a-z0-9]/gi, "").toLowerCase();
}

function fieldInputId(key: string): string {
  return `ogd-banner-map-${key}`;
}

async function save() {
  success.value = "";

  const link =
    formPayload.link_mode === "custom"
      ? formPayload.link_url.trim()
      : formPayload.link_mode;

  const payload = {
    name: formPayload.name.trim(),
    canvas_id: formPayload.canvas_id,
    canvas_name: formPayload.canvas_name,
    width: formPayload.width,
    height: formPayload.height,
    link,
    display_width: Number(formPayload.display_width) || 600,
    map: formPayload.map.filter((entry) => entry.key !== ""),
  };

  try {
    const response = await saveApi.request<{ data: EmailBanner }>(
      isNew.value ? "email-banners" : `email-banners/${bannerId.value}`,
      {
        method: isNew.value ? "POST" : "PUT",
        body: payload,
      },
    );

    bannerExists.value = true;
    success.value = isNew.value ? "Banner created." : "Banner updated.";

    if (isNew.value && response.data?.id) {
      await router.replace(`/email-banners/${response.data.id}`);
    }
  } catch {
    // Error handled by API error state.
  }
}

const confirmingDelete = ref("");

async function remove() {
  if (confirmingDelete.value) {
    confirmingDelete.value = "";

    try {
      await deleteApi.request<{ data: [] }>(`email-banners/${bannerId.value}`, {
        method: "DELETE",
      });
      await router.push("/email-banners");
    } catch {
      // Error handled by API error state.
    }

    return;
  }

  confirmingDelete.value = "yes";
}

const canSave = computed(
  () =>
    formPayload.name.trim() !== "" &&
    formPayload.canvas_id !== "" &&
    !saveApi.loading.value,
);

onMounted(async () => {
  try {
    await Promise.all([loadCanvases(), loadBanner()]);
  } catch {
    // Errors handled individually.
  } finally {
    isLoading.value = false;
  }
});
</script>

<template>
  <section>
    <!-- Page heading and return navigation. -->
    <RouterLink
      class="ogd:mb-5 ogd:inline-flex ogd:text-sm! ogd:font-semibold! ogd:text-primary! ogd:hover:text-primary-hover!"
      to="/email-banners"
    >
      Back to email banners
    </RouterLink>

    <div
      class="ogd:flex ogd:flex-wrap ogd:items-end ogd:justify-between ogd:gap-4"
    >
      <h1
        class="ogd:m-0 ogd:font-display ogd:text-3xl ogd:font-bold ogd:tracking-[-0.03em] ogd:text-gray-900"
      >
        {{ isNew ? "Create Email Banner" : "Edit Email Banner" }}
      </h1>
    </div>
    <p
      class="ogd:mt-2 ogd:mb-6 ogd:max-w-140 ogd:text-[15px] ogd:leading-relaxed ogd:text-gray-500"
    >
      Map an ogdynamic canvas to cart and customer data. The banner
      personalizes itself for every recipient when the email is sent.
    </p>

    <!-- Editor skeleton while the banner and canvas list load. -->
    <div
      v-if="isLoading"
      class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
    >
      <div class="ogd:animate-pulse">
        <div class="ogd:h-4 ogd:w-28 ogd:rounded-md ogd:bg-gray-100"></div>
        <div class="ogd:mt-2 ogd:h-11 ogd:w-full ogd:rounded-xl ogd:bg-gray-100"></div>
        <div class="ogd:mt-5 ogd:h-4 ogd:w-20 ogd:rounded-md ogd:bg-gray-100"></div>
        <div class="ogd:mt-2 ogd:h-11 ogd:w-full ogd:rounded-xl ogd:bg-gray-100"></div>
      </div>
    </div>

    <div
      v-else
      class="ogd:grid ogd:grid-cols-[minmax(0,1.1fr)_minmax(420px,1.6fr)] ogd:gap-6 max-[1024px]:ogd:grid-cols-1"
    >
      <!-- Banner form. -->
      <div class="ogd:grid ogd:gap-4 ogd:self-start">
        <div
          class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
        >
          <FormField label="Banner name" for-id="ogd-banner-name">
            <TextInput
              id="ogd-banner-name"
              v-model="formPayload.name"
              placeholder="Cart recovery hero"
            />
          </FormField>

          <FormField label="Canvas" for-id="ogd-banner-canvas">
            <SearchableSelectInput
              id="ogd-banner-canvas"
              v-model="formPayload.canvas_id"
              :options="selectorOptions"
              :disabled="canvasesLoading || canvasLoading"
              :has-more="hasMoreCanvases"
              :loading-more="loadingMoreCanvases"
              placeholder="Search canvases"
              @load-more="loadMoreCanvases"
            />
          </FormField>

          <div
            v-if="canvasError && !canvasListFailed"
            class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-destructive/25 ogd:bg-destructive/5 ogd:px-3.5 ogd:py-3 ogd:text-destructive"
          >
            {{ canvasError }}
          </div>
          <div
            v-if="canvasListFailed"
            class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-warning/25 ogd:bg-warning-soft ogd:px-3.5 ogd:py-3 ogd:text-[13px] ogd:text-warning-text"
          >
            Your canvases could not be loaded. If you connected this site
            before canvases were introduced,
            <RouterLink
              class="ogd:font-semibold ogd:text-warning-text ogd:underline"
              to="/connection"
            >
              reconnect your account
            </RouterLink>
            to refresh access.
          </div>
          <div
            v-if="canvas && canvas.status !== 'public'"
            class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-warning/25 ogd:bg-warning-soft ogd:px-3.5 ogd:py-3 ogd:text-[13px] ogd:text-warning-text"
          >
            “{{ canvas.name }}” is {{ canvas.status || "not public" }}. ogdynamic
            renders banner images only for public canvases — set it to public in
            the
            <a
              class="ogd:font-semibold ogd:text-warning-text ogd:underline"
              :href="editCanvasUrl || createCanvasUrl"
              target="_blank"
              rel="noreferrer"
            >
              canvas editor
            </a>
            or pick a different canvas.
          </div>
        </div>

        <!-- Field mapping form for the selected canvas. -->
        <div
          v-if="formPayload.canvas_id && canvasLoading"
          class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
        >
          <div class="ogd:animate-pulse">
            <div class="ogd:h-5 ogd:w-36 ogd:rounded-md ogd:bg-gray-100"></div>
            <div class="ogd:mt-3 ogd:h-4 ogd:w-full ogd:rounded-md ogd:bg-gray-100"></div>
            <div class="ogd:mt-2 ogd:h-4 ogd:w-4/5 ogd:rounded-md ogd:bg-gray-100"></div>
            <div class="ogd:mt-5 ogd:grid ogd:gap-4">
              <div v-for="item in 3" :key="item">
                <div class="ogd:h-4 ogd:w-28 ogd:rounded-md ogd:bg-gray-100"></div>
                <div class="ogd:mt-2 ogd:h-10 ogd:w-full ogd:rounded-xl ogd:bg-gray-100"></div>
              </div>
            </div>
          </div>
        </div>

        <div
          v-else-if="formPayload.canvas_id"
          class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
        >
          <h2
            class="ogd:m-0! ogd:font-display ogd:text-base ogd:font-bold ogd:text-gray-900"
          >
            Banner mapping
          </h2>
          <p
            v-if="fields.length"
            class="ogd:mt-2! ogd:mb-5 ogd:text-sm ogd:leading-relaxed ogd:text-gray-500"
          >
            Link each overridable field to recipient data. Unmapped fields
            keep the canvas default.
          </p>

          <div
            v-if="!fields.length"
            class="ogd:mt-4 ogd:mb-1 ogd:rounded-[14px] ogd:border ogd:border-gray-100 ogd:bg-gray-50 ogd:px-3.5 ogd:py-3 ogd:text-sm ogd:text-gray-500"
          >
            No mappable fields found for this canvas.
          </div>

          <div v-else class="ogd:grid ogd:gap-4">
            <div v-for="field in fields" :key="field.key">
              <FormField
                :label="field.label + (field.type === 'image' ? ' (image URL)' : '')"
                :for-id="fieldInputId(field.key)"
              >
                <SearchableSelectInput
                  :id="fieldInputId(field.key)"
                  :modelValue="fieldMap(field.key)"
                  :options="sourceOptions"
                  @update:model-value="
                    setFieldMapValue(field.key, $event as string)
                  "
                />
                <div v-if="fieldMap(field.key) === 'custom'" class="ogd:mt-2">
                  <TextInput
                    :modelValue="fieldValue(field.key)"
                    placeholder="Custom value for every recipient"
                    @update:model-value="
                      setFieldValue(field.key, String($event ?? ''))
                    "
                  />
                </div>
              </FormField>
            </div>
          </div>

          <div
            v-if="imageFieldMismatches.length"
            class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-warning/25 ogd:bg-warning-soft ogd:px-3.5 ogd:py-3 ogd:text-[13px] ogd:text-warning-text"
          >
            {{ imageFieldMismatches.join(", ") }}
            {{ imageFieldMismatches.length === 1 ? "needs an image" : "need images" }}
            — image fields expect a URL (e.g. First product image) while text
            fields expect plain text.
          </div>
        </div>

        <!-- Link and sizing settings. -->
        <div
          v-if="formPayload.canvas_id"
          class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
        >
          <h2
            class="ogd:m-0! ogd:font-display ogd:text-base ogd:font-bold ogd:text-gray-900"
          >
            Email settings
          </h2>
          <p class="ogd:mt-2! ogd:mb-5 ogd:text-sm ogd:text-gray-500">
            Where the banner click takes the recipient, and how wide it
            renders.
          </p>

          <FormField label="Banner link" for-id="ogd-banner-link">
            <SelectInput
              id="ogd-banner-link"
              v-model="formPayload.link_mode"
              :options="linkModes"
            />
          </FormField>

          <FormField
            v-if="formPayload.link_mode === 'custom'"
            label="Custom URL"
            for-id="ogd-banner-link-url"
            hint="The full URL the banner click opens."
          >
            <TextInput
              id="ogd-banner-link-url"
              v-model="formPayload.link_url"
              type="url"
              placeholder="https://example.com/offer"
            />
          </FormField>

          <FormField
            label="Rendered width (px)"
            for-id="ogd-banner-width"
            hint="Display width inside the email — 600px fits every client."
          >
            <TextInput
              id="ogd-banner-width"
              v-model="formPayload.display_width"
              type="number"
            />
          </FormField>
        </div>
      </div>

      <!-- Live preview and actions. -->
      <div class="ogd:grid ogd:gap-4 ogd:self-start">
        <div
          v-if="formPayload.canvas_id"
          class="ogd:overflow-hidden ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white"
        >
          <div class="ogd:border-b ogd:border-gray-100 ogd:p-4">
            <h2
              class="ogd:m-0! ogd:font-display ogd:text-base ogd:font-bold ogd:text-gray-900"
            >
              Preview
            </h2>
            <p class="ogd:mt-1! ogd:mb-0! ogd:text-sm ogd:text-gray-500">
              {{ formPayload.canvas_name || canvas?.name || "" }} — canvas
              defaults shown; recipient data applies when the email is sent
            </p>
          </div>
          <div class="ogd:bg-gray-50 ogd:p-3">
            <img
              v-if="previewUrl"
              :src="previewUrl"
              :alt="`${formPayload.name || 'Banner'} preview`"
              class="ogd:block ogd:w-full ogd:rounded-[14px] ogd:border! ogd:border-gray-100!"
            />
          </div>
        </div>

        <div
          class="ogd:rounded-[20px] ogd:border ogd:border-gray-100 ogd:bg-white ogd:p-6"
        >
          <div class="ogd:flex ogd:flex-wrap ogd:gap-2.5">
            <button
              class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:gap-2 ogd:rounded-full ogd:border ogd:border-transparent ogd:bg-primary ogd:px-4.5 ogd:py-2.5 ogd:text-[13px] ogd:font-semibold ogd:text-white! ogd:transition ogd:hover:border-primary-hover ogd:hover:bg-primary-hover ogd:disabled:cursor-not-allowed ogd:disabled:opacity-50"
              type="button"
              :disabled="!canSave"
              @click="save"
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
                <path
                  d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"
                />
                <polyline points="17 21 17 13 7 13 7 21" />
                <polyline points="7 3 7 8 15 8" />
              </svg>
              {{ saveApi.loading.value ? "Saving…" : isNew ? "Create Banner" : "Update Banner" }}
            </button>
            <button
              v-if="bannerExists"
              class="ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:gap-2 ogd:rounded-full ogd:border ogd:px-4.5 ogd:py-2.5 ogd:text-[13px] ogd:font-semibold ogd:transition ogd:disabled:cursor-not-allowed ogd:disabled:opacity-50"
              :class="
                confirmingDelete
                  ? 'ogd:border-destructive ogd:bg-destructive ogd:text-white!'
                  : 'ogd:border-destructive/40 ogd:bg-white ogd:text-destructive! ogd:hover:border-destructive ogd:hover:bg-destructive ogd:hover:text-white!'
              "
              type="button"
              :disabled="deleteApi.loading.value"
              @click="remove"
            >
              {{
                deleteApi.loading.value
                  ? "Deleting…"
                  : confirmingDelete
                    ? "Confirm delete"
                    : "Delete Banner"
              }}
            </button>
          </div>

          <div
            v-if="saveApi.error.value"
            class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-destructive/25 ogd:bg-destructive/5 ogd:px-3.5 ogd:py-3 ogd:text-destructive"
          >
            {{ saveApi.error.value }}
          </div>
          <div
            v-if="deleteApi.error.value"
            class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-destructive/25 ogd:bg-destructive/5 ogd:px-3.5 ogd:py-3 ogd:text-destructive"
          >
            {{ deleteApi.error.value }}
          </div>
          <div
            v-if="success"
            class="ogd:mt-4 ogd:rounded-[14px] ogd:border ogd:border-success/25 ogd:bg-success-soft ogd:px-3.5 ogd:py-3 ogd:text-success-text"
          >
            {{ success }}
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
