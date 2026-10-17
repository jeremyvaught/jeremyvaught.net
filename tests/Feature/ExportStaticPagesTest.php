<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

const STATIC_HTML = '<!doctype html><html><body><h1>Hello</h1></body></html>';
const FORM_HTML = '<!doctype html><html><body><form method="post"><input type="hidden" name="_token" value="abc"></form></body></html>';

beforeEach(function () {
    $sandbox = storage_path('framework/testing/static-export-'.bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($sandbox.'/public');
    $this->app->usePublicPath($sandbox.'/public');
    // Stand in for the live site. The command fetches pages over HTTP, so fake those responses.
    Http::fake([
        '*/index.php/form-page' => Http::response(FORM_HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        '*/index.php/missing' => Http::response('Not found', 404, ['Content-Type' => 'text/html; charset=UTF-8']),
        '*' => Http::response(STATIC_HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
    ]);
});

afterEach(function () {
    File::deleteDirectory(dirname(public_path()));
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

it('rejects unsafe paths before fetching or changing exports', function (string $path) {
    File::put(public_path('index.html'), 'Previous export');
    $this->artisan('site:export', ['--path' => [$path]])->assertFailed();
    expect(File::get(public_path('index.html')))->toBe('Previous export');
    Http::assertNothingSent();
})->with(['/../outside', '/one/../two', '/one//two', '/?query=1', '/#fragment', '/one\\two', '/%2e%2e/outside']);

it('rejects unsafe or malformed stale manifests without deleting files', function (string $manifest) {
    $victim = dirname(public_path()).'/victim.html';
    File::put($victim, 'Retain outside file');
    File::put(public_path('.static-export.json'), $manifest);
    $this->artisan('site:export', ['--path' => ['/']])->assertFailed();
    expect(File::get($victim))->toBe('Retain outside file')
        ->and(File::get(public_path('.static-export.json')))->toBe($manifest);
    Http::assertNothingSent();
})->with(['["../victim.html"]', '[".static-export.json"]', '[123]', '{"page":"index.html"}', 'invalid json']);

it('rejects target and stale-directory symlinks before fetching', function (bool $stale) {
    $outside = dirname(public_path()).'/outside';
    File::ensureDirectoryExists($outside);
    File::put($outside.'/index.html', 'Retain outside file');
    symlink($outside, public_path('linked'));
    if ($stale) {
        File::put(public_path('.static-export.json'), '["linked/index.html"]');
    }
    $this->artisan('site:export', ['--path' => [$stale ? '/' : '/linked']])->assertFailed();
    expect(File::get($outside.'/index.html'))->toBe('Retain outside file');
    Http::assertNothingSent();
})->with([false, true]);

it('rejects a symlinked manifest without overwriting its target', function () {
    $outside = dirname(public_path()).'/outside.json';
    File::put($outside, '[]');
    symlink($outside, public_path('.static-export.json'));
    $this->artisan('site:export', ['--path' => ['/']])->assertFailed();
    expect(File::get($outside))->toBe('[]');
    Http::assertNothingSent();
});

it('skips whitespace CSRF attributes and Livewire markup', function (string $html) {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
    $this->artisan('site:export', ['--path' => ['/']])->assertSuccessful();
    expect(public_path('index.html'))->not->toBeFile();
})->with(['<input name = "_token" value="private">', '<input name=_token value="private">', '<meta name=csrf-token content="private">', "<meta name = 'csrf-token' content='private'>", '<div data-csrf = "private"></div>', '<div wire:id="private" wire:snapshot="private"></div>']);

it('leaves class-protected routes dynamic during discovery and explicit export', function (string $middleware) {
    Route::get('/private-page', fn () => 'Private')->middleware($middleware);
    $this->artisan('site:export --dry-run')->doesntExpectOutputToContain('private-page')->assertSuccessful();
    $this->artisan('site:export', ['--path' => ['/private-page']])->assertSuccessful();
    expect(public_path('private-page/index.html'))->not->toBeFile();
    Http::assertNothingSent();
})->with([Authenticate::class, Authenticate::class.':web', EnsureEmailIsVerified::class, RedirectIfAuthenticated::class]);

it('does not follow redirects or save their destination as a page', function () {
    Http::swap(new Factory);
    Http::fake(function ($request, array $options) {
        expect($options['allow_redirects'])->toBeFalse();

        return Http::response('', 302, ['Location' => 'https://other.example/', 'Content-Type' => 'text/html']);
    });
    $this->artisan('site:export', ['--path' => ['/']])->assertSuccessful();
    expect(public_path('index.html'))->not->toBeFile();
    Http::assertSentCount(1);
});

it('preserves every previous export and manifest if any fetch fails', function (string $failure) {
    $manifest = '["index.html", "static-page/index.html"]';
    File::ensureDirectoryExists(public_path('static-page'));
    File::put(public_path('index.html'), 'Previous home');
    File::put(public_path('static-page/index.html'), 'Previous inner page');
    File::put(public_path('.static-export.json'), $manifest);
    Http::swap(new Factory);
    $requests = 0;
    Http::fake(function () use (&$requests, $failure) {
        if (++$requests === 1) {
            return Http::response(STATIC_HTML, 200, ['Content-Type' => 'text/html']);
        }
        if ($failure === 'network') {
            throw new ConnectionException('Local test connection failure');
        }

        return Http::response('Server error', 500, ['Content-Type' => 'text/html']);
    });
    $this->artisan('site:export', ['--path' => ['/', '/static-page']])->assertFailed();
    expect(File::get(public_path('index.html')))->toBe('Previous home')
        ->and(File::get(public_path('static-page/index.html')))->toBe('Previous inner page')
        ->and(File::get(public_path('.static-export.json')))->toBe($manifest);
})->with(['server', 'network']);

it('removes a stale export when its route deliberately stops serving HTML', function (int $status, string $type) {
    File::put(public_path('index.html'), 'Previous home');
    File::put(public_path('.static-export.json'), '["index.html"]');
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('No longer a page', $status, ['Content-Type' => $type])]);
    $this->artisan('site:export', ['--path' => ['/']])->assertSuccessful();
    expect(public_path('index.html'))->not->toBeFile();
})->with([[404, 'text/html'], [302, 'text/html'], [200, 'application/json']]);
