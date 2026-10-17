<?php

namespace App\Console\Commands;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Save the site's public pages as plain HTML files in public/, so nginx serves
 * them directly (Forge's config tries real files and index.html before PHP).
 * Visitors to those pages never start Laravel: no PHP, no database, no session.
 *
 * Pages are fetched over HTTP from the live site (APP_URL), exactly as a visitor
 * gets them, via /index.php/<path> so Laravel renders them even when a static
 * copy already exists. Run it in the deploy script AFTER the new release is live.
 * Only for pages that are the same for every visitor; pages behind login are skipped.
 */
class ExportStaticPages extends Command
{
    protected $signature = 'site:export
        {--path=* : Export only these paths (default: every public GET route without parameters)}
        {--dry-run : List what would be written without writing}';

    protected $description = 'Save public pages as static HTML files that nginx serves without PHP';

    /** URI prefixes that are never static pages. */
    private const SKIP_PREFIXES = [
        'dashboard', 'settings', 'login', 'logout', 'register', 'password', 'forgot-password',
        'reset-password', 'email', 'two-factor', 'user', 'livewire', 'flux', 'storage', 'up',
        '.well-known', 'build', 'sanctum', 'api',
    ];

    private const MANIFEST = '.static-export.json';

    public function handle(): int
    {
        try {
            $paths = $this->input->getOption('path');
            if (! is_array($paths)) {
                throw new InvalidArgumentException('Page paths must be a list.');
            }
            $paths = $paths ?: $this->discoverPaths();
            $files = [];
            foreach ($paths as $path) {
                if (! is_string($path)) {
                    throw new InvalidArgumentException('Page paths must be strings.');
                }
                $path = '/'.ltrim($path, '/');
                $relative = $this->fileFor($path);
                $this->safeFile($relative);
                $files[$path] = $relative;
            }
            $previous = $this->previousFiles();
            $manifest = $this->safeFile(self::MANIFEST);
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach ($files as $path => $relative) {
                $this->components->twoColumnDetail($path, 'public/'.$relative);
            }

            return self::SUCCESS;
        }

        // Finish all HTTP reads before changing files. A server/network failure
        // must retain the previous exports and manifest for this release.
        $pages = [];
        foreach ($files as $path => $relative) {
            try {
                $route = Route::getRoutes()->match(Request::create(rtrim(config('app.url'), '/').$path, 'GET'));
                if ($this->protectedRoute($route)) {
                    $this->components->warn("Skipped {$path} (protected or runtime route)");

                    continue;
                }
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                // Explicit paths may be absent locally; the HTTP status gates them.
            }
            try {
                $response = Http::withHeaders(['User-Agent' => 'site-export'])
                    ->withoutRedirecting()
                    ->timeout(30)
                    ->get(rtrim(config('app.url'), '/').'/index.php'.$path);
            } catch (ConnectionException) {
                $this->components->error("Could not fetch {$path}; previous exports retained.");

                return self::FAILURE;
            }
            if ($response->serverError()) {
                $this->components->error("Could not fetch {$path} ({$response->status()}); previous exports retained.");

                return self::FAILURE;
            }

            $type = (string) $response->header('Content-Type');
            if ($response->status() !== 200 || ! str_starts_with($type, 'text/html')) {
                $this->components->warn("Skipped {$path} ({$response->status()}, {$type})");

                continue;
            }
            if ($reason = $this->dynamicMarker($response->body())) {
                $this->components->warn("Skipped {$path} (contains {$reason}; leave it to Laravel)");

                continue;
            }
            $pages[$relative] = $response->body();
        }

        foreach ($pages as $relative => $body) {
            File::ensureDirectoryExists(dirname($this->safeFile($relative)));
            File::put($this->safeFile($relative), $body);
            $this->components->twoColumnDetail($relative, "public/{$relative}");
        }
        $written = array_keys($pages);
        foreach ($previous as $old) {
            if (! in_array($old, $written, true)) {
                File::delete($this->safeFile($old));
            }
        }
        File::put($manifest, json_encode($written, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->components->info(count($written).' page(s) exported.');

        return self::SUCCESS;
    }

    /** @return list<string> Public GET routes with no parameters or protected middleware. */
    private function discoverPaths(): array
    {
        return array_values(collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
            ->reject(fn (RoutingRoute $route) => str_contains($route->uri(), '{'))
            ->reject(fn (RoutingRoute $route) => $this->protectedRoute($route))
            // Livewire/Flux asset routes carry a random suffix, e.g. livewire-16741e36/livewire.js
            ->reject(fn (RoutingRoute $route) => preg_match('#^(livewire|flux)[-/]#', $route->uri()) === 1)
            // Only page-like paths: no file extension other than .html
            ->reject(fn (RoutingRoute $route) => preg_match('#\.(?!html$)[a-z0-9]+$#i', $route->uri()) === 1)
            ->map(fn (RoutingRoute $route) => '/'.ltrim($route->uri(), '/'))
            ->unique()
            ->values()
            ->all());
    }

    /** Markers of content that must not be shared between visitors, or null if the page is safe to freeze. */
    private function dynamicMarker(string $html): ?string
    {
        $markers = [
            'a CSRF token' => '/\bname\s*=\s*(?:["\'](?:_token|csrf-token)["\']|(?:_token|csrf-token)(?=\s|\/?>))|\bdata-csrf\s*=/i',
            'Livewire' => '/wire:(?:id|snapshot|model|click|submit)|livewire:/i',
        ];

        foreach ($markers as $label => $pattern) {
            if (preg_match($pattern, $html) === 1) {
                return $label;
            }
        }

        return null;
    }

    /** "/" → index.html, "/about" → about/index.html, "/404.html" → 404.html */
    private function fileFor(string $path): string
    {
        if (! preg_match('#^/[a-zA-Z0-9_/-]*(?:\.html)?$#', $path) || str_contains($path, '//')) {
            throw new InvalidArgumentException('Unsafe page path.');
        }
        $path = trim($path, '/');

        if ($path === '') {
            return 'index.html';
        }

        return str_ends_with($path, '.html') ? $path : $path.'/index.html';
    }

    private function protectedRoute(RoutingRoute $route): bool
    {
        return collect($route->gatherMiddleware())->contains(function ($middleware): bool {
            if (! is_string($middleware)) {
                return false;
            }
            $name = explode(':', ltrim($middleware, '\\'), 2)[0];

            return str_starts_with($name, 'auth') || str_starts_with($name, 'guest') || $name === 'verified'
                || in_array($name, [Authenticate::class, EnsureEmailIsVerified::class, RedirectIfAuthenticated::class], true);
        }) || collect(self::SKIP_PREFIXES)->contains(fn ($prefix) => $route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'));
    }

    /** Resolve only exported HTML files (or the manifest), never traversal or symlinks. */
    private function safeFile(string $relative): string
    {
        if ($relative !== self::MANIFEST && preg_match('#^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+\.html$#', $relative) !== 1) {
            throw new InvalidArgumentException('Unsafe static export filename.');
        }
        $root = realpath(public_path());
        if ($root === false) {
            throw new InvalidArgumentException('Public directory is missing.');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new InvalidArgumentException('Static export paths cannot contain symlinks.');
            }
        }

        return $path;
    }

    /** @return list<string> */
    private function previousFiles(): array
    {
        $manifest = $this->safeFile(self::MANIFEST);
        if (! File::exists($manifest)) {
            return [];
        }
        $previous = json_decode(File::get($manifest), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($previous) || ! array_is_list($previous)) {
            throw new InvalidArgumentException('Static export manifest must be a list of HTML files.');
        }
        $files = [];
        foreach ($previous as $old) {
            if (! is_string($old) || $old === self::MANIFEST) {
                throw new InvalidArgumentException('Static export manifest filenames must be strings.');
            }
            $this->safeFile($old);
            $files[] = $old;
        }

        return $files;
    }
}
