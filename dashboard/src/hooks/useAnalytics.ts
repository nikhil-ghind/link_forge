import { api } from '../api/endpoints';
import { useApiResource } from './useApiResource';
import type { BreakdownDimension } from '../api/types';

const LIVE_POLL_MS = 15_000;

export function useSummary(days: number) {
  return useApiResource((signal) => api.summary(days, signal), [days], { pollMs: LIVE_POLL_MS });
}

export function useTimeseries(days: number, granularity?: 'hour' | 'day') {
  return useApiResource(
    (signal) => api.timeseries(days, granularity, signal),
    [days, granularity],
    { pollMs: LIVE_POLL_MS },
  );
}

export function useTopLinks(days: number, limit = 8) {
  return useApiResource((signal) => api.topLinks(days, limit, signal), [days, limit], {
    pollMs: LIVE_POLL_MS,
  });
}

export function useBreakdown(dimension: BreakdownDimension, days: number, linkId?: number) {
  return useApiResource(
    (signal) => api.breakdown(dimension, days, linkId, signal),
    [dimension, days, linkId],
  );
}

export function useLinkStats(linkId: number, days: number) {
  return useApiResource((signal) => api.linkStats(linkId, days, signal), [linkId, days], {
    pollMs: LIVE_POLL_MS,
  });
}

export function useLinks(params: {
  page: number;
  q: string;
  sort: string;
  direction: 'asc' | 'desc';
}) {
  return useApiResource(
    (signal) => api.links({ ...params, per_page: 20 }, signal),
    [params.page, params.q, params.sort, params.direction],
  );
}
