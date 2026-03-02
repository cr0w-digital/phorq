<?php
declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;
use phorq\Router;
use phorq\Context;
use phorq\Result;

/**
 * Integration tests for Router.
 *
 * Each test builds a temporary module tree on disk, creates a Router, and
 * asserts that route() dispatches (or 404s) correctly.
 */
class RouterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phorq_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = "{$dir}/{$item}";
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }

    private function putFile(string $relPath, string $content): void
    {
        $abs = "{$this->tmpDir}/{$relPath}";
        $dir = dirname($abs);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($abs, $content);
    }

    private function makeRouter(): Router
    {
        return Router::create($this->tmpDir);
    }

    private function routeCapture(Router $router, string $method, string $path): ?Result
    {
        $ctx = new Context();
        $req = [
            'method' => $method,
            'path'   => trim($path, '/'),
            'query'  => [],
            'input'  => [],
        ];

        return $router->route($ctx, $req);
    }

    // ----------------------------------------------------------------
    // Tests: basic routing
    // ----------------------------------------------------------------

    public function testCoreIndexRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "home";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/');

        $this->assertSame('home', $res->body);
    }

    public function testStaticSegmentRoute(): void
    {
        $this->putFile('core/routes/about.php', '<?php echo "about-page";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/about');

        $this->assertSame('about-page', $res->body);
    }

    public function testSubdirectoryIndexRoute(): void
    {
        $this->putFile('core/routes/users/index.php', '<?php echo "users-list";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/users');

        $this->assertSame('users-list', $res->body);
    }

    public function test404ForMissingRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "home";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/nonexistent');

        $this->assertNull($res);
    }

    // ----------------------------------------------------------------
    // Tests: method-specific handlers
    // ----------------------------------------------------------------

    public function testMethodSpecificFileIndex(): void
    {
        $this->putFile('core/routes/items/index.php', '<?php echo "items-get";');
        $this->putFile('core/routes/items/index.POST.php', '<?php echo "items-post";');
        $router = $this->makeRouter();

        $get  = $this->routeCapture($router, 'GET', '/items');
        $post = $this->routeCapture($router, 'POST', '/items');

        $this->assertSame('items-get', $get->body);
        $this->assertSame('items-post', $post->body);
    }

    public function testMethodSpecificSegmentFile(): void
    {
        $this->putFile('core/routes/login.GET.php', '<?php echo "login-form";');
        $this->putFile('core/routes/login.POST.php', '<?php echo "login-submit";');
        $router = $this->makeRouter();

        $get  = $this->routeCapture($router, 'GET', '/login');
        $post = $this->routeCapture($router, 'POST', '/login');

        $this->assertSame('login-form', $get->body);
        $this->assertSame('login-submit', $post->body);
    }

    // ----------------------------------------------------------------
    // Tests: dynamic params
    // ----------------------------------------------------------------

    public function testDynamicParamFile(): void
    {
        $this->putFile('core/routes/users/[id].php', '<?php echo "user-{$id}";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/users/42');

        $this->assertSame('user-42', $res->body);
    }

    public function testDynamicParamDirectory(): void
    {
        $this->putFile('core/routes/users/[id]/settings/index.php', '<?php echo "settings-{$id}";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/users/99/settings');

        $this->assertSame('settings-99', $res->body);
    }

    // ----------------------------------------------------------------
    // Tests: catch-all routes
    // ----------------------------------------------------------------

    public function testCatchAllFile(): void
    {
        $this->putFile('core/routes/docs/[...rest].php', '<?php echo implode("/", $rest);');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/docs/a/b/c');

        $this->assertSame('a/b/c', $res->body);
    }

    public function testCatchAllDirectory(): void
    {
        $this->putFile('core/routes/files/[...path]/index.php', '<?php echo implode(",", $path);');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/files/x/y');

        $this->assertSame('x,y', $res->body);
    }

    public function testOptionalCatchAll(): void
    {
        $this->putFile('core/routes/pages/[[...path]]/index.php',
            '<?php echo $path ? implode("/", $path) : "root";');
        $router = $this->makeRouter();

        // Matches the directory root (no trailing segments)
        $root = $this->routeCapture($router, 'GET', '/pages');
        $this->assertSame('root', $root->body);

        // Matches with trailing segments
        $deep = $this->routeCapture($router, 'GET', '/pages/foo/bar');
        $this->assertSame('foo/bar', $deep->body);
    }

    public function testOptionalCatchAllFile(): void
    {
        $this->putFile('core/routes/search/[[...terms]].php',
            '<?php echo $terms ? implode("+", $terms) : "empty";');
        $router = $this->makeRouter();

        // Bare path — zero segments
        $bare = $this->routeCapture($router, 'GET', '/search');
        $this->assertSame('empty', $bare->body);

        // With segments
        $deep = $this->routeCapture($router, 'GET', '/search/php/router');
        $this->assertSame('php+router', $deep->body);
    }

    // ----------------------------------------------------------------
    // Tests: modules + mount
    // ----------------------------------------------------------------

    public function testModuleMountRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "core-home";');
        $this->putFile('blog/config.php', '<?php return ["mount" => "blogger"];');
        $this->putFile('blog/routes/index.php', '<?php echo "blog-home";');
        $router = $this->makeRouter();

        $core = $this->routeCapture($router, 'GET', '/');
        $blog = $this->routeCapture($router, 'GET', '/blog');
        $blogger = $this->routeCapture($router, 'GET', '/blogger');

        $this->assertSame('core-home', $core->body);
        $this->assertNull($blog);
        $this->assertSame('blog-home', $blogger->body);
    }

    public function testModuleDynamicRoute(): void
    {
        $this->putFile('blog/routes/[slug].php', '<?php echo "post-{$slug}";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/blog/hello-world');

        $this->assertSame('post-hello-world', $res->body);
    }

    // ----------------------------------------------------------------
    // Tests: middleware
    // ----------------------------------------------------------------

    public function testModuleMiddlewareRuns(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $this->putFile('core/middleware.php', '<?php return function($next, $req, $ctx, $router) { return "MW[" . $next() . "]"; };');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/');

        $this->assertSame('MW[ok]', $res->body);
    }

    public function testCoreMiddlewareRunsForOtherModules(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('core/middleware.php', '<?php return function($next, $req, $ctx, $router) { return "CORE[" . $next() . "]"; };');
        $this->putFile('blog/routes/index.php', '<?php echo "blog";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/blog');

        $this->assertSame('CORE[blog]', $res->body);
    }

    public function testBothMiddlewaresStack(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('core/middleware.php', '<?php return function($next, $req, $ctx, $router) { return "C[" . $next() . "]"; };');
        $this->putFile('api/middleware.php', '<?php return function($next, $req, $ctx, $router) { return "A[" . $next() . "]"; };');
        $this->putFile('api/routes/index.php', '<?php echo "data";');
        $router = $this->makeRouter();
        $res = $this->routeCapture($router, 'GET', '/api');

        $this->assertSame('C[A[data]]', $res->body);
    }

    // ----------------------------------------------------------------
    // Tests: output wrapping via Context
    // ----------------------------------------------------------------

    public function testContextWrapJson(): void
    {
        $this->putFile('core/routes/data.php', '<?php return ["json" => ["ok" => true]];');
        $ctx = new Context();
        $ctx->wrap['json'] = fn(mixed $data) => json_encode($data);
        $router = $this->makeRouter();
        $req = ['method' => 'GET', 'path' => 'data', 'query' => [], 'input' => []];

        $result = $router->route($ctx, $req);

        $this->assertSame('{"ok":true}', $result->body);
    }

    // ----------------------------------------------------------------
    // Tests: resolve()
    // ----------------------------------------------------------------

    public function testResolveFallsBackToCore(): void
    {
        $this->putFile('core/layout.php', '<?php // layout');
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();

        $res = $this->routeCapture($router, 'GET', '/blog');
        $this->assertSame('blog', $res->module);

        // blog has no layout.php, so resolve should fall back to core
        $resolved = $router->resolve('layout', $res->module);
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('core/layout.php', $resolved);
    }

    public function testResolvePrefersActiveModule(): void
    {
        $this->putFile('core/layout.php', '<?php // core-layout');
        $this->putFile('blog/layout.php', '<?php // blog-layout');
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();

        $res = $this->routeCapture($router, 'GET', '/blog');
        $this->assertSame('blog', $res->module);

        $resolved = $router->resolve('layout', $res->module);
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('blog/layout.php', $resolved);
        $this->assertStringNotContainsString('core/layout.php', $resolved);
    }

    public function testResolveReturnsNullForMissing(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $router = $this->makeRouter();

        $this->assertNull($router->resolve('nonexistent'));
    }

    // ----------------------------------------------------------------
    // Tests: result module
    // ----------------------------------------------------------------

    public function testResultContainsModule(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('blog/config.php', '<?php return ["mount" => "blogger"];');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();

        $core = $this->routeCapture($router, 'GET', '/');
        $this->assertSame('core', $core->module);

        $blog = $this->routeCapture($router, 'GET', '/blogger');
        $this->assertSame('blog', $blog->module);
    }

    // ----------------------------------------------------------------
    // Tests: cache
    // ----------------------------------------------------------------

    public function testCacheFile(): void
    {
        $cacheFile = "{$this->tmpDir}/_cache/routes.php";
        $this->putFile('core/routes/index.php', '<?php echo "cached";');

        $router = Router::create($this->tmpDir, $cacheFile);
        $this->assertFileExists($cacheFile);

        // Add a new route AFTER cache was written — the cached map won't know about it
        $this->putFile('core/routes/new-page.php', '<?php echo "new";');

        $router2 = Router::create($this->tmpDir, $cacheFile);

        // Cached route still works
        $res = $this->routeCapture($router2, 'GET', '/');
        $this->assertSame('cached', $res->body);

        // New route is invisible because the stale cache doesn't list it
        $res2 = $this->routeCapture($router2, 'GET', '/new-page');
        $this->assertNull($res2);
    }

    // ----------------------------------------------------------------
    // Tests: edge cases
    // ----------------------------------------------------------------

    public function testStaticRouteBeforeDynamic(): void
    {
        $this->putFile('core/routes/users/index.php', '<?php echo "list";');
        $this->putFile('core/routes/users/[id].php', '<?php echo "user-{$id}";');
        $this->putFile('core/routes/users/admin.php', '<?php echo "admin-page";');
        $router = $this->makeRouter();

        $admin = $this->routeCapture($router, 'GET', '/users/admin');
        $dyn   = $this->routeCapture($router, 'GET', '/users/42');

        $this->assertSame('admin-page', $admin->body);
        $this->assertSame('user-42', $dyn->body);
    }

    public function testCoreRouteWithNoMatchingModule(): void
    {
        $this->putFile('core/routes/foo/index.php', '<?php echo "foo-core";');
        $router = $this->makeRouter();
        // /foo isn't a module — it's just a core route at core/routes/foo/
        $res = $this->routeCapture($router, 'GET', '/foo');

        $this->assertSame('foo-core', $res->body);
    }

    public function testModuleMountShadowsCoreRoute(): void
    {
        // Both a module mounted at /api AND a core route at core/routes/api/ exist.
        // The module mount should win.
        $this->putFile('core/routes/api/index.php', '<?php echo "core-api";');
        $this->putFile('api/routes/index.php', '<?php echo "module-api";');
        $router = $this->makeRouter();

        $res = $this->routeCapture($router, 'GET', '/api');
        $this->assertSame('module-api', $res->body);
    }

    public function testBuildRequestDefaults(): void
    {
        // Without superglobals set, we still get a sane default
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test';
        $_GET = ['q' => '1'];

        $req = Router::buildRequest();

        $this->assertSame('GET', $req['method']);
        $this->assertSame('test', $req['path']);
        $this->assertSame(['q' => '1'], $req['query']);
    }
}
