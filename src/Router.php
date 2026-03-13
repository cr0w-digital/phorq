<?php

declare(strict_types=1);

namespace phorq;

/**
 * ⎇ phorq
 *
 * Directory conventions:
 *   routes/
 *     index.php
 *     users.php
 *     [id].php
 *     [...rest].php
 *     [[...rest]]/index.php   (optional catch-all dir)
 *     [...rest]/index.php     (catch-all dir)
 *
 * Module conventions:
 *   modules/<mod>/
 *     config.php   // optional: ['mount' => 'x', 'routes' => '/abs/or/rel/path']
 *     middleware.php
 *     routes/...
 *
 * Core fallback:
 *   modules/core/... same structure
 */
final class Router
{
    /**
     * Map format (cached as PHP file returning array):
     * [
     *   '__mount' => [
     *     'mountName' => ['name' => 'mod', 'routes' => '/path/to/routes', 'root' => '/path/to/moduleRoot', 'mw' => '/path/to/middleware.php'|null],
     *     ...
     *   ],
     *   '/path/to/routes/dir' => ['dirs' => ['users','[id]','[...rest]','[[...rest]]'], 'files' => ['index.php','[id].php',...]],
     *   '/path/to/routes/dir/users' => [...],
     * ]
     */
    private array $map = [];

    /**
     * @param string      $modulesDir Absolute path to the modules directory.
     * @param string|null $cacheFile  Absolute path to the cache file, or null to disable caching.
     */
    public function __construct(
        private string $modulesDir,
        private ?string $cacheFile,
    ) {}

    /**
     * Factory: build a Router, scanning (or loading from cache) the route map.
     *
     * @param string      $modulesDir Absolute path to the modules directory.
     * @param string|null $cacheFile  Path to the cache file, or null to disable caching.
     */
    public static function create(string $modulesDir, ?string $cacheFile = null): self
    {
        $r = new self(rtrim($modulesDir, '/'), $cacheFile);
        $r->map = $r->loadMap();
        return $r;
    }

    /**
     * Resolve a module-relative file with core fallback (layout.php, middleware helpers, etc).
     * If no extension is provided, .php is appended.
     *
     * @param string $name   File name to resolve (e.g. 'layout' or 'helpers.php').
     * @param string $module Module to search first before falling back to core.
     */
    public function resolve(string $name, string $module = 'core'): ?string
    {
        if (!str_contains($name, '.')) $name .= '.php';

        $p = "{$this->modulesDir}/{$module}/{$name}";
        if (is_file($p)) return $p;

        if ($module !== 'core') {
            $p = "{$this->modulesDir}/core/{$name}";
            if (is_file($p)) return $p;
        }

        return null;
    }

    // ── Routing ──────────────────────────────────────────────

    /**
     * Main entrypoint. Matches the request against the route map and dispatches
     * it through the middleware stack.
     *
     * Returns a Result on success, or null on 404.
     *
     * @param mixed        $ctx Optional application context threaded through middleware and route files.
     * @param Request|null $req Pre-built request, or null to build from superglobals.
     */
    public function route(mixed $ctx = null, ?Request $req = null): ?Result
    {
        $req  = $req ?? Request::fromGlobals();
        $path = $req->path;

        if ($path === '') {
            $mount    = 'core';
            $segments = ['index'];
        } else {
            $segments = explode('/', $path);
            $mount    = array_shift($segments) ?: 'core';
        }

        $mountInfo = $this->map['__mount'][$mount] ?? null;

        // Module mount always wins — only fall back to core when no module matches.
        if (!$mountInfo) {
            $mount     = 'core';
            $mountInfo = $this->map['__mount']['core'] ?? null;
            if (!$mountInfo) return null;
            $segments = $path === '' ? ['index'] : explode('/', $path);
        }

        $match = $this->resolveRoute($mount, $mountInfo, $segments);
        if (!$match) return null;

        // Bind pattern and module onto the request before dispatch so that
        // middleware and route files can read $req->pattern and $req->module.
        $req   = $req->withMatch($match['pattern'], $match['module']);
        $value = $this->dispatch($match, $ctx, $req);

        return new Result($value, $match['module']);
    }

