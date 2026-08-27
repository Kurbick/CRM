<?php

namespace App\Http\Controllers\Web;

use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LocaleController
{
    public function update(Request $request, AuthorizedLandingPage $landingPage): RedirectResponse
    {
        $locale = $request->input('locale');

        abort_unless(
            is_string($locale) && in_array($locale, config('app.supported_locales', ['ru', 'az']), true),
            422,
        );

        $request->session()->put('locale', $locale);
        app()->setLocale($locale);

        $referer = $request->headers->get('referer');

        if (is_string($referer) && $this->isInternalUrl($referer, $request)) {
            return redirect()->to($referer);
        }

        if ($request->user() === null) {
            return redirect()->route('login');
        }

        return redirect()->to($landingPage->url($request->user()));
    }

    private function isInternalUrl(string $url, Request $request): bool
    {
        $referer = parse_url($url);
        $current = parse_url($request->url());

        if (! is_array($referer) || ! is_array($current)) {
            return false;
        }

        return ($referer['scheme'] ?? null) === ($current['scheme'] ?? null)
            && ($referer['host'] ?? null) === ($current['host'] ?? null)
            && ($referer['port'] ?? null) === ($current['port'] ?? null)
            && ! isset($referer['user'], $referer['pass']);
    }
}
