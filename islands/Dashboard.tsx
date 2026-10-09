import { useEffect, useState, type ReactNode } from "react";
import { Skeleton } from "@tracht-digital-solutions/tds-shared/components";
import { AreaChart, BarList, type BarRow } from "./charts";
import {
  BROWSER_LABELS,
  CHANNEL_LABELS,
  DEVICE_LABELS,
  OS_LABELS,
  SITES,
  addDays,
  berlinDay,
  countryName,
  defaultFilter,
  delta,
  duration,
  label,
  loadReport,
  num,
  pct,
  type Filter,
  type KeyCount,
  type Loaded,
  type SeriesPoint,
  type Totals,
} from "./lib";

type Tab = "overview" | "pages" | "sources" | "forms";

const TABS: Array<[Tab, string]> = [
  ["overview", "Übersicht"],
  ["pages", "Seiten & Absprung"],
  ["sources", "Herkunft & Klicks"],
  ["forms", "Formulare"],
];

interface Meta {
  retentionDays?: number;
  geoip?: boolean;
}

/** Load a report whenever the filter changes; a stale answer never overwrites a newer one. */
function useReport<T>(name: string, filter: Filter): Loaded<T & Meta> {
  const [state, setState] = useState<Loaded<T & Meta>>({ state: "loading" });
  useEffect(() => {
    let live = true;
    setState({ state: "loading" });
    void loadReport<T & Meta>(name, filter).then((r) => {
      if (live) setState(r);
    });
    return () => {
      live = false;
    };
  }, [name, filter.site, filter.from, filter.to]);
  return state;
}

export default function Dashboard() {
  const [filter, setFilter] = useState<Filter>(defaultFilter);
  const [tab, setTab] = useState<Tab>("overview");

  return (
    <div className="tds-stack tds-stack--loose">
      <FilterBar filter={filter} onChange={setFilter} />
      <nav className="tds-toolbar" role="tablist" aria-label="Ansicht">
        {TABS.map(([id, text]) => (
          <button
            key={id}
            type="button"
            role="tab"
            id={`analytics-tab-${id}`}
            aria-selected={tab === id}
            aria-controls="analytics-panel"
            className={tab === id ? "chip tds-tab chip-active" : "chip tds-tab"}
            onClick={() => setTab(id)}
          >
            {text}
          </button>
        ))}
      </nav>
      <div id="analytics-panel" role="tabpanel" aria-labelledby={`analytics-tab-${tab}`}>
        {tab === "overview" ? <OverviewTab filter={filter} /> : null}
        {tab === "pages" ? <PagesTab filter={filter} /> : null}
        {tab === "sources" ? <SourcesTab filter={filter} /> : null}
        {tab === "forms" ? <FormsTab filter={filter} /> : null}
      </div>
      <p className="marginalia">
        Erfasst werden nur Besucher, die der Kategorie „Statistik“ zugestimmt haben — die Zahlen sind deshalb
        kleiner als die tatsächliche Reichweite. Keine IP-Adressen, keine Formularinhalte, keine Drittanbieter.
      </p>
    </div>
  );
}

function FilterBar({ filter, onChange }: { filter: Filter; onChange: (f: Filter) => void }) {
  const today = berlinDay();
  const preset = (days: number) => onChange({ ...filter, from: addDays(today, -(days - 1)), to: today });
  return (
    <div className="tds-toolbar">
      <label className="tds-field-row">
        <span>Site</span>
        <select className="field-boxed" value={filter.site} onChange={(e) => onChange({ ...filter, site: e.target.value })}>
          <option value="">Alle Sites</option>
          {SITES.map((s) => (
            <option key={s.key} value={s.key}>
              {s.label}
            </option>
          ))}
        </select>
      </label>
      <label className="tds-field-row">
        <span>Von</span>
        <input
          className="field-boxed"
          type="date"
          value={filter.from}
          max={filter.to}
          onChange={(e) => e.target.value && onChange({ ...filter, from: e.target.value })}
        />
      </label>
      <label className="tds-field-row">
        <span>Bis</span>
        <input
          className="field-boxed"
          type="date"
          value={filter.to}
          min={filter.from}
          max={today}
          onChange={(e) => e.target.value && onChange({ ...filter, to: e.target.value })}
        />
      </label>
      <span className="tds-row" role="group" aria-label="Zeitraum">
        {[7, 30, 90].map((d) => (
          <button key={d} type="button" className="btn btn-ghost" onClick={() => preset(d)}>
            {d} Tage
          </button>
        ))}
      </span>
    </div>
  );
}