    // ── Dispatch ─────────────────────────────────────────────

    /**
     * Execute the matched route file through the middleware stack.
     *
     * Route files receive:
     *   $req    — the Request object (with pattern and module already set)
     *   $ctx    — the application context (whatever the caller passed in, may be null)
     *   $router — this Router instance
     *   + one variable per URL param (e.g. $id, $rest)
     *
     * Returns the raw value from the route file — no casting or wrapping.
     */
    private function dispatch(array $match, mixed $ctx, Request $req): mixed
    {
        $routeFile   = $match['file'];
        $middlewares = $match['mw'];
        $params      = $match['params'];

        $handler = function () use ($routeFile, $params, $req, $ctx) {
            extract($params, EXTR_OVERWRITE);

            ob_start();
            $ret = require $routeFile;
            $buf = ob_get_clean();
            return $buf !== '' ? $buf : $ret;
        };

        foreach (array_reverse($middlewares) as $mwFile) {
            $prev    = $handler;
            $handler = function () use ($mwFile, $prev, $req, $ctx) {
                $mw = require $mwFile;
                if (!is_callable($mw)) {
                    throw new \RuntimeException("Invalid middleware: {$mwFile}");
                }
                return $mw($prev, $req, $ctx, $this);
            };
        }

        return $handler();
    }

    // ── Route resolution ─────────────────────────────────────

    /**
     * Walk the segment list against the route map, matching static dirs, dynamic
     * dirs, and catch-all dirs/files. Returns a match array or null on 404.
     */
    private function resolveRoute(string $mount, array $mountInfo, array $segments): ?array
    {
        $routesDir = $mountInfo['routes'];

        $mw   = [];
        $core = $this->map['__mount']['core'] ?? null;
        if ($core && $core['routes'] !== $routesDir && $core['mw']) $mw[] = $core['mw'];
        if ($mountInfo['mw']) $mw[] = $mountInfo['mw'];

        $dir    = $routesDir;
        $params = [];

        for ($i = 0; $i < count($segments); $i++) {
            $seg   = $segments[$i] ?? '';
            $entry = $this->map[$dir] ?? null;
            if (!$entry) return null;

            // Runtime ambiguity detection
            $candidates = [];
            if (in_array($seg, $entry['dirs'], true)) {
                $candidates[] = ['type' => 'static', 'dir' => $seg];
            }
            foreach ($entry['dirs'] as $d) {
                if ($this->isDynDir($d))      $candidates[] = ['type' => 'dynamic',  'dir' => $d];
            }
            foreach ($entry['dirs'] as $d) {
                if ($this->isOptCatchDir($d)) $candidates[] = ['type' => 'optcatch', 'dir' => $d];
            }
            foreach ($entry['dirs'] as $d) {
                if ($this->isCatchDir($d))    $candidates[] = ['type' => 'catch',    'dir' => $d];
            }
            if (count($candidates) > 1) {
                $names = array_map(fn($c) => $c['dir'], $candidates);
                throw new \RuntimeException(
                    "Ambiguous route resolution at {$dir} for segment '{$seg}': " . implode(', ', $names)
                );
            }

            if ($file = $this->pickFile($dir, array_slice($segments, $i), $entry['files'], $params)) {
                return $this->finalMatch($mount, $routesDir, $file, $params, $mw);
            }

            if (in_array($seg, $entry['dirs'], true)) {
                $dir .= "/{$seg}";
                continue;
            }

            $dyn = $this->first($entry['dirs'], fn($d) => $this->isDynDir($d));
            if ($dyn) {
                $params[$this->dynName($dyn)] = $seg;
                $dir .= "/{$dyn}";
                continue;
            }

            if ($opt = $this->first($entry['dirs'], fn($d) => $this->isOptCatchDir($d))) {
                $params[$this->catchName($opt)] = array_slice($segments, $i);
                return $this->resolveIndex($mount, $routesDir, "{$dir}/{$opt}", $params, $mw);
            }

            if ($all = $this->first($entry['dirs'], fn($d) => $this->isCatchDir($d))) {
                $params[$this->catchName($all)] = array_slice($segments, $i);
                return $this->resolveIndex($mount, $routesDir, "{$dir}/{$all}", $params, $mw);
            }

            return null;
        }

        return $this->resolveIndex($mount, $routesDir, $dir, $params, $mw);
    }

