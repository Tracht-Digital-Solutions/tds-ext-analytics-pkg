import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Besucher-Statistik — the dashboard for the public sites' own, consent-gated
 * audience measurement (beacon: `tds-shared/analytics`; backend:
 * `php/src/AnalyticsModule.php`).
 */
export default defineExtension({
  id: "analytics",
  name: "Besucher-Statistik",
  version: "0.1.0",
  permissions: [{ id: "analytics:read", label: "Besucher-Statistik ansehen", group: "analytics" }],
  nav: [
    {
      id: "analytics",
      label: "Statistik",
      href: "/statistik",
      icon: "chart-line",
      group: "verwaltung",
      order: 40,
      permission: "analytics:read",
    },
  ],
  widgets: [
    {
      id: "analytics-visits",
      title: "Besuche (7 Tage)",
      island: "@tracht-digital-solutions/tds-ext-analytics/widgets/Widget.astro",
      size: "md",
      permission: "analytics:read",
      dataEndpoint: "/analytics/summary",
      order: 15,
    },
  ],
  settings: [
    {
      id: "analytics",
      label: "Besucher-Statistik",
      island: "@tracht-digital-solutions/tds-ext-analytics/islands/Settings.astro",
      order: 60,
    },
  ],
  routes: [
    {
      pattern: "/statistik",
      entrypoint: "@tracht-digital-solutions/tds-ext-analytics/pages/Index.astro",
      permission: "analytics:read",
    },
  ],
  i18n: {
    de: { "analytics.title": "Besucher-Statistik" },
    en: { "analytics.title": "Visitor statistics" },
  },
});
