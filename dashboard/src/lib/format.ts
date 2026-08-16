const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const full = new Intl.NumberFormat('en');

export function formatCount(value: number): string {
  return value >= 10_000 ? compact.format(value) : full.format(value);
}

export function formatExact(value: number): string {
  return full.format(value);
}

export function formatPercent(value: number | null): string {
  if (value === null) {
    return 'new';
  }

  const sign = value > 0 ? '+' : '';

  return `${sign}${value.toFixed(1)}%`;
}

/** Chart axis label: "Mar 4" for daily buckets, "14:00" for hourly ones. */
export function formatBucket(bucket: string): string {
  const hourly = bucket.includes(' ');
  const date = new Date(hourly ? bucket.replace(' ', 'T') : `${bucket}T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return bucket;
  }

  return hourly
    ? date.toLocaleTimeString('en', { hour: '2-digit', minute: '2-digit', hour12: false })
    : date.toLocaleDateString('en', { month: 'short', day: 'numeric' });
}

export function formatBucketLong(bucket: string): string {
  const hourly = bucket.includes(' ');
  const date = new Date(hourly ? bucket.replace(' ', 'T') : `${bucket}T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return bucket;
  }

  return date.toLocaleString('en', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    ...(hourly ? { hour: '2-digit', minute: '2-digit', hour12: false } : {}),
  });
}

export function formatRelative(iso: string | null): string {
  if (!iso) {
    return 'never';
  }

  const then = new Date(iso).getTime();

  if (Number.isNaN(then)) {
    return 'never';
  }

  const seconds = Math.round((Date.now() - then) / 1000);

  if (seconds < 60) return 'just now';
  if (seconds < 3_600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86_400) return `${Math.floor(seconds / 3_600)}h ago`;
  if (seconds < 2_592_000) return `${Math.floor(seconds / 86_400)}d ago`;

  return new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function truncate(value: string, length = 48): string {
  return value.length <= length ? value : `${value.slice(0, length - 1)}…`;
}

const REGION_NAMES = new Intl.DisplayNames(['en'], { type: 'region' });

export function countryName(code: string): string {
  if (code.length !== 2) {
    return code;
  }

  try {
    return REGION_NAMES.of(code.toUpperCase()) ?? code;
  } catch {
    return code;
  }
}
