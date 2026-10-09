/**
 * Hand-drawn SVG charts. No chart library: the platform has none, and the three
 * shapes the dashboard needs (a day series, a ranked bar list, a sparkline)
 * are a few dozen lines each. Colours come from theme tokens only, so light and
 * dark mode follow the host without a rule of their own.
 */

export interface Point {
  label: string;
  value: number;
}

const W = 600;

/** Day series as an area chart. `aria-label` carries the numbers a sighted reader gets from the shape. */
export function AreaChart({
  points,
  secondary,
  label,
  height = 160,
}: {
  points: Point[];
  secondary?: Point[];
  label: string;
  height?: number;
}) {
  if (points.length === 0) return null;
  const max = Math.max(1, ...points.map((p) => p.value), ...(secondary ?? []).map((p) => p.value));
  const step = points.length > 1 ? W / (points.length - 1) : W;
  const y = (v: number) => height - 4 - (v / max) * (height - 12);
  const line = (ps: Point[]) => ps.map((p, i) => `${i === 0 ? "M" : "L"}${(i * step).toFixed(1)},${y(p.value).toFixed(1)}`).join(" ");
  const area = `${line(points)} L${((points.length - 1) * step).toFixed(1)},${height} L0,${height} Z`;
  const first = points[0]!;
  const last = points[points.length - 1]!;

  return (
    <figure className="tds-stack tds-stack--tight">
      <svg
        viewBox={`0 0 ${W} ${height}`}
        preserveAspectRatio="none"
        width="100%"
        height={height}
        role="img"
        aria-label={label}
      >
        <title>{label}</title>
        {[0.25, 0.5, 0.75].map((f) => (
          <line
            key={f}
            x1="0"
            x2={W}
            y1={y(max * f)}
            y2={y(max * f)}
            stroke="var(--color-line)"
            strokeWidth="1"
            vectorEffect="non-scaling-stroke"
          />
        ))}
        <path d={area} fill="color-mix(in srgb, var(--color-primary) 14%, transparent)" />
        <path
          d={line(points)}
          fill="none"
          stroke="var(--color-primary)"
          strokeWidth="2"
          vectorEffect="non-scaling-stroke"
          strokeLinejoin="round"
        />
        {secondary ? (
          <path
            d={line(secondary)}
            fill="none"
            stroke="var(--color-info)"
            strokeWidth="1.5"
            strokeDasharray="4 4"
            vectorEffect="non-scaling-stroke"
          />
        ) : null}
      </svg>
      <figcaption className="tds-row tds-row--between marginalia">
        <span>{shortDay(first.label)}</span>
        <span>max. {max.toLocaleString("de-DE")}</span>
        <span>{shortDay(last.label)}</span>
      </figcaption>
    </figure>
  );
}

export function Sparkline({ values, label }: { values: number[]; label: string }) {
  if (values.length < 2) return null;
  const h = 36;
  const max = Math.max(1, ...values);
  const step = 120 / (values.length - 1);
  const d = values.map((v, i) => `${i === 0 ? "M" : "L"}${(i * step).toFixed(1)},${(h - 2 - (v / max) * (h - 6)).toFixed(1)}`).join(" ");
  return (
    <svg viewBox={`0 0 120 ${h}`} width="120" height={h} role="img" aria-label={label}>
      <title>{label}</title>
      <path d={d} fill="none" stroke="var(--tds-widget-hue, var(--color-primary))" strokeWidth="2" strokeLinejoin="round" />
    </svg>
  );
}

export interface BarRow {
  key: string;
  label: string;
  value: number;
  /** Shown right of the number, e.g. a share. */
  hint?: string;
}

/**
 * A ranked list with a proportional bar behind each row. A list, not a table:
 * it reads top-down on a phone without a horizontal scroller.
 */
export function BarList({ rows, empty, max }: { rows: BarRow[]; empty: string; max?: number }) {
  if (rows.length === 0) return <p className="tds-empty">{empty}</p>;
  const top = max ?? Math.max(1, ...rows.map((r) => r.value));
  return (
    <ul className="tds-list">
      {rows.map((r) => (
        <li key={r.key} className="tds-list__row">
          {/* A row is a wrapping flex line with centred items; the bar needs
              the full width, so the content sits in one full-width stack. */}
          <span className="tds-stack tds-stack--tight" style={{ flex: "1 1 100%", minWidth: 0 }}>
          <span className="tds-row tds-row--between">
            <span style={{ overflowWrap: "anywhere" }}>{r.label}</span>
            <span>
              <strong>{r.value.toLocaleString("de-DE")}</strong>
              {r.hint ? <span className="marginalia"> · {r.hint}</span> : null}
            </span>
          </span>
          <span
            aria-hidden="true"
            style={{
              display: "block",
              height: "0.375rem",
              borderRadius: "999px",
              background: "color-mix(in srgb, var(--color-primary) 12%, transparent)",
            }}
          >
            <span
              style={{
                display: "block",
                height: "100%",
                width: `${Math.max(2, Math.min(100, (r.value / top) * 100)).toFixed(1)}%`,
                borderRadius: "999px",
                background: "var(--color-primary)",
              }}
            />
          </span>
          </span>
        </li>
      ))}
    </ul>
  );
}

function shortDay(day: string): string {
  const [, m, d] = day.split("-");
  return d && m ? `${d}.${m}.` : day;
}
