<?php

namespace App\Http\Controllers\Web;

use App\Services\ActiveOrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrganizationContextController
{
    public function update(Request $request, ActiveOrganizationContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);

        $organization = $context->activeById((int) $validated['organization_id']);
        abort_unless($organization !== null, 422);
        $context->select($organization, $request);

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $this->isInternalUrl($referer, $request)) {
            return redirect()->to($referer);
        }

        return redirect()->route('dashboard');
    }

    private function isInternalUrl(string $url, Request $request): bool
    {
        $referer = parse_url($url);
        $current = parse_url($request->url());

        return is_array($referer)
            && is_array($current)
            && ($referer['scheme'] ?? null) === ($current['scheme'] ?? null)
            && ($referer['host'] ?? null) === ($current['host'] ?? null)
            && ($referer['port'] ?? null) === ($current['port'] ?? null)
            && ! isset($referer['user'], $referer['pass']);
    }
}
