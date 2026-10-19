import { useEffect, useState } from "react";
import { Alert, Spinner } from "@tracht-digital-solutions/tds-shared/components";
import { useRuntimeSettings } from "@tracht-digital-solutions/tds-shared/admin";
import { SITES } from "./lib";

/**
 * Besucher-Statistik settings, persisted to the core settings store
 * (`/admin/settings/analytics`). All keys are non-secret; the collector reads
 * the same store on every beacon, so a change applies without a deploy.
 */

const DEFAULTS: Record<string, string> = {
  ...Object.fromEntries(SITES.map((s) => [`site_${s.key}`, "1"])),
  retention_days: "90",
  excluded_paths: "",
  extra_hosts: "",
  geoip_enabled: "1",
};

export default function AnalyticsSettings() {
  const settings = useRuntimeSettings("analytics");
  // Form validation; the load failure comes from the hook.
  const [status, setStatus] = useState<string | null>(null);
  const [values, setValues] = useState<Record<string, string>>(DEFAULTS);

  useEffect(() => {
    const next = { ...DEFAULTS };
    for (const [key, s] of settings.settings) {
      if (key in next && typeof s.value === "string" && s.value !== "") next[key] = s.value;
    }
    setValues(next);
  }, [settings.settings]);

  const set = (key: string, value: string) => setValues((v) => ({ ...v, [key]: value }));
  const flag = (key: string) => values[key] === "1";

  const save = async () => {
    const days = Number(values.retention_days);
    if (!Number.isInteger(days) || days < 7 || days > 400) {
      setStatus("Aufbewahrung: bitte eine ganze Zahl zwischen 7 und 400 Tagen.");
      return;
    }
    setStatus(null);
    await settings.save(Object.keys(DEFAULTS).map((key) => ({ key, value: values[key] ?? "" })));
  };

  if (!settings.loaded) return <p><Spinner /></p>;

  return (
    <div className="tds-stack">
      <p className="marginalia">
        Gemessen wird nur, wer auf der jeweiligen Site der Kategorie „Statistik“ zugestimmt hat. Ohne Einwilligung
        sendet der Browser nichts.
      </p>
      <fieldset className="tds-stack tds-stack--tight">
        <legend>Messung je Site</legend>
        {SITES.map((s) => (
          <label key={s.key} className="tds-toggle-row">
            <span>{s.label}</span>
            <input
              type="checkbox"
              checked={flag(`site_${s.key}`)}
              onChange={() => set(`site_${s.key}`, flag(`site_${s.key}`) ? "0" : "1")}
            />
          </label>
        ))}
      </fieldset>
      <label className="tds-field-row">
        <span>Rohdaten aufbewahren (Tage)</span>
        <input
          className="field-boxed"
          type="number"
          min={7}
          max={400}
          value={values.retention_days ?? "90"}
          onChange={(e) => set("retention_days", e.target.value)}
        />
      </label>
      <p className="marginalia">Danach werden einzelne Besuche gelöscht; es bleiben anonyme Tagessummen.</p>
      <label className="tds-toggle-row">
        <span>Land aus der IP-Adresse ermitteln (lokale DB-IP-Datenbank, die Adresse wird nicht gespeichert)</span>
        <input type="checkbox" checked={flag("geoip_enabled")} onChange={() => set("geoip_enabled", flag("geoip_enabled") ? "0" : "1")} />
      </label>
      <label className="tds-field-row">
        <span>Ausgeschlossene Pfade (ein Präfix je Zeile)</span>
        <textarea
          className="field-boxed"
          rows={3}
          value={values.excluded_paths ?? ""}
          placeholder="/vorschau"
          onChange={(e) => set("excluded_paths", e.target.value)}
        />
      </label>
      <label className="tds-field-row">
        <span>Zusätzliche Hosts (host=site je Zeile, z. B. für eine lokale Vorschau)</span>
        <textarea
          className="field-boxed"
          rows={2}
          value={values.extra_hosts ?? ""}
          placeholder="localhost=landing"
          onChange={(e) => set("extra_hosts", e.target.value)}
        />
      </label>
      <Alert>{status ?? settings.error}</Alert>
      <div>
        <button className="btn btn-primary" type="button" onClick={save} disabled={settings.busy}>
          Speichern
        </button>
      </div>
    </div>
  );
}
