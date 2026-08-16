import type { ReactNode } from 'react';

interface Props<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  isEmpty?: (data: T) => boolean;
  emptyMessage?: string;
  children: (data: T) => ReactNode;
}

/**
 * One place for the loading / error / empty states, so every panel on the
 * dashboard behaves the same way instead of each chart inventing its own.
 */
export function AsyncPanel<T>({
  data,
  loading,
  error,
  isEmpty,
  emptyMessage = 'No data in this range yet.',
  children,
}: Props<T>) {
  if (error) {
    return <div className="state state-error">{error}</div>;
  }

  // Keep showing the previous render while a poll is in flight: the chart
  // should update in place rather than blink back to a skeleton.
  if (loading && data === null) {
    return <div className="skeleton" />;
  }

  if (data === null) {
    return <div className="state">{emptyMessage}</div>;
  }

  if (isEmpty?.(data)) {
    return <div className="state">{emptyMessage}</div>;
  }

  return <>{children(data)}</>;
}