/** Loading, error and success rendered the same way in every tab. */
function Section<T>({ report, children }: { report: Loaded<T & Meta>; children: (data: T & Meta) => ReactNode }) {
  if (report.state === "loading") {
    return (
      <div className="tds-stack" aria-busy="true">
        <Skeleton height="6rem" />
        <Skeleton height="12rem" />
      </div>
    );
  }
  if (report.state === "error") {
    return (
      <p className="tds-alert tds-alert--danger" role="alert">
        {report.message}
      </p>
    );
  }
  return <>{children(report.data)}</>;
}

function Card({ title, children, note }: { title: string; children: ReactNode; note?: string }) {
  return (
    <section className="tds-card tds-stack p-4">
      <h2>{title}</h2>
      {note ? <p className="marginalia">{note}</p> : null}
      {children}
    </section>
  );
}

/* ------------------------------------------------------------------ */

interface Overview {
  totals: Totals;
  previous: Totals;
  series: SeriesPoint[];
}

function Kpi({ title, value, change, invert }: { title: string; value: string; change: number | null; invert?: boolean }) {
  // For the bounce rate a rise is the bad direction.
  const good = change === null ? null : invert ? change < 0 : change > 0;
  return (
    <div className="stat-tile tds-stack tds-stack--tight" style={{ padding: "1rem" }}>
      <span className="marginalia">{title}</span>
      <strong style={{ fontSize: "1.75rem", lineHeight: 1.1 }}>{value}</strong>
      <span className={good === null ? "marginalia" : good ? "chip chip--success" : "chip chip--warning"}>
        {change === null ? "kein Vergleich" : `${change > 0 ? "+" : ""}${pct(change)} zur Vorperiode`}
      </span>
    </div>
  );
}

function OverviewTab({ filter }: { filter: Filter }) {
  const report = useReport<Overview>("overview", filter);
  return (
    <Section report={report}>
      {(d) => {
        const t = d.totals;
        const p = d.previous;
        return (
          <div className="tds-stack tds-stack--loose">
            <div className="tds-grid-auto">
              <Kpi title="Besuche" value={num(t.visits)} change={delta(t.visits, p.visits)} />
              <Kpi title="Besucher" value={num(t.visitors)} change={delta(t.visitors, p.visitors)} />
              <Kpi title="Seitenaufrufe" value={num(t.pageviews)} change={delta(t.pageviews, p.pageviews)} />
              <Kpi title="Absprungrate" value={pct(t.bounceRate)} change={delta(t.bounceRate, p.bounceRate)} invert />
              <Kpi title="Ø Verweildauer" value={duration(t.avgDurationMs)} change={delta(t.avgDurationMs, p.avgDurationMs)} />
              <Kpi
                title="Wiederkehrend"
                value={t.visits > 0 ? pct(t.returning / t.visits) : "–"}
                change={delta(t.returning, p.returning)}
              />
            </div>
            <Card title="Verlauf" note="Linie: Besuche · gestrichelt: Seitenaufrufe">
              {t.visits === 0 && t.pageviews === 0 ? (
                <p className="tds-empty">Im gewählten Zeitraum wurden keine Besuche erfasst.</p>
              ) : (
                <AreaChart
                  label={`Besuche je Tag vom ${filter.from} bis ${filter.to}: insgesamt ${t.visits}`}
                  points={d.series.map((s) => ({ label: s.day, value: s.visits }))}
                  secondary={d.series.map((s) => ({ label: s.day, value: s.pageviews }))}
                />
              )}
            </Card>
            <RetentionNote days={d.retentionDays} />
          </div>
        );
      }}
    </Section>
  );
}

/* ------------------------------------------------------------------ */

