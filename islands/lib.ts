import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

/** The sites the beacon runs on, in the order the filter lists them. */
export const SITES: ReadonlyArray<{ key: string; label: string }> = [
  { key: "landing", label: "Landingpage" },
  { key: "blog", label: "Blog" },
  { key: "tools", label: "Tools" },
  { key: "shop", label: "Shop" },
  { key: "auth", label: "Login" },
];

export interface Filter {
  site: string;
  from: string;
  to: string;
}

export interface Totals {
  visits: number;
  visitors: number;
  returning: number;
  pageviews: number;
  bounceRate: number | null;
  avgDurationMs: number | null;
}

export interface SeriesPoint {
  day: string;
  visits: number;
  pageviews: number;
}

export interface KeyCount {
  key: string;
  count: number;
}

/** Calendar day in Europe/Berlin — the zone the API groups by. */
export function berlinDay(date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Berlin" }).format(date);
}

export function addDays(day: string, days: number): string {
  const d = new Date(`${day}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

export function defaultFilter(): Filter {
  const to = berlinDay();
  return { site: "", from: addDays(to, -29), to };
}

export function query(f: Filter): string {
  const q = new URLSearchParams({ from: f.from, to: f.to });
  if (f.site) q.set("site", f.site);
  return q.toString();
}

export type Loaded<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ok"; data: T };

/**
 * GET a report. A network failure and a non-OK status both become an error
 * the island shows in-flow — never an empty list, which would read as "no
 * visitors" while the API is simply down.
 */
export async function loadReport<T>(name: string, f: Filter | null): Promise<Loaded<T>> {
  const path = f ? `/analytics/${name}?${query(f)}` : `/analytics/${name}`;
  const res = await apiFetch(path).catch(() => null);
  if (res === null) return { state: "error", message: "Die API ist nicht erreichbar." };
  if (res.status === 401 || res.status === 403) {
    return { state: "error", message: "Keine Berechtigung für die Besucher-Statistik." };
  }
  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as { error?: string } | null;
    return { state: "error", message: body?.error ?? `Statistik konnte nicht geladen werden (HTTP ${res.status}).` };
  }
  try {
    return { state: "ok", data: (await res.json()) as T };
  } catch {
    return { state: "error", message: "Die Antwort der API war kein JSON." };
  }
}

const nf = new Intl.NumberFormat("de-DE");
export const num = (n: number | null | undefined): string => (n === null || n === undefined ? "–" : nf.format(n));

export const pct = (r: number | null | undefined, digits = 0): string =>
  r === null || r === undefined
    ? "–"
    : `${(r * 100).toLocaleString("de-DE", { maximumFractionDigits: digits, minimumFractionDigits: digits })} %`;

export function duration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) return "–";
  const s = Math.round(ms / 1000);
  if (s < 60) return `${s} s`;
  const m = Math.floor(s / 60);
  return `${m}:${String(s % 60).padStart(2, "0")} min`;
}

/** Relative change against the previous period, or null when there is no base. */
export function delta(now: number | null, prev: number | null): number | null {
  if (now === null || prev === null || prev === 0) return null;
  return (now - prev) / prev;
}

export const CHANNEL_LABELS: Record<string, string> = {
  direct: "Direkt",
  search: "Suchmaschine",
  social: "Soziale Netzwerke",
  campaign: "Kampagne",
  referral: "Verweis",
  internal: "Eigene Seiten",
};

export const DEVICE_LABELS: Record<string, string> = {
  mobile: "Smartphone",
  tablet: "Tablet",
  desktop: "Desktop",
};

export const BROWSER_LABELS: Record<string, string> = {
  chrome: "Chrome",
  safari: "Safari",
  firefox: "Firefox",
  edge: "Edge",
  opera: "Opera",
  samsung: "Samsung Internet",
  other: "Andere",
};

export const OS_LABELS: Record<string, string> = {
  windows: "Windows",
  macos: "macOS",
  ios: "iOS",
  android: "Android",
  linux: "Linux",
  chromeos: "ChromeOS",
  other: "Andere",
};

const regionNames = (() => {
  try {
    return new Intl.DisplayNames(["de"], { type: "region" });
  } catch {
    return null;
  }
})();

export function countryName(code: string): string {
  if (!code) return "Unbekannt";
  try {
    return regionNames?.of(code) ?? code;
  } catch {
    return code;
  }
}

export const label = (map: Record<string, string>, key: string): string => map[key] ?? (key === "" ? "Unbekannt" : key);
