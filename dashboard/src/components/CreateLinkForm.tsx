import { useState } from 'react';
import { ApiError } from '../api/client';
import { api } from '../api/endpoints';
import type { Link } from '../api/types';

interface Props {
  onCreated: () => void;
}

export function CreateLinkForm({ onCreated }: Props) {
  const [targetUrl, setTargetUrl] = useState('');
  const [alias, setAlias] = useState('');
  const [title, setTitle] = useState('');
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const [created, setCreated] = useState<Link | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors([]);

    try {
      const link = await api.createLink({
        target_url: targetUrl.trim(),
        alias: alias.trim() || undefined,
        title: title.trim() || undefined,
      });

      setCreated(link);
      setTargetUrl('');
      setAlias('');
      setTitle('');
      onCreated();
    } catch (error) {
      // Validation failures come back field-keyed; surface every message
      // rather than only the first, since alias and URL can both fail.
      setErrors(
        error instanceof ApiError
          ? error.fieldMessages.length > 0
            ? error.fieldMessages
            : [error.message]
          : ['Could not create the link.'],
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="card" style={{ marginBottom: '1.25rem' }}>
      <div className="card-head">
        <h2 className="card-title">Create a short link</h2>
        <span className="card-note">leave the alias blank for a generated slug</span>
      </div>

      <form className="controls" style={{ marginBottom: 0 }} onSubmit={submit}>
        <input
          type="url"
          required
          value={targetUrl}
          placeholder="https://example.com/destination"
          onChange={(event) => setTargetUrl(event.target.value)}
          style={{ flex: '2 1 22rem' }}
        />
        <input
          type="text"
          value={alias}
          placeholder="custom-alias"
          pattern="[A-Za-z0-9]*"
          onChange={(event) => setAlias(event.target.value)}
          style={{ flex: '1 1 9rem' }}
        />
        <input
          type="text"
          value={title}
          placeholder="Label (optional)"
          onChange={(event) => setTitle(event.target.value)}
          style={{ flex: '1 1 11rem' }}
        />
        <button className="btn btn-primary" type="submit" disabled={saving}>
          {saving ? 'Creating…' : 'Create'}
        </button>
      </form>

      {errors.length > 0 && (
        <ul className="state state-error" style={{ textAlign: 'left', paddingBottom: 0 }}>
          {errors.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      )}

      {created && (
        <p className="card-note" style={{ marginBottom: 0 }}>
          Created <span className="mono">{created.short_url}</span> →{' '}
          {created.target_url}
        </p>
      )}
    </div>
  );
}
