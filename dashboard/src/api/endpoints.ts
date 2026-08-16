import { request } from './client';
import type {
  BreakdownDimension,
  BreakdownRow,
  CreateLinkPayload,
  Link,
  LinkStats,
  Paginated,
  SeriesPoint,
  Summary,
  TopLink,
} from './types';

interface Envelope<T> {
  data: T;
}

export const api = {
  summary: (days: number, signal?: AbortSignal) =>
    request<Envelope<Summary>>('/analytics/summary', { query: { days }, signal }).then((r) => r.data),

  timeseries: (days: number, granularity?: 'hour' | 'day', signal?: AbortSignal) =>
    request<Envelope<SeriesPoint[]>>('/analytics/timeseries', {
      query: { days, granularity },
      signal,
    }).then((r) => r.data),

  topLinks: (days: number, limit = 10, signal?: AbortSignal) =>
    request<Envelope<TopLink[]>>('/analytics/top-links', { query: { days, limit }, signal }).then(
      (r) => r.data,
    ),

  breakdown: (dimension: BreakdownDimension, days: number, linkId?: number, signal?: AbortSignal) =>
    request<Envelope<BreakdownRow[]>>(`/analytics/breakdown/${dimension}`, {
      query: { days, link_id: linkId },
      signal,
    }).then((r) => r.data),

  linkStats: (linkId: number, days: number, signal?: AbortSignal) =>
    request<Envelope<LinkStats>>(`/analytics/links/${linkId}`, { query: { days }, signal }).then(
      (r) => r.data,
    ),

  links: (
    params: { page?: number; q?: string; sort?: string; direction?: 'asc' | 'desc'; per_page?: number },
    signal?: AbortSignal,
  ) => request<Paginated<Link>>('/links', { query: params, signal }),

  link: (linkId: number, signal?: AbortSignal) =>
    request<Envelope<Link>>(`/links/${linkId}`, { signal }).then((r) => r.data),

  createLink: (payload: CreateLinkPayload) =>
    request<Envelope<Link>>('/links', { method: 'POST', body: payload }).then((r) => r.data),

  updateLink: (linkId: number, payload: Partial<CreateLinkPayload> & { is_active?: boolean }) =>
    request<Envelope<Link>>(`/links/${linkId}`, { method: 'PATCH', body: payload }).then((r) => r.data),

  deleteLink: (linkId: number) => request<void>(`/links/${linkId}`, { method: 'DELETE' }),
};
