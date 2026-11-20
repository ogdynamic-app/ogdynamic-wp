import { createRouter, createWebHashHistory } from "vue-router";
import { adminNavigation } from "./navigation";

export const adminRoutes = [
  {
    ...adminNavigation[0],
    component: () => import("./views/DashboardView.vue"),
  },
  {
    ...adminNavigation[1],
    component: () => import("./views/TemplatesView.vue"),
  },
  {
    ...adminNavigation[2],
    component: () => import("./views/EmailBannersView.vue"),
  },
  {
    path: "/connection",
    name: "connection",
    label: "Connection",
    component: () => import("./views/ConnectionView.vue"),
  },
  {
    path: "/templates/:postType",
    name: "template-post-type",
    label: "Template Mapping",
    component: () => import("./views/TemplatePostTypeView.vue"),
  },
  {
    path: "/email-banners/:id",
    name: "email-banner-edit",
    label: "Email Banner",
    component: () => import("./views/EmailBannerEditView.vue"),
  },
] as const;

export const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    {
      path: "/onboarding",
      name: "onboarding",
      component: () => import("./views/OnboardingView.vue"),
    },
    ...adminRoutes,
    { path: "/:pathMatch(.*)*", redirect: "/" },
  ],
  scrollBehavior() {
    return { top: 0, behavior: 'smooth' }
  },
});
