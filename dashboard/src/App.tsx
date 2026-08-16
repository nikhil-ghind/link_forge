import { useState } from 'react';
import { NavLink, Route, Routes } from 'react-router-dom';
import { clearToken, getToken, setToken } from './api/client';
import { LinkDetail } from './pages/LinkDetail';
import { Links } from './pages/Links';
import { Overview } from './pages/Overview';

type Theme = 'light' | 'dark' | 'system';

function TokenGate({ onSubmit }: { onSubmit: (token: string) => void }) {
  const [value, setValue] = useState('');

  return (
    <div className="card gate">
      <h1 className="card-title">LinkForge Analytics</h1>
      <p>
        Paste an API token to continue. Issue one on the server with{' '}
        <span className="mono">php artisan linkforge:token dashboard</span>. It is kept in this
        browser only.
      </p>
      <form
        onSubmit={(event) => {
          event.preventDefault();

          if (value.trim()) {
            onSubmit(value.trim());
          }
        }}
      >
        <input type="password" value={value} placeholder="lf_…" onChange={(e) => setValue(e.target.value)} />
        <button className="btn btn-primary" type="submit">
          Continue
        </button>
      </form>
    </div>
  );
}

export function App() {
  const [token, setTokenState] = useState(getToken());
  const [theme, setTheme] = useState<Theme>('system');

  const applyTheme = (next: Theme) => {
    setTheme(next);

    if (next === 'system') {
      document.documentElement.removeAttribute('data-theme');
    } else {
      document.documentElement.setAttribute('data-theme', next);
    }
  };

  if (!token) {
    return (
      <div className="shell">
        <TokenGate
          onSubmit={(value) => {
            setToken(value);
            setTokenState(value);
          }}
        />
      </div>
    );
  }

  return (
    <div className="shell">
      <header className="topbar">
        <div className="brand">
          LinkForge <span>analytics</span>
        </div>

        <nav className="nav">
          <NavLink to="/" end className={({ isActive }) => (isActive ? 'active' : '')}>
            Overview
          </NavLink>
          <NavLink to="/links" className={({ isActive }) => (isActive ? 'active' : '')}>
            Links
          </NavLink>
        </nav>

        <div className="controls" style={{ margin: 0 }}>
          <div className="segmented" role="group" aria-label="Theme">
            {(['light', 'system', 'dark'] as Theme[]).map((option) => (
              <button
                key={option}
                type="button"
                aria-pressed={theme === option}
                onClick={() => applyTheme(option)}
              >
                {option}
              </button>
            ))}
          </div>
          <button
            className="btn"
            type="button"
            onClick={() => {
              clearToken();
              setTokenState('');
            }}
          >
            Sign out
          </button>
        </div>
      </header>

      <main>
        <Routes>
          <Route path="/" element={<Overview />} />
          <Route path="/links" element={<Links />} />
          <Route path="/links/:id" element={<LinkDetail />} />
          <Route path="*" element={<div className="state">Page not found.</div>} />
        </Routes>
      </main>
    </div>
  );
}
