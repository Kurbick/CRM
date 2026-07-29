<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class HomeController extends Controller
{
    public function index(Request $request, AuthorizedLandingPage $landingPage): View
    {
        $authorizedLandingUrl = $landingPage->url($request->user());

        return view('home', [
            'authorizedLandingUrl' => $authorizedLandingUrl,
            'hasReadableSection' => $authorizedLandingUrl !== route('home'),
        ]);
    }
}
