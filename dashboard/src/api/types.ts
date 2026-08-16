export interface Link {
  id: number;
  slug: string;
  short_url: string;
  target_url: string;
  title: string | null;
  domain: string | null;
  redirect_status: number;
  is_active: boolean;
  is_custom_alias: boolean;
  is_redirectable: boolean;
  click_count: number;
  persisted_click_count: number;
  max_clicks: number | null;
  expires_at: string | null;
  last_clicked_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface Summary {
  range_days: number;
  from: string;
  to: string;
  clicks: number;
  previous_clicks: number;
  /** null means "no comparable previous period" rather than an infinite gain. */
  clicks_delta_pct: number | null;
  clicks_today: number;
  unique_visitors: number;
  total_links: number;
  active_links: number;
  links_created_in_range: number;
  avg_clicks_per_link: number;
  buffer_depth: number;
}

export interface SeriesPoint {
  bucket: string;
  clicks: number;
}

export interface TopLink {
  link_id: number;
  slug: string;
  title: string | null;
  short_url: string;
  clicks: number;
}

export interface BreakdownRow {
  label: string;
  clicks: number;
  share: number;
}

export interface LinkStats {
  link_id: number;
  slug: string;
  short_url: string;
  target_url: string;
  summary: Summary;
  timeseries: SeriesPoint[];
  referrers: BreakdownRow[];
  countries: BreakdownRow[];
  devices: BreakdownRow[];
  browsers: BreakdownRow[];
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export type BreakdownDimension = 'referrer' | 'country' | 'device' | 'browser' | 'os';

export interface CreateLinkPayload {
  target_url: string;
  alias?: string;
  title?: string;
  expires_at?: string | null;
  max_clicks?: number | null;
}
