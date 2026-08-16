<?php

namespace App\Http\Controllers;

use App\Services\ClickBuffer;
use App\Services\LinkCache;
use App\Support\ClickRecord;
use App\Support\ResolvedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The hot path.
 *
 * Budget for a cache hit: one Redis GET, one pipelined Redis write for the
 * click, one 302. No session, no CSRF, no MySQL, no queue dispatch.
 */
class RedirectController extends Controller
{
    public function __construct(
        private readonly LinkCache $cache,
        private readonly ClickBuffer $clicks,
    ) {}

    public function __invoke(Request $request, string $slug): RedirectResponse|Response
    {
        $link = $this->cache->resolve($slug);

        if ($link === null) {
            return $this->notFound($slug);
        }

        if (! $link->isActive) {
            return $this->gone($slug, 'disabled');
        }

        if ($link->isExpired()) {
            return $this->gone($slug, 'expired');
        }

        if ($link->maxClicks !== null && $link->hasReachedCap($this->clicks->liveClicks($link->id))) {
            return $this->gone($slug, 'cap_reached');
        }

        $this->clicks->record(ClickRecord::fromRequest($link->id, $request));

        return $this->redirectTo($link);
    }

    private function redirectTo(ResolvedLink $link): RedirectResponse
    {
        $status = in_array($link->redirectStatus, [301, 302, 307, 308], true)
            ? $link->redirectStatus
            : (int) config('linkforge.redirect.status', 302);

        $response = new RedirectResponse($link->targetUrl, $status);

        // A cached redirect is an untracked redirect. Only a permanent 301 is
        // allowed to be cached, and even then only by the client.
        if ($status === 301 || $status === 308) {
            $response->headers->set('Cache-Control', 'public, max-age=86400');
        } else {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }

        $response->headers->set('X-LinkForge-Slug', $link->slug);
        $response->headers->set('Referrer-Policy', 'unsafe-url');

        return $response;
    }

    /**
     * 404 for unknown slugs. Kept cheap and cacheable at the edge for a short
     * window so slug-space scanners cost the origin almost nothing.
     */
    private function notFound(string $slug): Response
    {
        return response()
            ->view('errors.link-not-found', ['slug' => $slug, 'reason' => 'unknown'], 404)
            ->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * 410 for links that existed but no longer resolve — semantically distinct
     * from "never existed", and useful signal in the access logs.
     */
    private function gone(string $slug, string $reason): Response
    {
        return response()
            ->view('errors.link-not-found', ['slug' => $slug, 'reason' => $reason], 410)
            ->header('Cache-Control', 'private, no-store');
    }
}
