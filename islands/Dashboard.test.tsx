// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { primeRuntimeConfig, resetApiBase } from "@tracht-digital-solutions/tds-shared/api";
import Dashboard from "./Dashboard";
import WidgetBody from "./WidgetBody";

const totals = { visits: 12, visitors: 10, returning: 3, pageviews: 30, bounceRate: 0.25, avgDurationMs: 65000 };
const series = [
  { day: "2026-10-08", visits: 5, pageviews: 12 },
  { day: "2026-10-09", visits: 7, pageviews: 18 },
];

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

beforeEach(() => {
  primeRuntimeConfig(null);
  resetApiBase();
  document.head.innerHTML = '<meta name="tds-api-base" content="https://api.test">';
});

afterEach(() => cleanup());

describe("Dashboard", () => {
  it("loads the overview from the absolute API host and renders the KPIs", async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ totals, previous: { ...totals, visits: 6 }, series, retentionDays: 90 }));
    vi.stubGlobal("fetch", fetchMock);
    render(<Dashboard />);

    await screen.findByText("Besuche");
    const url = String(fetchMock.mock.calls[0]?.[0]);
    expect(url.startsWith("https://api.test/analytics/overview?")).toBe(true);
    expect(url).toContain("from=");
    expect(screen.getByText("+100 % zur Vorperiode")).toBeTruthy();
    expect(screen.getByRole("img", { name: /Besuche je Tag/ })).toBeTruthy();
  });

  it("shows a failed load in the flow, never as an empty statistic", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(json({ error: "Statistik ist nicht verfügbar (Datenbank)." }, 503)));
    render(<Dashboard />);
    expect((await screen.findByRole("alert")).textContent).toContain("nicht verfügbar");
    expect(screen.queryByText(/keine Besuche erfasst/)).toBeNull();
  });

  it("reloads with the chosen site", async () => {
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(json({ totals, previous: totals, series })));
    vi.stubGlobal("fetch", fetchMock);
    render(<Dashboard />);
    await screen.findByText("Besuche");
    fireEvent.change(screen.getByLabelText("Site"), { target: { value: "blog" } });
    await waitFor(() => expect(fetchMock.mock.calls.some((c) => String(c[0]).includes("site=blog"))).toBe(true));
  });

  it("renders the form funnel by field name", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockImplementation((url: string) =>
        Promise.resolve(
          String(url).includes("/forms")
            ? json({ forms: [{ form: "contact", started: 4, submitted: 1, abandoned: 3, conversion: 0.25, abandonedAt: [{ field: "message", count: 2 }, { field: "email", count: 1 }] }] })
            : json({ totals, previous: totals, series }),
        ),
      ),
    );
    render(<Dashboard />);
    fireEvent.click(screen.getByRole("tab", { name: "Formulare" }));
    expect(await screen.findByText("Kontaktformular")).toBeTruthy();
    expect(screen.getByText("message")).toBeTruthy();
  });
});

describe("WidgetBody", () => {
  it("shows the seven-day visits", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(json({ totals, series })));
    render(<WidgetBody />);
    expect(await screen.findByText("12")).toBeTruthy();
  });

  it("shows a dash, not a zero, when the API is down", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("offline")));
    render(<WidgetBody />);
    expect(await screen.findByText("–")).toBeTruthy();
  });
});
