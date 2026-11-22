<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

/**
 * Shared button. One place for the button styling so views stop repeating
 * long class strings — variants cover the app-wide looks: primary (solid),
 * secondary (bordered), danger (outline, fills on hover), danger-solid
 * (the destructive confirm state). `to` renders a RouterLink, `href` an
 * external link (target=_blank), and sizes cover pill actions (md/lg),
 * round icon buttons (icon), and full-width card CTAs (hero).
 */
const props = withDefaults(
  defineProps<{
    variant?: "primary" | "secondary" | "danger" | "danger-solid";
    size?: "md" | "lg" | "icon" | "hero";
    to?: string;
    href?: string;
    type?: "button" | "submit";
    disabled?: boolean;
  }>(),
  {
    variant: "primary",
    size: "md",
    to: undefined,
    href: undefined,
    type: "button",
    disabled: false,
  },
);

const classes = computed(() => [
  // Base.
  "ogd:inline-flex ogd:cursor-pointer ogd:items-center ogd:justify-center ogd:gap-2 ogd:border ogd:font-semibold ogd:no-underline ogd:transition ogd:disabled:cursor-not-allowed ogd:disabled:opacity-50",
  // Size (radius, padding, text).
  {
    md: "ogd:rounded-lg ogd:px-4 ogd:py-2 ogd:text-[12.5px]",
    lg: "ogd:rounded-lg ogd:px-4.5 ogd:py-2.5 ogd:text-[13px]",
    icon: "ogd:rounded-lg ogd:size-10 ogd:shrink-0",
    hero: "ogd:rounded-2xl ogd:w-full ogd:px-5 ogd:py-4 ogd:text-sm",
  }[props.size],
  // Variant.
  {
    primary:
      "ogd:border-transparent ogd:bg-primary ogd:text-white! ogd:hover:border-primary-hover ogd:hover:bg-primary-hover",
    secondary:
      "ogd:border-gray-200 ogd:bg-white ogd:text-gray-700 ogd:hover:border-primary ogd:hover:text-primary",
    danger:
      "ogd:border-destructive/40 ogd:bg-white ogd:text-destructive! ogd:hover:border-destructive ogd:hover:bg-destructive ogd:hover:text-white!",
    "danger-solid": "ogd:border-destructive ogd:bg-destructive ogd:text-white!",
  }[props.variant],
]);
</script>

<template>
  <RouterLink v-if="to" :to="to" :class="classes">
    <slot />
  </RouterLink>
  <a v-else-if="href" :href="href" target="_blank" rel="noreferrer" :class="classes">
    <slot />
  </a>
  <button v-else :type="type" :class="classes" :disabled="disabled">
    <slot />
  </button>
</template>
