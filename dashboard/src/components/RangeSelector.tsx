const RANGES = [
  { days: 1, label: '24h' },
  { days: 7, label: '7d' },
  { days: 30, label: '30d' },
  { days: 90, label: '90d' },
  { days: 365, label: '1y' },
];

interface Props {
  value: number;
  onChange: (days: number) => void;
}

export function RangeSelector({ value, onChange }: Props) {
  return (
    <div className="segmented" role="group" aria-label="Time range">
      {RANGES.map((range) => (
        <button
          key={range.days}
          type="button"
          aria-pressed={value === range.days}
          onClick={() => onChange(range.days)}
        >
          {range.label}
        </button>
      ))}
    </div>
  );
}
