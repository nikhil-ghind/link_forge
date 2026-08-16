<?php

use App\Http\Controllers\RedirectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Redirect routes
|--------------------------------------------------------------------------
|
| The slug route is intentionally the only thing here and it runs with an
| empty middleware group: no session, no cookie encryption, no CSRF token.
| Those cost several milliseconds and buy nothing for an anonymous 302.
|
| The regex constraint keeps the route from swallowing /api, static assets or
| dotted paths like favicon.ico, and rejects malformed slugs before any Redis
| call is made.
|
*/

Route::middleware('redirect')->group(function () {
    Route::get('/{slug}', RedirectController::class)
        ->where('slug', '[A-Za-z0-9]{1,32}')
        ->name('redirect');
});

Route::get('/', fn () => redirect()->away(rtrim((string) config('app.url'), '/').'/dashboard'))
    ->name('root');
