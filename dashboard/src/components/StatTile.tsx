import { formatCount, formatPercent } from '../lib/format';

interface Props {
  label: string;
  value: number;
  /** Period-over-period change; null means there is no comparable period. */
  deltaPct?: number | null;
  note?: string;
  /** Higher is better for most metrics, but not for e.g. buffer depth. */
  invertDelta?: boolean;
}

export function StatTile({ label, value, deltaPct, note, invertDelta = false }: Props) {
  const hasDelta = deltaPct !== undefined;
  const positive = (deltaPct ?? 0) > 0 !== invertDelta;

  return (
    <div className="card">
      <div className="tile-label">{label}</div>
      <div className="tile-value">{formatCount(value)}</div>
      <div className="tile-delta">
        {hasDelta && deltaPct !== null && deltaPct !== 0 && (
          <span className={positive ? 'up' : 'down'}>{formatPercent(deltaPct)}</span>
        )}
        {hasDelta && deltaPct === null && <span>no prior period</span>}
        {hasDelta && deltaPct === 0 && <span>flat</span>}
        {note && <span>{hasDelta ? ' · ' : ''}{note}</span>}
      </div>
    </div>
  );
}
