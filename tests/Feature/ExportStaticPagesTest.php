<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const STATIC_HTML = '<!doctype html><html><body><h1>Hello</h1></body></html>';
const FORM_HTML = '<!doctype html><html><body><form method="post"><input type="hidden" name="_token" value="abc"></form></body></html>';

beforeEach(function () {
    // Stand in for the live site. The command fetches pages over HTTP, so fake those responses.
    Http::fake([
        '*/index.php/form-page' => Http::response(FORM_HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        '*/index.php/missing' => Http::response('Not found', 404, ['Content-Type' => 'text/html; charset=UTF-8']),
        '*' => Http::response(STATIC_HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
    ]);
});

afterEach(function () {
    File::delete(public_path('index.html'));
    File::delete(public_path('.static-export.json'));
    File::deleteDirectory(public_path('static-page'));
    File::deleteDirectory(public_path('form-page'));
});

it('saves a page exactly as the site serves it', function () {
    $this->artisan('site:export --path=/static-page')->assertSuccessful();

    expect(File::get(public_path('static-page/index.html')))->toBe(STATIC_HTML);
});

it('saves the homepage as public/index.html', function () {
    $this->artisan('site:export --path=/')->assertSuccessful();

    expect(File::get(public_path('index.html')))->toBe(STATIC_HTML);
});

it('fetches pages through index.php so existing static copies are never re-saved', function () {
    $this->artisan('site:export --path=/static-page')->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/index.php/static-page'));
});

it('skips pages with a CSRF token so forms keep working', function () {
    $this->artisan('site:export --path=/form-page')->assertSuccessful();

    expect(public_path('form-page/index.html'))->not->toBeFile();
});

it('skips pages that do not return 200', function () {
    $this->artisan('site:export --path=/missing')->assertSuccessful();

    expect(public_path('missing/index.html'))->not->toBeFile();
});

it('removes files from an earlier export that are no longer pages', function () {
    $this->artisan('site:export --path=/static-page')->assertSuccessful();
    $this->artisan('site:export --path=/')->assertSuccessful();

    expect(public_path('static-page/index.html'))->not->toBeFile()
        ->and(public_path('index.html'))->toBeFile();
});

it('discovers public pages but never pages behind login', function () {
    $this->artisan('site:export --dry-run')
        ->expectsOutputToContain('public/index.html')
        ->doesntExpectOutputToContain('dashboard')
        ->doesntExpectOutputToContain('settings')
        ->assertSuccessful();

    Http::assertNothingSent();
});
