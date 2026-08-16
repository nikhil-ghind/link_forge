import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { TopLink } from '../api/types';
import { formatCount, formatExact, truncate } from '../lib/format';
import { chart } from '../lib/palette';

interface Props {
  data: TopLink[];
  onSelect?: (linkId: number) => void;
}

interface TooltipPayload {
  active?: boolean;
  payload?: { payload: TopLink }[];
}

function ChartTooltip({ active, payload }: TooltipPayload) {
  if (!active || !payload?.length) {
    return null;
  }

  const link = payload[0].payload;

  return (
    <div className="tooltip">
      <div className="tooltip-label">{link.title ?? link.slug}</div>
      <div className="tooltip-value">{formatExact(link.clicks)} clicks</div>
    </div>
  );
}

/**
 * Horizontal bars: the labels are slugs and titles, which read far better on
 * the y-axis than rotated under vertical columns.
 */
export function TopLinksChart({ data, onSelect }: Props) {
  const height = Math.max(200, data.length * 34 + 24);

  return (
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} layout="vertical" margin={{ top: 0, right: 16, bottom: 0, left: 8 }}>
        <CartesianGrid stroke={chart.grid} horizontal={false} />

        <XAxis
          type="number"
          tickFormatter={formatCount}
          tickLine={false}
          axisLine={false}
          tick={{ fill: chart.axis, fontSize: 12 }}
          allowDecimals={false}
        />
        <YAxis
          type="category"
          dataKey="slug"
          width={104}
          tickLine={false}
          axisLine={false}
          tick={{ fill: chart.axis, fontSize: 12 }}
          tickFormatter={(slug: string) => truncate(slug, 12)}
        />

        <Tooltip content={<ChartTooltip />} cursor={{ fill: chart.grid, fillOpacity: 0.45 }} />

        <Bar
          dataKey="clicks"
          radius={[0, 4, 4, 0]}
          barSize={14}
          isAnimationActive={false}
          onClick={(entry: TopLink) => onSelect?.(entry.link_id)}
          cursor={onSelect ? 'pointer' : undefined}
        >
          {data.map((link) => (
            <Cell key={link.link_id} fill={chart.series1} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}
