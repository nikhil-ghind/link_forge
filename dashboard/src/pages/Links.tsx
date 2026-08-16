import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { api } from '../api/endpoints';
import { ApiError } from '../api/client';
import { AsyncPanel } from '../components/AsyncPanel';
import { CreateLinkForm } from '../components/CreateLinkForm';
import { useLinks } from '../hooks/useAnalytics';
import { formatExact, formatRelative, truncate } from '../lib/format';

type SortKey = 'created_at' | 'click_count' | 'last_clicked_at' | 'slug';

export function Links() {
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<SortKey>('created_at');
  const [direction, setDirection] = useState<'asc' | 'desc'>('desc');
  const [copied, setCopied] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const links = useLinks({ page, q: search, sort, direction });

  const toggleSort = (key: SortKey) => {
    if (key === sort) {
      setDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'));
    } else {
      setSort(key);
      setDirection('desc');
    }

    setPage(1);
  };

  const runAction = async (action: () => Promise<unknown>) => {
    setActionError(null);

    try {
      await action();
      links.refresh();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : 'Action failed.');
    }
  };

  const copy = async (link: { id: number; short_url: string }) => {
    await navigator.clipboard.writeText(link.short_url);
    setCopied(link.id);
    window.setTimeout(() => setCopied(null), 1500);
  };

  const header = (key: SortKey, label: string) => (
    <th className={key === 'click_count' ? 'num' : undefined}>
      <button type="button" onClick={() => toggleSort(key)}>
        {label}
        {sort === key ? (direction === 'asc' ? ' ▲' : ' ▼') : ''}
      </button>
    </th>
  );

  return (
    <>
      <CreateLinkForm onCreated={links.refresh} />

      <div className="controls">
        <form
          onSubmit={(event) => {
            event.preventDefault();
            setSearch(q);
            setPage(1);
          }}
        >
          <input
            type="text"
            value={q}
            placeholder="Search slug, title or destination"
            onChange={(event) => setQ(event.target.value)}
            style={{ minWidth: '18rem' }}
          />
        </form>
        {search && (
          <button
            className="btn"
            type="button"
            onClick={() => {
              setQ('');
              setSearch('');
            }}
          >
            Clear
          </button>
        )}
      </div>

      {actionError && <div className="state state-error">{actionError}</div>}

      <div className="card">
        <AsyncPanel {...links} isEmpty={(page_) => page_.data.length === 0} emptyMessage="No links yet.">
          {(paginated) => (
            <>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      {header('slug', 'Short link')}
                      <th>Destination</th>
                      {header('click_count', 'Clicks')}
                      {header('last_clicked_at', 'Last click')}
                      <th>Status</th>
                      <th aria-label="Actions" />
                    </tr>
                  </thead>
                  <tbody>
                    {paginated.data.map((link) => (
                      <tr key={link.id}>
                        <td>
                          <RouterLink className="mono" to={`/links/${link.id}`}>
                            /{link.slug}
                          </RouterLink>
                          {link.title && <div className="card-note">{truncate(link.title, 34)}</div>}
                        </td>
                        <td>
                          <a href={link.target_url} target="_blank" rel="noreferrer noopener">
                            {truncate(link.target_url, 52)}
                          </a>
                        </td>
                        <td className="num">{formatExact(link.click_count)}</td>
                        <td>{formatRelative(link.last_clicked_at)}</td>
                        <td>
                          <span className={`pill ${link.is_redirectable ? 'pill-on' : 'pill-off'}`}>
                            {link.is_redirectable ? 'live' : link.is_active ? 'inactive' : 'disabled'}
                          </span>
                        </td>
                        <td style={{ whiteSpace: 'nowrap' }}>
                          <button className="btn" type="button" onClick={() => void copy(link)}>
                            {copied === link.id ? 'Copied' : 'Copy'}
                          </button>{' '}
                          <button
                            className="btn"
                            type="button"
                            onClick={() =>
                              void runAction(() => api.updateLink(link.id, { is_active: !link.is_active }))
                            }
                          >
                            {link.is_active ? 'Disable' : 'Enable'}
                          </button>{' '}
                          <button
                            className="btn btn-danger"
                            type="button"
                            onClick={() => {
                              if (window.confirm(`Delete /${link.slug}? The slug is never reissued.`)) {
                                void runAction(() => api.deleteLink(link.id));
                              }
                            }}
                          >
                            Delete
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="controls" style={{ marginTop: '1rem', marginBottom: 0 }}>
                <button
                  className="btn"
                  type="button"
                  disabled={paginated.meta.current_page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  Previous
                </button>
                <span className="card-note">
                  Page {paginated.meta.current_page} of {paginated.meta.last_page} ·{' '}
                  {formatExact(paginated.meta.total)} links
                </span>
                <button
                  className="btn"
                  type="button"
                  disabled={paginated.meta.current_page >= paginated.meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </button>
              </div>
            </>
          )}
        </AsyncPanel>
      </div>
    </>
  );
}
