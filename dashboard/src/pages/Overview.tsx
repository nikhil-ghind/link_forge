import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AsyncPanel } from '../components/AsyncPanel';
import { BreakdownBars } from '../components/BreakdownBars';
import { ClickTimeSeries } from '../components/ClickTimeSeries';
import { RangeSelector } from '../components/RangeSelector';
import { StatTile } from '../components/StatTile';
import { TopLinksChart } from '../components/TopLinksChart';
import { useBreakdown, useSummary, useTimeseries, useTopLinks } from '../hooks/useAnalytics';

export function Overview() {
  const [days, setDays] = useState(30);
  const navigate = useNavigate();

  const summary = useSummary(days);
  const series = useTimeseries(days);
  const top = useTopLinks(days, 8);
  const referrers = useBreakdown('referrer', days);
  const countries = useBreakdown('country', days);
  const devices = useBreakdown('device', days);
  const browsers = useBreakdown('browser', days);

  return (
    <>
      <div className="controls">
        <RangeSelector value={days} onChange={setDays} />
        <span className="card-note">
          {summary.data ? `${summary.data.clicks_today.toLocaleString()} clicks so far today` : ''}
        </span>
      </div>

      <div className="grid grid-tiles">
        <AsyncPanel {...summary}>
          {(data) => (
            <>
              <StatTile label="Clicks" value={data.clicks} deltaPct={data.clicks_delta_pct} note="vs previous period" />
              <StatTile label="Unique visitors" value={data.unique_visitors} note={`${days}-day window`} />
              <StatTile label="Active links" value={data.active_links} note={`${data.total_links} total`} />
              <StatTile
                label="Avg clicks / link"
                value={Math.round(data.avg_clicks_per_link)}
                note={`buffer depth ${data.buffer_depth}`}
              />
            </>
          )}
        </AsyncPanel>
      </div>

      <div className="card" style={{ marginBottom: '1rem' }}>
        <div className="card-head">
          <h2 className="card-title">Clicks over time</h2>
          <span className="card-note">{days <= 2 ? 'hourly' : 'daily'} buckets, UTC</span>
        </div>
        <AsyncPanel {...series} isEmpty={(rows) => rows.every((row) => row.clicks === 0)}>
          {(rows) => <ClickTimeSeries data={rows} />}
        </AsyncPanel>
      </div>

      <div className="grid grid-halves">
        <div className="card">
          <div className="card-head">
            <h2 className="card-title">Top links</h2>
            <span className="card-note">click a bar to drill in</span>
          </div>
          <AsyncPanel {...top} isEmpty={(rows) => rows.length === 0}>
            {(rows) => <TopLinksChart data={rows} onSelect={(id) => navigate(`/links/${id}`)} />}
          </AsyncPanel>
        </div>

        <div className="card">
          <div className="card-head">
            <h2 className="card-title">Referrers</h2>
          </div>
          <AsyncPanel {...referrers} isEmpty={(rows) => rows.length === 0}>
            {(rows) => <BreakdownBars rows={rows} />}
          </AsyncPanel>
        </div>

        <div className="card">
          <div className="card-head">
            <h2 className="card-title">Countries</h2>
          </div>
          <AsyncPanel {...countries} isEmpty={(rows) => rows.length === 0}>
            {(rows) => <BreakdownBars rows={rows} labelAs="country" />}
          </AsyncPanel>
        </div>

        <div className="card">
          <div className="card-head">
            <h2 className="card-title">Devices &amp; browsers</h2>
          </div>
          <AsyncPanel {...devices} isEmpty={(rows) => rows.length === 0}>
            {(rows) => <BreakdownBars rows={rows} limit={4} />}
          </AsyncPanel>
          <div style={{ height: '1rem' }} />
          <AsyncPanel {...browsers} isEmpty={(rows) => rows.length === 0}>
            {(rows) => <BreakdownBars rows={rows} limit={4} />}
          </AsyncPanel>
        </div>
      </div>
    </>
  );
}