interface PageRow {
  path: string;
  pageviews: number;
  entries: number;
  exits: number;
  exitRate: number | null;
  bounceRate: number | null;
}

interface ScrollPage {
  path: string;
  pageviews: number;
  reached: Record<"25" | "50" | "75" | "100", number>;
  sections: Array<{ id: string; count: number; share: number }>;
}

function PagesTab({ filter }: { filter: Filter }) {
  const pages = useReport<{ pages: PageRow[] }>("pages", filter);
  const scroll = useReport<{ pages: ScrollPage[] }>("scroll", filter);
  return (
    <div className="tds-stack tds-stack--loose">
      <Section report={pages}>
        {(d) => (
          <Card
            title="Seiten"
            note="Ausstiegsquote: Anteil der Aufrufe, nach denen der Besuch endete. Absprungrate: Besuche, die auf dieser Seite einstiegen und nach einer Seite endeten."
          >
            {d.pages.length === 0 ? (
              <p className="tds-empty">Keine Seitenaufrufe im Zeitraum.</p>
            ) : (
              <table className="tds-table" tabIndex={0} role="region" aria-label="Seiten">
                <thead>
                  <tr>
                    <th scope="col">Seite</th>
                    <th scope="col">Aufrufe</th>
                    <th scope="col">Einstiege</th>
                    <th scope="col">Ausstiege</th>
                    <th scope="col">Ausstiegsquote</th>
                    <th scope="col">Absprungrate</th>
                  </tr>
                </thead>
                <tbody>
                  {d.pages.map((p) => (
                    <tr key={p.path}>
                      <td style={{ overflowWrap: "anywhere" }}>{p.path}</td>
                      <td>{num(p.pageviews)}</td>
                      <td>{num(p.entries)}</td>
                      <td>{num(p.exits)}</td>
                      <td>{pct(p.exitRate)}</td>
                      <td>{pct(p.bounceRate)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
        )}
      </Section>
      <Section report={scroll}>
        {(d) => (
          <Card title="Scrolltiefe" note="Anteil der Aufrufe, die 25, 50, 75 und 100 % der Seite erreicht haben, und die erreichten Abschnitte.">
            {d.pages.length === 0 ? (
              <p className="tds-empty">Keine Daten im Zeitraum.</p>
            ) : (
              <ul className="tds-list">
                {d.pages.slice(0, 15).map((p) => (
                  <li key={p.path} className="tds-list__row">
                    <div className="tds-stack tds-stack--tight" style={{ flex: "1 1 100%", minWidth: 0 }}>
                    <strong style={{ overflowWrap: "anywhere" }}>{p.path}</strong>
                    <span className="tds-row">
                      {(["25", "50", "75", "100"] as const).map((m) => (
                        <span key={m} className="chip chip--neutral">
                          {m} %: {pct(p.reached[m])}
                        </span>
                      ))}
                    </span>
                    {p.sections.length > 0 ? (
                      <BarList
                        empty=""
                        max={p.pageviews}
                        rows={p.sections.map((s) => ({ key: s.id, label: `#${s.id}`, value: s.count, hint: pct(s.share) }))}
                      />
                    ) : null}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )}
      </Section>
    </div>
  );
}

/* ------------------------------------------------------------------ */

type Sources = Record<"channel" | "ref" | "source" | "medium" | "campaign" | "country" | "device" | "browser" | "os" | "lang", KeyCount[]>;

const rows = (list: KeyCount[], name: (k: string) => string = (k) => k || "Unbekannt"): BarRow[] => {
  const total = list.reduce((s, r) => s + r.count, 0);
  return list.map((r) => ({ key: r.key || "_", label: name(r.key), value: r.count, hint: total > 0 ? pct(r.count / total) : undefined }));
};

function SourcesTab({ filter }: { filter: Filter }) {
  const sources = useReport<Sources>("sources", filter);
  const clicks = useReport<{ cta: KeyCount[]; outbound: KeyCount[] }>("clicks", filter);
  return (
    <div className="tds-stack tds-stack--loose">
      <Section report={sources}>
        {(d) => (
          <div className="tds-grid-auto">
            <Card title="Kanäle">
              <BarList empty="Keine Besuche." rows={rows(d.channel, (k) => label(CHANNEL_LABELS, k))} />
            </Card>
            <Card title="Verweisende Seiten">
              <BarList empty="Keine Verweise." rows={rows(d.ref.filter((r) => r.key !== ""))} />
            </Card>
            <Card title="Kampagnen (utm_campaign)">
              <BarList empty="Keine Kampagnen." rows={rows(d.campaign.filter((r) => r.key !== ""))} />
            </Card>
            <Card title="Quellen (utm_source)">
              <BarList empty="Keine getaggten Links." rows={rows(d.source.filter((r) => r.key !== ""))} />
            </Card>
            <Card title="Länder" note={d.geoip ? "IP-Geolokalisierung: DB-IP.com (CC BY 4.0)" : "Länderdatenbank noch nicht geladen."}>
              <BarList empty="Keine Besuche." rows={rows(d.country, countryName)} />
            </Card>
            <Card title="Geräte">
              <BarList empty="Keine Besuche." rows={rows(d.device, (k) => label(DEVICE_LABELS, k))} />
            </Card>
            <Card title="Browser">
              <BarList empty="Keine Besuche." rows={rows(d.browser, (k) => label(BROWSER_LABELS, k))} />
            </Card>
            <Card title="Betriebssysteme">
              <BarList empty="Keine Besuche." rows={rows(d.os, (k) => label(OS_LABELS, k))} />
            </Card>
            <Card title="Sprache">
              <BarList empty="Keine Besuche." rows={rows(d.lang, (k) => (k === "en" ? "Englisch" : k === "de" ? "Deutsch" : k))} />
            </Card>
          </div>
        )}
      </Section>
      <Section report={clicks}>
        {(d) => (
          <div className="tds-grid-auto">
            <Card title="Klicks auf Schaltflächen" note="Elemente mit data-track-Namen.">
              <BarList empty="Keine Klicks erfasst." rows={rows(d.cta)} />
            </Card>
            <Card title="Ausgehende Links" note="Zieldomain von Links, die die Site verlassen.">
              <BarList empty="Keine ausgehenden Klicks." rows={rows(d.outbound)} />
            </Card>
          </div>
        )}
      </Section>
    </div>
  );
}

/* ------------------------------------------------------------------ */

interface FormRow {
  form: string;
  started: number;
  submitted: number;
  abandoned: number;
  conversion: number | null;
  abandonedAt: Array<{ field: string; count: number }>;
}

const FORM_LABELS: Record<string, string> = {
  contact: "Kontaktformular",
  newsletter: "Newsletter",
  login: "Anmeldung",
  checkout: "Kasse",
};

function FormsTab({ filter }: { filter: Filter }) {
  const forms = useReport<{ forms: FormRow[] }>("forms", filter);
  return (
    <Section report={forms}>
      {(d) =>
        d.forms.length === 0 ? (
          <p className="tds-empty">Im Zeitraum hat niemand ein markiertes Formular begonnen.</p>
        ) : (
          <div className="tds-grid-auto">
            {d.forms.map((f) => (
              <Card key={f.form} title={label(FORM_LABELS, f.form)}>
                <BarList
                  empty=""
                  max={f.started}
                  rows={[
                    { key: "started", label: "Begonnen", value: f.started },
                    { key: "submitted", label: "Abgeschickt", value: f.submitted, hint: pct(f.conversion) },
                    { key: "abandoned", label: "Abgebrochen", value: f.abandoned },
                  ]}
                />
                <h3>Abgebrochen nach Feld</h3>
                <p className="marginalia">Das zuletzt ausgefüllte Feld — nur sein Name, nie der Inhalt.</p>
                <BarList empty="Kein Abbruch." rows={f.abandonedAt.map((a) => ({ key: a.field, label: a.field, value: a.count }))} />
              </Card>
            ))}
          </div>
        )
      }
    </Section>
  );
}

function RetentionNote({ days }: { days?: number }) {
  if (!days) return null;
  return (
    <p className="marginalia">
      Einzelne Besuche werden {days} Tage gespeichert, danach bleiben nur anonyme Tagessummen. Besucher zählen in
      älteren Zeiträumen je Tag.
    </p>
  );
}
