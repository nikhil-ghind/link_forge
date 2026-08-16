import type { BreakdownRow } from '../api/types';
import { countryName, formatExact } from '../lib/format';

interface Props {
  rows: BreakdownRow[];
  /** Country codes get expanded to names; other dimensions are shown as-is. */
  labelAs?: 'country' | 'raw';
  limit?: number;
}

/**
 * Ranked share list rather than a pie: comparing arc angles is harder than
 * comparing bar lengths, and the labels stay readable at any count.
 *
 * The tail beyond `limit` is folded into a single "Other" row so the panel
 * never grows an unbounded number of near-zero rows.
 */
export function BreakdownBars({ rows, labelAs = 'raw', limit = 6 }: Props) {
  const head = rows.slice(0, limit);
  const tail = rows.slice(limit);
  const max = Math.max(1, ...rows.map((row) => row.clicks));

  const display = [...head];

  if (tail.length > 0) {
    display.push({
      label: `Other (${tail.length})`,
      clicks: tail.reduce((sum, row) => sum + row.clicks, 0),
      share: Number(tail.reduce((sum, row) => sum + row.share, 0).toFixed(1)),
    });
  }

  return (
    <div className="bars">
      {display.map((row) => {
        const label =
          labelAs === 'country' && row.label.length === 2 ? countryName(row.label) : row.label;

        return (
          <div className="bar-row" key={row.label}>
            <div className="bar-label" title={label}>
              {label}
            </div>
            <div className="bar-value">
              {formatExact(row.clicks)} <span className="card-note">{row.share}%</span>
            </div>
            <div className="bar-track">
              <div
                className="bar-fill"
                style={{ width: `${Math.max(2, (row.clicks / max) * 100)}%` }}
              />
            </div>
          </div>
        );
      })}
    </div>
  );
}
