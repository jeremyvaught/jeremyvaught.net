<?php

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

it('uses forwarded HTTPS only from the portfolio load balancers', function (string $remote, bool $trusted): void {
    Route::setRoutes(new RouteCollection);
    Route::get('/__proxy-scheme', fn (Request $request): array => [
        'secure' => $request->isSecure(),
        'generated_url' => url('/scheme-target'),
        'route_url' => route('proxy-scheme'),
        'client_ip' => $request->ip(),
    ])->name('proxy-scheme');
    Route::getRoutes()->refreshNameLookups();
    app('url')->setRoutes(Route::getRoutes());

    $this->withServerVariables(['REMOTE_ADDR' => $remote, 'HTTPS' => 'off'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-For' => '203.0.113.11',
        ])->getJson('http://localhost/__proxy-scheme')
        ->assertOk()
        ->assertJson([
            'secure' => $trusted,
            'generated_url' => ($trusted ? 'https' : 'http').'://localhost/scheme-target',
            'route_url' => ($trusted ? 'https' : 'http').'://localhost/__proxy-scheme',
            'client_ip' => $trusted ? '203.0.113.11' : $remote,
        ]);
})->with([
    'LB1 private address' => ['10.116.0.3', true],
    'LB2 private address' => ['10.116.0.4', true],
    'untrusted public caller' => ['203.0.113.5', false],
    'old application server is not a proxy' => ['10.116.0.9', false],
]);
