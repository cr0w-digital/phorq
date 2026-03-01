<?php
declare(strict_types=1);

namespace phorq;

/**
 * ⎇ phorq
 *
 * Directory conventions:
 *   routes/
 *     index.php
 *     index.GET.php
 *     users.php
 *     users.POST.php
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
     *      'mountName' => ['routes' => '/path/to/routes', 'root' => '/path/to/moduleRoot', 'mw' => '/path/to/middleware.php'|null],
     *      ...
     *   ],
     *   '/path/to/routes/dir' => ['dirs' => ['users','[id]','[...rest]','[[...rest]]'], 'files' => ['index.php','[id].GET.php',...]],
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

    /**
     * Build a request array from PHP superglobals.
     */
    public static function buildRequest(): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $path   = trim((string)parse_url($uri, PHP_URL_PATH), '/');

        $input = $method === 'GET'
            ? []
            : ($_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?? []));

        return [
            'method' => $method,
            'path'   => $path,
            'query'  => $_GET,
            'input'  => $input,
        ];
    }

    /**
     * Main entrypoint.
     * Returns a Result with status (200 = success, 404 = not found) and body.
     *
     * @param Context    $ctx  Application context (wrappers, shared state)
     * @param array|null $req  Optional pre-built request array (for testing). When null, buildRequest() is used.
     */
    public function route(Context $ctx, ?array $req = null): Result
    {
        $req    = $req ?? self::buildRequest();
        $method = $req['method'];
        $path   = $req['path'];

        if ($path === '') {
            $mount = 'core';
            $segments = ['index'];
            $fullSegments = $segments;
        } else {
            $fullSegments = explode('/', $path);
            $mount = array_shift($fullSegments) ?: 'core';
            $segments = $fullSegments;
        }

        $mountInfo = $this->map['__mount'][$mount] ?? null;

        // Module mount always wins — only fall back to core when no module matches.
        if (!$mountInfo) {
            $mount = 'core';
            $mountInfo = $this->map['__mount']['core'] ?? null;
            if (!$mountInfo) return new Result(404, '');
            $segments = $path === '' ? ['index'] : explode('/', $path);
        }

        $match = $this->resolveRoute($mount, $mountInfo, $segments, $method);
        if (!$match) return new Result(404, '');

        $req['pattern'] = $match['pattern'];

        $body = (string) $this->dispatch($match, $ctx, $req);
        return new Result(200, $body, $match['module']);
    }

    // ---------------------------
    // Dispatch
    // ---------------------------

    /**
     * Execute the matched route file through the middleware stack and apply output wrapping.
     */
    private function dispatch(array $match, Context $ctx, array $req): mixed
    {
        $routeFile   = $match['file'];
        $middlewares = $match['mw'];
        $params      = $match['params'];

        $handler = function () use ($routeFile, $params, $req) {
            extract($req, EXTR_SKIP);
            extract($params, EXTR_OVERWRITE);

            ob_start();
            $ret = require $routeFile;
            $buf = ob_get_clean();
            return $buf !== '' ? $buf : $ret;
        };

        foreach (array_reverse($middlewares) as $mwFile) {
            $prev = $handler;
            $handler = function () use ($mwFile, $prev, $req, $ctx) {
                $mw = require $mwFile;
                if (!is_callable($mw)) {
                    throw new \RuntimeException("Invalid middleware: {$mwFile}");
                }
                return $mw($prev, $req, $ctx, $this);
            };
        }

        $out = $handler();

        if (is_array($out) && count($out) === 1) {
            $type = array_key_first($out);
            $content = $out[$type];
        } else {
            $type = 'html';
            $content = $out;
        }

        return isset($ctx->wrap[$type]) ? $ctx->wrap[$type]($content) : (string)$content;
    }

    // ---------------------------
    // Route resolution
    // ---------------------------

    /**
     * Walk the segment list against the route map, matching static dirs, dynamic dirs,
     * and catch-all dirs/files. Returns a match array or null on 404.
     */
    private function resolveRoute(string $mount, array $mountInfo, array $segments, string $method): ?array
    {
        $routesDir  = $mountInfo['routes'];
        $moduleRoot = $mountInfo['root'];

        $mw = [];

        $core = $this->map['__mount']['core'] ?? null;
        if ($core && $core['routes'] !== $routesDir && $core['mw']) $mw[] = $core['mw'];
        if ($mountInfo['mw']) $mw[] = $mountInfo['mw'];

        $dir = $routesDir;
        $params = [];

        for ($i = 0; $i < count($segments); $i++) {
            $seg = $segments[$i] ?? '';
            $entry = $this->map[$dir] ?? null;
            if (!$entry) return null;

            if ($file = $this->pickFile($dir, array_slice($segments, $i), $entry['files'], $method, $params)) {
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
                return $this->resolveIndex($mount, $routesDir, "{$dir}/{$opt}", $method, $params, $mw);
            }
            if ($all = $this->first($entry['dirs'], fn($d) => $this->isCatchDir($d))) {
                $params[$this->catchName($all)] = array_slice($segments, $i);
                return $this->resolveIndex($mount, $routesDir, "{$dir}/{$all}", $method, $params, $mw);
            }

            return null;
        }

        return $this->resolveIndex($mount, $routesDir, $dir, $method, $params, $mw);
    }

    /**
     * Try to match an index file (method-specific first, then generic) in the given directory.
     * Falls back to optional catch-all subdirectory if no index file is found.
     */
    private function resolveIndex(string $mount, string $routesDir, string $dir, string $method, array $params, array $mw): ?array
    {
        $entry = $this->map[$dir] ?? null;
        if (!$entry) return null;

        foreach (["index.{$method}.php", 'index.php'] as $f) {
            if (in_array($f, $entry['files'], true)) {
                return $this->finalMatch($mount, $routesDir, "{$dir}/{$f}", $params, $mw);
            }
        }

        // No index file — check for optional catch-all file or subdirectory
        foreach ($entry['files'] as $f) {
            if (preg_match('/^\[\[\.\.\.(\w+)\]\](?:\.' . preg_quote($method, '/') . ')?\.php$/', $f, $m)) {
                $params[$m[1]] = [];
                return $this->finalMatch($mount, $routesDir, "{$dir}/{$f}", $params, $mw);
            }
        }

        if ($opt = $this->first($entry['dirs'] ?? [], fn($d) => $this->isOptCatchDir($d))) {
            $params[$this->catchName($opt)] = [];
            return $this->resolveIndex($mount, $routesDir, "{$dir}/{$opt}", $method, $params, $mw);
        }

        return null;
    }

    /**
     * File precedence:
     * 1) exact segment.{METHOD}.php, exact segment.php
     * 2) [param].{METHOD}.php / [param].php
     * 3) [...rest].{METHOD}.php / [...rest].php / [[...rest]].php
     */
    private function pickFile(string $dir, array $segments, array $files, string $method, array &$params): ?string
    {
        $seg = $segments[0] ?? null;
        if (!$seg) return null;

        foreach (["{$seg}.{$method}.php", "{$seg}.php"] as $f) {
            if (in_array($f, $files, true)) return "{$dir}/{$f}";
        }

        foreach ($files as $f) {
            if (!str_ends_with($f, '.php')) continue;

            // [[...rest]].php — optional catch-all file
            if (preg_match('/^\[\[\.\.\.(\w+)\]\](?:\.' . preg_quote($method, '/') . ')?\.php$/', $f, $m)) {
                $params[$m[1]] = $segments;
                return "{$dir}/{$f}";
            }

            if (preg_match('/^\[([^\]]+)\](?:\.' . preg_quote($method, '/') . ')?\.php$/', $f, $m)) {
                $cap = $m[1];

                if (str_starts_with($cap, '...')) {
                    $params[substr($cap, 3)] = $segments;
                    return "{$dir}/{$f}";
                }

                $params[$cap] = $seg;
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
        $rel = preg_replace('/\.(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)$/', '', $rel);
        $rel = preg_replace('#/index$#', '', $rel);
        $pattern = '/' . trim($mount, '/')
            . ($rel ? $rel : '');

        $mountInfo = $this->map['__mount'][$mount] ?? null;

        return [
            'module'  => $mountInfo['name'] ?? $mount,
            'file'    => $file,
            'params'  => $params,
            'mw'      => $mw,
            'pattern' => $pattern,
        ];
    }

    // ---------------------------
    // Map building + caching
    // ---------------------------

    /**
     * Load the route map from cache, or scan the modules directory and build it fresh.
     * Writes the cache file when caching is enabled.
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

            $mount = (string)($conf['mount'] ?? $mod);

            $routes = (string)($conf['routes'] ?? "{$root}/routes");
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
     * Recursively scan a routes directory, populating the map with dirs and files at each level.
     */
    private function scanRoutes(string $dir, array &$map): void
    {
        $dirs = [];
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
        $map[$dir] = ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * Sort rank for directories: static (0) < dynamic (1) < catch-all (2) < optional catch-all (3).
     */
    private function dirRank(string $d): int
    {
        if ($this->isDynDir($d)) return 1;
        if ($this->isCatchDir($d)) return 2;
        if ($this->isOptCatchDir($d)) return 3;
        return 0;
    }

    // ---------------------------
    // Pattern helpers
    // ---------------------------

    /** Check if a directory name is a single dynamic param like [id]. */
    private function isDynDir(string $d): bool
    {
        return str_starts_with($d, '[') && str_ends_with($d, ']')
            && !$this->isCatchDir($d) && !$this->isOptCatchDir($d);
    }

    /** Check if a directory name is a catch-all like [...rest]. */
    private function isCatchDir(string $d): bool
    {
        return str_starts_with($d, '[...') && str_ends_with($d, ']');
    }

    /** Check if a directory name is an optional catch-all like [[...rest]]. */
    private function isOptCatchDir(string $d): bool
    {
        return str_starts_with($d, '[[...') && str_ends_with($d, ']]');
    }

    /** Extract the parameter name from a dynamic directory: [id] → id. */
    private function dynName(string $d): string
    {
        return trim($d, '[]');
    }

    /** Extract the parameter name from a catch-all directory: [...rest] → rest, [[...rest]] → rest. */
    private function catchName(string $d): string
    {
        $x = trim($d, '[]');
        return ltrim($x, '.');
    }

    /** Return the first element matching a predicate, or null. */
    private function first(array $xs, callable $pred): ?string
    {
        foreach ($xs as $x) if ($pred($x)) return $x;
        return null;
    }
}
