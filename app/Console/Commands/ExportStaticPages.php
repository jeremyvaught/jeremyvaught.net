<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

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
        $paths = $this->option('path') ?: $this->discoverPaths();
        $written = [];

        if ($this->option('dry-run')) {
            foreach ($paths as $path) {
                $this->components->twoColumnDetail('/'.ltrim($path, '/'), 'public/'.$this->fileFor($path));
            }

            return self::SUCCESS;
        }

        foreach ($paths as $path) {
            $path = '/'.ltrim($path, '/');
            $response = Http::withHeaders(['User-Agent' => 'site-export'])
                ->timeout(30)
                ->get(rtrim(config('app.url'), '/').'/index.php'.$path);

            $type = (string) $response->header('Content-Type');
            if ($response->status() !== 200 || ! str_starts_with($type, 'text/html')) {
                $this->components->warn("Skipped {$path} ({$response->status()}, {$type})");

                continue;
            }

            // Never freeze per-visitor content: forms with CSRF tokens, Livewire, etc.
            if ($reason = $this->dynamicMarker($response->body())) {
                $this->components->warn("Skipped {$path} (contains {$reason}; leave it to Laravel)");

                continue;
            }

            $relative = $this->fileFor($path);
            $written[] = $relative;

            File::ensureDirectoryExists(dirname(public_path($relative)));
            File::put(public_path($relative), $response->body());
            $this->components->twoColumnDetail($path, "public/{$relative}");
        }

        $this->removeStaleFiles($written);
        File::put(public_path(self::MANIFEST), json_encode(array_values($written), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->components->info(count($written).' page(s) exported.');

        return self::SUCCESS;
    }

    /** Public GET routes with no parameters and no auth/guest middleware. */
    private function discoverPaths(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
            ->reject(fn (RoutingRoute $route) => str_contains($route->uri(), '{'))
            ->reject(fn (RoutingRoute $route) => collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'guest') || $m === 'verified')))
            ->reject(fn (RoutingRoute $route) => collect(self::SKIP_PREFIXES)
                ->contains(fn ($prefix) => $route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/')))
            // Livewire/Flux asset routes carry a random suffix, e.g. livewire-16741e36/livewire.js
            ->reject(fn (RoutingRoute $route) => preg_match('#^(livewire|flux)[-/]#', $route->uri()) === 1)
            // Only page-like paths: no file extension other than .html
            ->reject(fn (RoutingRoute $route) => preg_match('#\.(?!html$)[a-z0-9]+$#i', $route->uri()) === 1)
            ->map(fn (RoutingRoute $route) => '/'.ltrim($route->uri(), '/'))
            ->unique()
            ->values()
            ->all();
    }

    /** Markers of content that must not be shared between visitors, or null if the page is safe to freeze. */
    private function dynamicMarker(string $html): ?string
    {
        $markers = [
            'a CSRF token' => '/name=["\']_token["\']|name=["\']csrf-token["\']|data-csrf=/i',
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
        $path = trim($path, '/');

        if ($path === '') {
            return 'index.html';
        }

        return str_ends_with($path, '.html') ? $path : $path.'/index.html';
    }

    /** Delete files written by a previous export that are no longer exported (non zero-downtime sites). */
    private function removeStaleFiles(array $written): void
    {
        $manifest = public_path(self::MANIFEST);
        if (! File::exists($manifest)) {
            return;
        }

        foreach (json_decode(File::get($manifest), true) ?: [] as $old) {
            if (! in_array($old, $written, true) && File::exists(public_path($old))) {
                File::delete(public_path($old));
                $this->components->twoColumnDetail("removed public/{$old}", 'stale');
            }
        }
    }
}
