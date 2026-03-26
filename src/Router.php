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
    private array $map = [];
    private array $middleware = [];

    public function __construct(
        private string $modulesDir,
        private ?string $cacheFile,
    ) {}

    public static function create(string $modulesDir, ?string $cacheFile = null): self
    {
        $r = new self(rtrim($modulesDir, '/'), $cacheFile);
        $r->map = $r->loadMap();
        return $r;
    }

    public function use(callable $middleware): static
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function resolve(string $name, string $module = 'core'): ?string
    {
        if (!str_contains($name, '.')) $name .= '.php';

        // Resolve mount alias to actual module directory name
        $moduleDir = $this->map['__mount'][$module]['name'] ?? $module;

        $p = "{$this->modulesDir}/{$moduleDir}/{$name}";
        if (is_file($p)) return $p;

        if ($moduleDir !== 'core') {
            $p = "{$this->modulesDir}/core/{$name}";
            if (is_file($p)) return $p;
        }

        return null;
    }

    // ── Routing ──────────────────────────────────────────────

    public function route(mixed $ctx = null, ?Request $req = null): Result
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

        if (!$mountInfo) {
            $mount     = 'core';
            $mountInfo = $this->map['__mount']['core'] ?? null;
            if (!$mountInfo) return Result::notFound();
            $segments = $path === '' ? ['index'] : explode('/', $path);
        }

        $match = $this->resolveRoute($mount, $mountInfo, $segments);
        if (!$match) return Result::notFound();

        $req = $req->withMatch($match['pattern'], $match['module']);

        // Reset directives for this request
        directives()->reset();

        $directives = $this->runMiddleware($match, $ctx, $req);

        return new Result(
            module:     $match['module'],
            directives: $directives,
        );
    }

    // ── Middleware chain ──────────────────────────────────────

    private function runMiddleware(array $match, mixed $ctx, Request $req): array
    {
        $routeFile   = $match['file'];
        $middlewares = [...$this->middleware, ...$match['mw']];
        $params      = $match['params'];

        $currentModule = $match['module'] ?? 'core';

        $self = $this;

        $handler = function () use ($routeFile, $params, $req, $ctx, $self, $currentModule): array {
            extract($params, EXTR_OVERWRITE);

            $router  = $self;

            // Module-aware resolve — defaults to the current route's module
            $resolve = fn(string $name, ?string $module = null) =>
                $self->resolve($name, $module ?? $currentModule);

            ob_start();
            $ret = require $routeFile;
            $buf = ob_get_clean();

            // Route returned a directive list (e.g. return html(...), return redirect(...))
            // Already swept — use directly, merge any stack modifiers (title, trigger etc.)
            if (is_array($ret) && is_directive_list($ret)) {
                $modifiers = directives()->sweep(); // pick up any modifier directives
                return array_merge($modifiers, $ret);
            }

            // Captured echo output — implicit html directive
            if ($buf !== '') {
                directives()->push('html', ['content' => $buf, 'code' => 200]);
            }
            // Returned string or phml node — implicit html directive
            elseif ($ret !== null && $ret !== 1) {
                directives()->push('html', ['content' => $ret, 'code' => 200]);
            }

            return directives()->sweep();
        };

        foreach (array_reverse($middlewares) as $mw) {
            $prev    = $handler;
            $handler = function () use ($mw, $prev, $req, $ctx): array {
                $resolved = is_callable($mw) ? $mw : require $mw;
                if (!is_callable($resolved)) {
                    throw new \RuntimeException("Invalid middleware: {$mw}");
                }

                $directives = $resolved($prev, $req, $ctx, $this);

                if (!is_array($directives)) {
                    throw new \RuntimeException(
                        "Middleware must return an array of directives. Got " . get_debug_type($directives) . ". " .
                        "Call \$next() and return its result, or return [] to short-circuit."
                    );
                }

                return $directives;
            };
        }

        return $handler();
    }

    // ── Route resolution ─────────────────────────────────────

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

    private function resolveIndex(string $mount, string $routesDir, string $dir, array $params, array $mw): ?array
    {
        $entry = $this->map[$dir] ?? null;
        if (!$entry) return null;

        if (in_array('index.php', $entry['files'], true)) {
            return $this->finalMatch($mount, $routesDir, "{$dir}/index.php", $params, $mw);
        }

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
            } elseif (str_ends_with($item, '.php') || str_ends_with($item, '.md')) {
                $files[] = $item;
            }
        }

        usort($dirs, fn($a, $b) => $this->dirRank($a) <=> $this->dirRank($b));

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

    private function dirRank(string $d): int
    {
        return match (true) {
            $this->isDynDir($d)      => 1,
            $this->isCatchDir($d)    => 2,
            $this->isOptCatchDir($d) => 3,
            default                  => 0,
        };
    }

    private function isDynDir(string $d): bool
    {
        return str_starts_with($d, '[') && str_ends_with($d, ']')
            && !$this->isCatchDir($d) && !$this->isOptCatchDir($d);
    }

    private function isCatchDir(string $d): bool
    {
        return str_starts_with($d, '[...') && str_ends_with($d, ']');
    }

    private function isOptCatchDir(string $d): bool
    {
        return str_starts_with($d, '[[...') && str_ends_with($d, ']]');
    }

    private function dynName(string $d): string
    {
        return trim($d, '[]');
    }

    private function catchName(string $d): string
    {
        return ltrim(trim($d, '[]'), '.');
    }

    private function first(array $xs, callable $pred): ?string
    {
        foreach ($xs as $x) if ($pred($x)) return $x;
        return null;
    }
}