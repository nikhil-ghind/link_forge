import { useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { AsyncPanel } from '../components/AsyncPanel';
import { BreakdownBars } from '../components/BreakdownBars';
import { ClickTimeSeries } from '../components/ClickTimeSeries';
import { RangeSelector } from '../components/RangeSelector';
import { StatTile } from '../components/StatTile';
import { useLinkStats } from '../hooks/useAnalytics';

export function LinkDetail() {
  const { id } = useParams<{ id: string }>();
  const [days, setDays] = useState(30);
  const linkId = Number(id);

  const stats = useLinkStats(linkId, days);

  if (!Number.isFinite(linkId)) {
    return <div className="state state-error">That link id is not valid.</div>;
  }

  return (
    <>
      <div className="controls">
        <RouterLink to="/links" className="btn">
          ← All links
        </RouterLink>
        <RangeSelector value={days} onChange={setDays} />
      </div>

      <AsyncPanel {...stats}>
        {(data) => (
          <>
            <div className="card" style={{ marginBottom: '1rem' }}>
              <div className="card-head">
                <h2 className="card-title mono">/{data.slug}</h2>
                <span className="card-note">{data.short_url}</span>
              </div>
              <a href={data.target_url} target="_blank" rel="noreferrer noopener">
                {data.target_url}
              </a>
            </div>

            <div className="grid grid-tiles">
              <StatTile
                label="Clicks"
                value={data.summary.clicks}
                deltaPct={data.summary.clicks_delta_pct}
                note="vs previous period"
              />
              <StatTile label="Unique visitors" value={data.summary.unique_visitors} />
              <StatTile label="Today" value={data.summary.clicks_today} note="live counter" />
            </div>

            <div className="card" style={{ marginBottom: '1rem' }}>
              <div className="card-head">
                <h2 className="card-title">Clicks over time</h2>
                <span className="card-note">{days <= 2 ? 'hourly' : 'daily'} buckets, UTC</span>
              </div>
              <ClickTimeSeries data={data.timeseries} />
            </div>

            <div className="grid grid-halves">
              <div className="card">
                <div className="card-head">
                  <h2 className="card-title">Referrers</h2>
                </div>
                <BreakdownBars rows={data.referrers} />
              </div>
              <div className="card">
                <div className="card-head">
                  <h2 className="card-title">Countries</h2>
                </div>
                <BreakdownBars rows={data.countries} labelAs="country" />
              </div>
              <div className="card">
                <div className="card-head">
                  <h2 className="card-title">Devices</h2>
                </div>
                <BreakdownBars rows={data.devices} limit={4} />
              </div>
              <div className="card">
                <div className="card-head">
                  <h2 className="card-title">Browsers</h2>
                </div>
                <BreakdownBars rows={data.browsers} limit={5} />
              </div>
            </div>
          </>
        )}
      </AsyncPanel>
    </>
  );
}