    /**
     * Try to match index.php in the given directory.
     * Falls back to optional catch-all subdirectory if absent.
     */
    private function resolveIndex(string $mount, string $routesDir, string $dir, array $params, array $mw): ?array
    {
        $entry = $this->map[$dir] ?? null;
        if (!$entry) return null;

        if (in_array('index.php', $entry['files'], true)) {
            return $this->finalMatch($mount, $routesDir, "{$dir}/index.php", $params, $mw);
        }

        // No index file — check for optional catch-all file or subdirectory
        foreach ($entry['files'] as $f) {
            if (preg_match('/^\[\[\.\.\.(\w+)\]\]\.php$/', $f, $m)) {
                $params[$m[1]] = [];
                return $this->finalMatch($mount, $routesDir, "{$dir}/{$f}", $params, $mw);
            }
        }

        if ($opt = $this->first($entry['dirs'] ?? [], fn($d) => $this->isOptCatchDir($d))) {
            $params[$this->catchName($opt)] = [];
            return $this->resolveIndex($mount, $routesDir, "{$dir}/{$opt}", $params, $mw);
        }

        return null;
    }

    /**
     * File match precedence:
     *   1. segment.php       — exact static
     *   2. [param].php       — single dynamic
     *   3. [...rest].php / [[...rest]].php  — catch-all
     */
    private function pickFile(string $dir, array $segments, array $files, array &$params): ?string
    {
        $seg = $segments[0] ?? null;
        if (!$seg) return null;

        if (in_array("{$seg}.php", $files, true)) return "{$dir}/{$seg}.php";

        foreach ($files as $f) {
            if (!str_ends_with($f, '.php')) continue;

            if (preg_match('/^\[\[\.\.\.(\w+)\]\]\.php$/', $f, $m)) {
                $params[$m[1]] = $segments;
                return "{$dir}/{$f}";
            }

            if (preg_match('/^\[([^\]]+)\]\.php$/', $f, $m)) {
                $cap = $m[1];
                if (str_starts_with($cap, '...')) {
                    $params[substr($cap, 3)] = $segments;
                } else {
                    $params[$cap] = $seg;
                }
                return "{$dir}/{$f}";
            }
        }

        return null;
    }

    /**
     * Build the final match array from a resolved file path, deriving the route pattern.
     */
    private function finalMatch(string $mount, string $routesDir, string $file, array $params, array $mw): array
    {
        $rel = substr($file, strlen($routesDir));
        $rel = preg_replace('/\.php$/', '', $rel);
        $rel = preg_replace('#/index$#', '', $rel);

        $pattern   = '/' . trim($mount, '/') . ($rel ?: '');
        $mountInfo = $this->map['__mount'][$mount] ?? null;

        return [
            'module'  => $mountInfo['name'] ?? $mount,
            'file'    => $file,
            'params'  => $params,
            'mw'      => $mw,
            'pattern' => $pattern,
        ];
    }

    // ── Map building + caching ───────────────────────────────

    /**
     * Load the route map from cache, or scan the modules directory and build it
     * fresh. Writes the cache file when caching is enabled.
     */
    private function loadMap(): array
    {
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            $m = require $this->cacheFile;
            if (is_array($m) && isset($m['__mount'])) return $m;
        }

        $map = ['__mount' => []];

        foreach (scandir($this->modulesDir) ?: [] as $mod) {
            if ($mod === '.' || $mod === '..') continue;

            $root = "{$this->modulesDir}/{$mod}";
            if (!is_dir($root)) continue;

            $conf = is_file("{$root}/config.php") ? (require "{$root}/config.php") : [];
            if (!is_array($conf)) $conf = [];

            $mount  = (string) ($conf['mount'] ?? $mod);
            $routes = (string) ($conf['routes'] ?? "{$root}/routes");

            if ($routes !== '' && $routes[0] !== '/') {
                $routes = "{$root}/" . ltrim($routes, '/');
            }

            $mw = is_file("{$root}/middleware.php") ? "{$root}/middleware.php" : null;

            $map['__mount'][$mount] = [
                'name'   => $mod,
                'routes' => $routes,
                'root'   => $root,
                'mw'     => $mw,
            ];

            if (is_dir($routes)) $this->scanRoutes($routes, $map);
        }

