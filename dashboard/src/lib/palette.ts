/**
 * Chart colours.
 *
 * The values are read from CSS custom properties rather than hard-coded here,
 * so light and dark steps are declared in exactly one place (styles.css) and
 * Recharts picks up whichever mode is active.
 *
 * Categorical slots are assigned in fixed order and never cycled: a chart that
 * would need a fifth series folds the tail into "Other" instead, so a filter
 * that removes a series never repaints the ones that remain.
 */
const cssVar = (name: string, fallback: string): string => {
  if (typeof window === 'undefined') {
    return fallback;
  }

  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  return value || fallback;
};

export const chart = {
  get series1() {
    return cssVar('--series-1', '#2a78d6');
  },
  get series2() {
    return cssVar('--series-2', '#eb6834');
  },
  get series3() {
    return cssVar('--series-3', '#1baf7a');
  },
  get grid() {
    return cssVar('--grid', '#e6e6e2');
  },
  get axis() {
    return cssVar('--text-muted', '#77766f');
  },
  get surface() {
    return cssVar('--surface-1', '#ffffff');
  },
};

/** Fixed-order categorical slots for the breakdown charts. */
export const CATEGORICAL = ['--series-1', '--series-2', '--series-3'] as const;

export const MAX_SERIES = CATEGORICAL.length;
