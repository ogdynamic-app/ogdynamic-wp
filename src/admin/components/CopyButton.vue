<script setup lang="ts">
import { useClipboard } from "@vueuse/core";

withDefaults(
  defineProps<{
    /** Text shown on the button and copied to the clipboard. */
    value: string;
    /** Label shown right after a successful copy. */
    copiedLabel?: string;
  }>(),
  { copiedLabel: "Copied!" },
);

// `legacy` falls back to execCommand for non-secure contexts — wp-admin
// is routinely served over plain HTTP, where navigator.clipboard is
// unavailable. `copied` auto-resets after copiedDuring (1500ms).
const { copy, copied, isSupported } = useClipboard({ legacy: true });
</script>

<template>
  <button
    type="button"
    class="ogd:inline-flex ogd:max-w-full ogd:cursor-pointer ogd:items-center ogd:gap-2 ogd:rounded-full ogd:border ogd:border-gray-200 ogd:bg-white ogd:px-3.5 ogd:py-2 ogd:text-[12.5px] ogd:font-medium ogd:text-gray-700! ogd:transition ogd:hover:border-primary ogd:hover:text-primary ogd:disabled:cursor-not-allowed ogd:disabled:opacity-50"
    :disabled="!isSupported"
    :title="isSupported ? 'Copy to clipboard' : 'Clipboard unavailable'"
    @click="copy(value)"
  >
    <svg
      v-if="!copied"
      class="ogd:size-3.5 ogd:shrink-0"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <rect width="14" height="14" x="8" y="8" rx="2" />
      <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
    </svg>
    <svg
      v-else
      class="ogd:size-3.5 ogd:shrink-0 ogd:text-primary"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path d="m20 6-11 11-5-5" />
    </svg>
    <span class="ogd:truncate">{{ copied ? copiedLabel : value }}</span>
  </button>
</template>