        if ($this->cacheFile !== null) {
            @mkdir(dirname($this->cacheFile), 0777, true);
            file_put_contents($this->cacheFile, '<?php return ' . var_export($map, true) . ';');
        }

        return $map;
    }

    /**
     * Recursively scan a routes directory, populating the map with dirs and
     * files at each level. Detects ambiguous route shapes at scan time.
     */
    private function scanRoutes(string $dir, array &$map): void
    {
        $dirs  = [];
        $files = [];

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;

            $p = "{$dir}/{$item}";
            if (is_dir($p)) {
                $dirs[] = $item;
                $this->scanRoutes($p, $map);
            } elseif (str_ends_with($item, '.php')) {
                $files[] = $item;
            }
        }

        usort($dirs, fn($a, $b) => $this->dirRank($a) <=> $this->dirRank($b));

        // Static ambiguity detection — directories
        $shapes = [];
        foreach ($dirs as $d) {
            $shape = match (true) {
                $this->isDynDir($d)      => '[param]',
                $this->isCatchDir($d)    => '[...rest]',
                $this->isOptCatchDir($d) => '[[...rest]]',
                default                  => $d,
            };
            if (isset($shapes[$shape])) {
                throw new \RuntimeException(
                    "Ambiguous route shape in {$dir}: '{$d}' and '{$shapes[$shape]}' are equivalent"
                );
            }
            $shapes[$shape] = $d;
        }

        $map[$dir] = ['dirs' => $dirs, 'files' => $files];

        // Static ambiguity detection — dynamic route files
        $fileShapes = [];
        foreach ($files as $f) {
            $shape = match (1) {
                preg_match('/^\[\[\.\.\.([^\]]+)\]\]\.php$/', $f) => '[[...rest]]',
                preg_match('/^\[\.\.\.([^\]]+)\]\.php$/', $f)     => '[...rest]',
                preg_match('/^\[([^\]]+)\]\.php$/', $f)           => '[param]',
                default                                           => null,
            };
            if ($shape === null) continue;
            if (isset($fileShapes[$shape])) {
                throw new \RuntimeException(
                    "Ambiguous dynamic route file in {$dir}: '{$f}' and '{$fileShapes[$shape]}' are equivalent"
                );
            }
            $fileShapes[$shape] = $f;
        }
    }

    /**
     * Sort rank for directories: static (0) < dynamic (1) < catch-all (2) < optional catch-all (3).
     */
    private function dirRank(string $d): int
    {
        return match (true) {
            $this->isDynDir($d)      => 1,
            $this->isCatchDir($d)    => 2,
            $this->isOptCatchDir($d) => 3,
            default                  => 0,
        };
    }

    // ── Pattern helpers ──────────────────────────────────────

    /** True for a single dynamic segment like [id]. */
    private function isDynDir(string $d): bool
    {
        return str_starts_with($d, '[') && str_ends_with($d, ']')
            && !$this->isCatchDir($d) && !$this->isOptCatchDir($d);
    }

    /** True for a catch-all segment like [...rest]. */
    private function isCatchDir(string $d): bool
    {
        return str_starts_with($d, '[...') && str_ends_with($d, ']');
    }

    /** True for an optional catch-all segment like [[...rest]]. */
    private function isOptCatchDir(string $d): bool
    {
        return str_starts_with($d, '[[...') && str_ends_with($d, ']]');
    }

    /** Extract the parameter name from a dynamic dir: [id] → id. */
    private function dynName(string $d): string
    {
        return trim($d, '[]');
    }

    /** Extract the parameter name from a catch-all dir: [...rest] → rest, [[...rest]] → rest. */
    private function catchName(string $d): string
    {
        return ltrim(trim($d, '[]'), '.');
    }

    /** Return the first element of $xs matching $pred, or null. */
    private function first(array $xs, callable $pred): ?string
    {
        foreach ($xs as $x) if ($pred($x)) return $x;
        return null;
    }
}