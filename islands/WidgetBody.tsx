import { useEffect, useState } from "react";
import { Skeleton } from "@tracht-digital-solutions/tds-shared/components";
import { Sparkline } from "./charts";
import { loadReport, num, pct, type Loaded, type SeriesPoint, type Totals } from "./lib";

/**
 * Dashboard card: visits of the last seven days, the bounce rate and a
 * sparkline. A failed load shows "–", never a 0 — zero visitors is a real
 * answer this card must not give when it simply could not ask.
 */
export default function WidgetBody() {
  const [report, setReport] = useState<Loaded<{ totals: Totals; series: SeriesPoint[] }>>({ state: "loading" });

  useEffect(() => {
    let live = true;
    void loadReport<{ totals: Totals; series: SeriesPoint[] }>("summary", null).then((r) => {
      if (live) setReport(r);
    });
    return () => {
      live = false;
    };
  }, []);

  if (report.state === "loading") {
    return (
      <p className="tds-widget__metric" aria-busy="true">
        <Skeleton width="4ch" height="1.75rem" />
      </p>
    );
  }
  if (report.state === "error") {
    return (
      <p className="tds-widget__metric" title={report.message}>
        –
      </p>
    );
  }
  const t = report.data.totals;
  return (
    <div className="tds-stack tds-stack--tight">
      <p className="tds-widget__metric">{num(t.visits)}</p>
      <Sparkline
        values={report.data.series.map((s) => s.visits)}
        label={`Besuche der letzten sieben Tage: ${report.data.series.map((s) => s.visits).join(", ")}`}
      />
      <p className="marginalia">
        Absprungrate {pct(t.bounceRate)} · <a href="/statistik">Statistik öffnen</a>
      </p>
    </div>
  );
}
