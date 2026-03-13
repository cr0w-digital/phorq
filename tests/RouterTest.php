<?php
declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;
use phorq\Router;
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

    // ── Helpers ──────────────────────────────────────────────

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
        $req = new \phorq\Request($method, trim($path, '/'));
        return $router->route(null, $req);
    }

    // ── Basic routing ────────────────────────────────────────

    public function testCoreIndexRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "home";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/');
        $this->assertSame('home', $res->value);
    }

    public function testStaticSegmentRoute(): void
    {
        $this->putFile('core/routes/about.php', '<?php echo "about-page";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/about');
        $this->assertSame('about-page', $res->value);
    }

    public function testSubdirectoryIndexRoute(): void
    {
        $this->putFile('core/routes/users/index.php', '<?php echo "users-list";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/users');
        $this->assertSame('users-list', $res->value);
    }

    public function test404ForMissingRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "home";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/nonexistent');
        $this->assertNull($res);
    }

    // ── Method branching ─────────────────────────────────────

    public function testMethodBranchingInsideRouteFile(): void
    {
        $this->putFile('core/routes/login.php',
            '<?php echo $req->isPost() ? "login-submit" : "login-form";');
        $router = $this->makeRouter();

        $this->assertSame('login-form',   $this->routeCapture($router, 'GET',  '/login')->value);
        $this->assertSame('login-submit', $this->routeCapture($router, 'POST', '/login')->value);
    }

    // ── Dynamic params ───────────────────────────────────────

    public function testDynamicParamFile(): void
    {
        $this->putFile('core/routes/users/[id].php', '<?php echo "user-{$id}";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/users/42');
        $this->assertSame('user-42', $res->value);
    }

    public function testDynamicParamDirectory(): void
    {
        $this->putFile('core/routes/users/[id]/settings/index.php', '<?php echo "settings-{$id}";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/users/99/settings');
        $this->assertSame('settings-99', $res->value);
    }

    // ── Catch-all routes ─────────────────────────────────────

    public function testCatchAllFile(): void
    {
        $this->putFile('core/routes/docs/[...rest].php', '<?php echo implode("/", $rest);');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/docs/a/b/c');
        $this->assertSame('a/b/c', $res->value);
    }

    public function testCatchAllDirectory(): void
    {
        $this->putFile('core/routes/files/[...path]/index.php', '<?php echo implode(",", $path);');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/files/x/y');
        $this->assertSame('x,y', $res->value);
    }

    public function testOptionalCatchAll(): void
    {
        $this->putFile('core/routes/pages/[[...path]]/index.php',
            '<?php echo $path ? implode("/", $path) : "root";');
        $router = $this->makeRouter();

        $this->assertSame('root',    $this->routeCapture($router, 'GET', '/pages')->value);
        $this->assertSame('foo/bar', $this->routeCapture($router, 'GET', '/pages/foo/bar')->value);
    }

    public function testOptionalCatchAllFile(): void
    {
        $this->putFile('core/routes/search/[[...terms]].php',
            '<?php echo $terms ? implode("+", $terms) : "empty";');
        $router = $this->makeRouter();

        $this->assertSame('empty',      $this->routeCapture($router, 'GET', '/search')->value);
        $this->assertSame('php+router', $this->routeCapture($router, 'GET', '/search/php/router')->value);
    }

    // ── Modules + mount ──────────────────────────────────────

    public function testModuleMountRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "core-home";');
        $this->putFile('blog/config.php', '<?php return ["mount" => "blogger"];');
        $this->putFile('blog/routes/index.php', '<?php echo "blog-home";');
        $router = $this->makeRouter();

        $this->assertSame('core-home', $this->routeCapture($router, 'GET', '/')->value);
        $this->assertNull($this->routeCapture($router, 'GET', '/blog'));
        $this->assertSame('blog-home', $this->routeCapture($router, 'GET', '/blogger')->value);
    }

    public function testModuleDynamicRoute(): void
    {
        $this->putFile('blog/routes/[slug].php', '<?php echo "post-{$slug}";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/blog/hello-world');
        $this->assertSame('post-hello-world', $res->value);
    }

    // ── Middleware ───────────────────────────────────────────

    public function testModuleMiddlewareRuns(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $this->putFile('core/middleware.php', '<?php return function($next, $req, $ctx, $router) { return "MW[" . $next() . "]"; };');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/');
        $this->assertSame('MW[ok]', $res->value);
    }

    public function testCoreMiddlewareRunsForOtherModules(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('core/middleware.php', '<?php return function($next, $req, $ctx, $router) { return "CORE[" . $next() . "]"; };');
        $this->putFile('blog/routes/index.php', '<?php echo "blog";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/blog');
        $this->assertSame('CORE[blog]', $res->value);
    }

    public function testBothMiddlewaresStack(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('core/middleware.php', '<?php return function($next, $req, $ctx, $router) { return "C[" . $next() . "]"; };');
        $this->putFile('api/middleware.php',  '<?php return function($next, $req, $ctx, $router) { return "A[" . $next() . "]"; };');
        $this->putFile('api/routes/index.php', '<?php echo "data";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/api');
        $this->assertSame('C[A[data]]', $res->value);
    }

    // ── Raw return value ─────────────────────────────────────

    public function testRouteReturnsRawValue(): void
    {
        $this->putFile('core/routes/data.php', '<?php return ["json" => ["ok" => true]];');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/data');
        $this->assertSame(['json' => ['ok' => true]], $res->value);
    }

    // ── resolve() ────────────────────────────────────────────

    public function testResolveFallsBackToCore(): void
    {
        $this->putFile('core/layout.php', '<?php // layout');
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();

        $res = $this->routeCapture($router, 'GET', '/blog');
        $this->assertSame('blog', $res->module);

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
        $this->assertNull($this->makeRouter()->resolve('nonexistent'));
    }

    // ── Result shape ─────────────────────────────────────────

    public function testResultContainsModule(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('blog/config.php', '<?php return ["mount" => "blogger"];');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();

        $this->assertSame('core', $this->routeCapture($router, 'GET', '/')->module);
        $this->assertSame('blog', $this->routeCapture($router, 'GET', '/blogger')->module);
    }

    public function testRequestHasPatternAndModuleAfterRoute(): void
    {
        $this->putFile('core/routes/users/[id].php', '<?php return [$req->pattern, $req->module];');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/users/7');
        $this->assertIsArray($res->value);
        $this->assertNotNull($res->value[0]);
        $this->assertSame('core', $res->value[1]);
    }

    // ── Cache ────────────────────────────────────────────────

    public function testCacheFile(): void
    {
        $cacheFile = "{$this->tmpDir}/_cache/routes.php";
        $this->putFile('core/routes/index.php', '<?php echo "cached";');

        Router::create($this->tmpDir, $cacheFile);
        $this->assertFileExists($cacheFile);

        // New route added after cache was written — should be invisible
        $this->putFile('core/routes/new-page.php', '<?php echo "new";');
        $router2 = Router::create($this->tmpDir, $cacheFile);

        $this->assertSame('cached', $this->routeCapture($router2, 'GET', '/')->value);
        $this->assertNull($this->routeCapture($router2, 'GET', '/new-page'));
    }

    // ── Edge cases ───────────────────────────────────────────

    public function testStaticRouteBeforeDynamic(): void
    {
        $this->putFile('core/routes/users/index.php', '<?php echo "list";');
        $this->putFile('core/routes/users/[id].php', '<?php echo "user-{$id}";');
        $this->putFile('core/routes/users/admin.php', '<?php echo "admin-page";');
        $router = $this->makeRouter();

        $this->assertSame('admin-page', $this->routeCapture($router, 'GET', '/users/admin')->value);
        $this->assertSame('user-42',    $this->routeCapture($router, 'GET', '/users/42')->value);
    }

    public function testCoreRouteWithNoMatchingModule(): void
    {
        $this->putFile('core/routes/foo/index.php', '<?php echo "foo-core";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/foo');
        $this->assertSame('foo-core', $res->value);
    }

    public function testModuleMountShadowsCoreRoute(): void
    {
        $this->putFile('core/routes/api/index.php', '<?php echo "core-api";');
        $this->putFile('api/routes/index.php', '<?php echo "module-api";');
        $res = $this->routeCapture($this->makeRouter(), 'GET', '/api');
        $this->assertSame('module-api', $res->value);
    }

    public function testBuildRequestDefaults(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/test';
        $_GET = ['q' => '1'];

        $req = \phorq\Request::fromGlobals();

        $this->assertSame('GET',        $req->method);
        $this->assertSame('test',       $req->path);
        $this->assertSame(['q' => '1'], $req->query);
    }

    public function testAmbiguousDynamicRouteFilesThrows(): void
    {
        $this->putFile('core/routes/[id].php',   '<?php echo "x";');
        $this->putFile('core/routes/[slug].php', '<?php echo "y";');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ambiguous dynamic route file/');
        $this->makeRouter();
    }

    public function testAmbiguousDynamicRouteDirsThrows(): void
    {
        $this->putFile('core/routes/[id]/index.php',   '<?php echo "x";');
        $this->putFile('core/routes/[slug]/index.php', '<?php echo "y";');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ambiguous route shape/');
        $this->makeRouter();
    }

    public function testAmbiguousCatchAllRouteFilesThrows(): void
    {
        $this->putFile('core/routes/[...foo].php', '<?php echo "x";');
        $this->putFile('core/routes/[...bar].php', '<?php echo "y";');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ambiguous dynamic route file/');
        $this->makeRouter();
    }

    public function testAmbiguousCatchAllRouteDirsThrows(): void
    {
        $this->putFile('core/routes/[...foo]/index.php', '<?php echo "x";');
        $this->putFile('core/routes/[...bar]/index.php', '<?php echo "y";');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ambiguous route shape/');
        $this->makeRouter();
    }

    public function testAmbiguousOptCatchAllRouteFilesThrows(): void
    {
        $this->putFile('core/routes/[[...foo]].php', '<?php echo "x";');
        $this->putFile('core/routes/[[...bar]].php', '<?php echo "y";');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ambiguous dynamic route file/');
        $this->makeRouter();
    }

    public function testAmbiguousOptCatchAllRouteDirsThrows(): void
    {
        $this->putFile('core/routes/[[...foo]]/index.php', '<?php echo "x";');
        $this->putFile('core/routes/[[...bar]]/index.php', '<?php echo "y";');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ambiguous route shape/');
        $this->makeRouter();
    }
}