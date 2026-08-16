import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import type { SeriesPoint } from '../api/types';
import { formatBucket, formatBucketLong, formatCount, formatExact } from '../lib/format';
import { chart } from '../lib/palette';

interface Props {
  data: SeriesPoint[];
  height?: number;
}

interface TooltipPayload {
  active?: boolean;
  payload?: { payload: SeriesPoint }[];
}

function ChartTooltip({ active, payload }: TooltipPayload) {
  if (!active || !payload?.length) {
    return null;
  }

  const point = payload[0].payload;

  return (
    <div className="tooltip">
      <div className="tooltip-label">{formatBucketLong(point.bucket)}</div>
      <div className="tooltip-value">{formatExact(point.clicks)} clicks</div>
    </div>
  );
}

/**
 * Single-series area chart. One series means no legend is needed — the card
 * title names it — and the fill is there to give the line weight, not to encode
 * a second thing.
 */
export function ClickTimeSeries({ data, height = 260 }: Props) {
  // Thin out axis ticks on long ranges so labels never collide.
  const tickInterval = Math.max(0, Math.floor(data.length / 8) - 1);

  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -12 }}>
        <defs>
          <linearGradient id="clicksFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={chart.series1} stopOpacity={0.22} />
            <stop offset="100%" stopColor={chart.series1} stopOpacity={0.02} />
          </linearGradient>
        </defs>

        <CartesianGrid stroke={chart.grid} strokeDasharray="0" vertical={false} />

        <XAxis
          dataKey="bucket"
          tickFormatter={formatBucket}
          interval={tickInterval}
          tickLine={false}
          axisLine={false}
          tick={{ fill: chart.axis, fontSize: 12 }}
          minTickGap={12}
        />
        <YAxis
          tickFormatter={formatCount}
          tickLine={false}
          axisLine={false}
          width={52}
          tick={{ fill: chart.axis, fontSize: 12 }}
          allowDecimals={false}
        />

        <Tooltip
          content={<ChartTooltip />}
          cursor={{ stroke: chart.axis, strokeWidth: 1, strokeDasharray: '3 3' }}
        />

        <Area
          type="monotone"
          dataKey="clicks"
          stroke={chart.series1}
          strokeWidth={2}
          fill="url(#clicksFill)"
          activeDot={{ r: 4, strokeWidth: 2, stroke: chart.surface }}
          dot={false}
          isAnimationActive={false}
        />
      </AreaChart>
    </ResponsiveContainer>
  );
}
