<?php

declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;
use phorq\Router;
use phorq\Request;
use phorq\Result;

/**
 * Integration tests for Router.
 *
 * Each test builds a temporary module tree on disk, creates a Router, and
 * asserts that route() returns the expected Result and directives.
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
        \phorq\directives()->reset();
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

    private function route(Router $router, string $method, string $path): Result
    {
        $req = new Request($method, trim($path, '/'));
        return $router->route(null, $req);
    }

    private function htmlContent(Result $result): string
    {
        $d = $result->first('html');
        return $d ? (string) $d['payload']['content'] : '';
    }

    private function htmlCode(Result $result): int
    {
        $d = $result->first('html');
        return $d ? (int) ($d['payload']['code'] ?? 200) : 0;
    }

    // ── Basic routing ────────────────────────────────────────

    public function testCoreIndexRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "home";');
        $res = $this->route($this->makeRouter(), 'GET', '/');
        $this->assertTrue($res->has('html'));
        $this->assertSame('home', $this->htmlContent($res));
    }

    public function testStaticSegmentRoute(): void
    {
        $this->putFile('core/routes/about.php', '<?php echo "about-page";');
        $res = $this->route($this->makeRouter(), 'GET', '/about');
        $this->assertTrue($res->has('html'));
        $this->assertSame('about-page', $this->htmlContent($res));
    }

    public function testSubdirectoryIndexRoute(): void
    {
        $this->putFile('core/routes/users/index.php', '<?php echo "users-list";');
        $res = $this->route($this->makeRouter(), 'GET', '/users');
        $this->assertTrue($res->has('html'));
        $this->assertSame('users-list', $this->htmlContent($res));
    }

    public function test404ForMissingRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "home";');
        $res = $this->route($this->makeRouter(), 'GET', '/nonexistent');
        $this->assertTrue($res->has('error'));
        $this->assertSame(404, $res->first('error')['payload']['code']);
    }

    // ── Return values ────────────────────────────────────────

    public function testReturnStringIsImplicitHtml(): void
    {
        $this->putFile('core/routes/hello.php', '<?php return "<b>hello</b>";');
        $res = $this->route($this->makeRouter(), 'GET', '/hello');
        $this->assertTrue($res->has('html'));
        $this->assertSame('<b>hello</b>', $this->htmlContent($res));
    }

    public function testEchoIsImplicitHtml(): void
    {
        $this->putFile('core/routes/hello.php', '<?php echo "echoed";');
        $res = $this->route($this->makeRouter(), 'GET', '/hello');
        $this->assertTrue($res->has('html'));
        $this->assertSame('echoed', $this->htmlContent($res));
    }

    public function testReturnJsonDirective(): void
    {
        $this->putFile('core/routes/api.php', '<?php return json(["ok" => true]);');
        $res = $this->route($this->makeRouter(), 'GET', '/api');
        $this->assertTrue($res->has('json'));
        $this->assertSame(['ok' => true], $res->first('json')['payload']['data']);
    }

    public function testReturnRedirectDirective(): void
    {
        $this->putFile('core/routes/old.php', '<?php return redirect("/new");');
        $res = $this->route($this->makeRouter(), 'GET', '/old');
        $this->assertTrue($res->has('redirect'));
        $this->assertSame('/new', $res->first('redirect')['payload']['url']);
    }

    public function testReturnErrorDirective(): void
    {
        $this->putFile('core/routes/secret.php', '<?php return error(403);');
        $res = $this->route($this->makeRouter(), 'GET', '/secret');
        $this->assertTrue($res->has('error'));
        $this->assertSame(403, $res->first('error')['payload']['code']);
    }

    public function testExplicitHtmlWithStatusCode(): void
    {
        $this->putFile('core/routes/created.php', '<?php return html("<p>created</p>", 201);');
        $res = $this->route($this->makeRouter(), 'GET', '/created');
        $this->assertTrue($res->has('html'));
        $this->assertSame(201, $this->htmlCode($res));
    }

    // ── Method branching ─────────────────────────────────────

    public function testMethodBranchingInsideRouteFile(): void
    {
        $this->putFile('core/routes/login.php',
            '<?php echo $req->isPost() ? "submit" : "form";');
        $router = $this->makeRouter();

        $this->assertSame('form',   $this->htmlContent($this->route($router, 'GET',  '/login')));
        $this->assertSame('submit', $this->htmlContent($this->route($router, 'POST', '/login')));
    }

    // ── Dynamic params ───────────────────────────────────────

    public function testDynamicParamFile(): void
    {
        $this->putFile('core/routes/users/[id].php', '<?php echo "user-{$id}";');
        $res = $this->route($this->makeRouter(), 'GET', '/users/42');
        $this->assertSame('user-42', $this->htmlContent($res));
    }

    public function testDynamicParamDirectory(): void
    {
        $this->putFile('core/routes/users/[id]/settings/index.php', '<?php echo "settings-{$id}";');
        $res = $this->route($this->makeRouter(), 'GET', '/users/99/settings');
        $this->assertSame('settings-99', $this->htmlContent($res));
    }

    // ── Catch-all routes ─────────────────────────────────────

    public function testCatchAllFile(): void
    {
        $this->putFile('core/routes/docs/[...rest].php', '<?php echo implode("/", $rest);');
        $res = $this->route($this->makeRouter(), 'GET', '/docs/a/b/c');
        $this->assertSame('a/b/c', $this->htmlContent($res));
    }

    public function testCatchAllDirectory(): void
    {
        $this->putFile('core/routes/files/[...path]/index.php', '<?php echo implode(",", $path);');
        $res = $this->route($this->makeRouter(), 'GET', '/files/x/y');
        $this->assertSame('x,y', $this->htmlContent($res));
    }

    public function testOptionalCatchAll(): void
    {
        $this->putFile('core/routes/pages/[[...path]]/index.php',
            '<?php echo $path ? implode("/", $path) : "root";');
        $router = $this->makeRouter();

        $this->assertSame('root',    $this->htmlContent($this->route($router, 'GET', '/pages')));
        $this->assertSame('foo/bar', $this->htmlContent($this->route($router, 'GET', '/pages/foo/bar')));
    }

    public function testOptionalCatchAllFile(): void
    {
        $this->putFile('core/routes/search/[[...terms]].php',
            '<?php echo $terms ? implode("+", $terms) : "empty";');
        $router = $this->makeRouter();

        $this->assertSame('empty',      $this->htmlContent($this->route($router, 'GET', '/search')));
        $this->assertSame('php+router', $this->htmlContent($this->route($router, 'GET', '/search/php/router')));
    }

    // ── Modules + mount ──────────────────────────────────────

    public function testModuleMountRoute(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "core-home";');
        $this->putFile('blog/config.php', '<?php return ["mount" => "blogger"];');
        $this->putFile('blog/routes/index.php', '<?php echo "blog-home";');
        $router = $this->makeRouter();

        $this->assertSame('core-home', $this->htmlContent($this->route($router, 'GET', '/')));
        $this->assertTrue($this->route($router, 'GET', '/blog')->has('error'));
        $this->assertSame('blog-home', $this->htmlContent($this->route($router, 'GET', '/blogger')));
    }

    public function testModuleDynamicRoute(): void
    {
        $this->putFile('blog/routes/[slug].php', '<?php echo "post-{$slug}";');
        $res = $this->route($this->makeRouter(), 'GET', '/blog/hello-world');
        $this->assertSame('post-hello-world', $this->htmlContent($res));
    }

    // ── Middleware ───────────────────────────────────────────

    public function testModuleMiddlewareRuns(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $this->putFile('core/middleware.php',
            '<?php return function(callable $next): array {
                $d = $next();
                // prepend a marker html directive
                array_unshift($d, ["type" => "html", "payload" => ["content" => "MW", "code" => 200]]);
                return $d;
            };');
        $res = $this->route($this->makeRouter(), 'GET', '/');
        $all = $res->all('html');
        $this->assertCount(2, $all);
        $this->assertSame('MW', $all[0]['payload']['content']);
        $this->assertSame('ok', $all[1]['payload']['content']);
    }

    public function testCoreMiddlewareRunsForOtherModules(): void
    {
        $this->putFile('core/middleware.php',
            '<?php return function(callable $next): array {
                $d = $next();
                array_unshift($d, ["type" => "html", "payload" => ["content" => "CORE", "code" => 200]]);
                return $d;
            };');
        $this->putFile('blog/routes/index.php', '<?php echo "blog";');
        $res  = $this->route($this->makeRouter(), 'GET', '/blog');
        $all  = $res->all('html');
        $this->assertSame('CORE', $all[0]['payload']['content']);
        $this->assertSame('blog', $all[1]['payload']['content']);
    }

    public function testMiddlewareShortCircuitWithRedirect(): void
    {
        $this->putFile('core/routes/secret.php', '<?php echo "secret";');
        $this->putFile('core/middleware.php',
            '<?php return function(callable $next): array {
                return redirect("/login");
            };');
        $res = $this->route($this->makeRouter(), 'GET', '/secret');
        $this->assertTrue($res->has('redirect'));
        $this->assertSame('/login', $res->first('redirect')['payload']['url']);
    }

    public function testBothMiddlewaresStack(): void
    {
        $this->putFile('core/middleware.php',
            '<?php return function(callable $next): array {
                $d = $next();
                array_unshift($d, ["type" => "html", "payload" => ["content" => "C", "code" => 200]]);
                return $d;
            };');
        $this->putFile('api/middleware.php',
            '<?php return function(callable $next): array {
                $d = $next();
                array_unshift($d, ["type" => "html", "payload" => ["content" => "A", "code" => 200]]);
                return $d;
            };');
        $this->putFile('api/routes/index.php', '<?php echo "data";');
        $res = $this->route($this->makeRouter(), 'GET', '/api');
        $all = $res->all('html');
        $this->assertSame('C',    $all[0]['payload']['content']);
        $this->assertSame('A',    $all[1]['payload']['content']);
        $this->assertSame('data', $all[2]['payload']['content']);
    }

    // ── Global middleware (use()) ─────────────────────────────

    public function testGlobalMiddlewareRuns(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $router = $this->makeRouter()
            ->use(function(callable $next): array {
                $d = $next();
                array_unshift($d, ['type' => 'html', 'payload' => ['content' => 'GLOBAL', 'code' => 200]]);
                return $d;
            });

        $all = $this->route($router, 'GET', '/')->all('html');
        $this->assertSame('GLOBAL', $all[0]['payload']['content']);
        $this->assertSame('ok',     $all[1]['payload']['content']);
    }

    public function testGlobalMiddlewareWrapsModuleMiddleware(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $this->putFile('core/middleware.php',
            '<?php return function(callable $next): array {
                $d = $next();
                array_unshift($d, ["type" => "html", "payload" => ["content" => "MOD", "code" => 200]]);
                return $d;
            };');

        $router = $this->makeRouter()
            ->use(function(callable $next): array {
                $d = $next();
                array_unshift($d, ['type' => 'html', 'payload' => ['content' => 'GLOBAL', 'code' => 200]]);
                return $d;
            });

        $all = $this->route($router, 'GET', '/')->all('html');
        $this->assertSame('GLOBAL', $all[0]['payload']['content']);
        $this->assertSame('MOD',    $all[1]['payload']['content']);
        $this->assertSame('ok',     $all[2]['payload']['content']);
    }

    public function testMultipleGlobalMiddlewareStack(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $router = $this->makeRouter()
            ->use(function(callable $next): array {
                $d = $next();
                array_unshift($d, ['type' => 'html', 'payload' => ['content' => 'A', 'code' => 200]]);
                return $d;
            })
            ->use(function(callable $next): array {
                $d = $next();
                array_unshift($d, ['type' => 'html', 'payload' => ['content' => 'B', 'code' => 200]]);
                return $d;
            });

        $all = $this->route($router, 'GET', '/')->all('html');
        $this->assertSame('A',  $all[0]['payload']['content']);
        $this->assertSame('B',  $all[1]['payload']['content']);
        $this->assertSame('ok', $all[2]['payload']['content']);
    }

    public function testGlobalMiddlewareReceivesRequest(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $captured = null;
        $router   = $this->makeRouter()
            ->use(function (callable $next, Request $req) use (&$captured): array {
                $captured = $req;
                return $next();
            });

        $this->route($router, 'GET', '/');
        $this->assertInstanceOf(Request::class, $captured);
        $this->assertSame('GET', $captured->method);
    }

    public function testMiddlewareMustReturnArray(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "ok";');
        $this->putFile('core/middleware.php',
            '<?php return function(callable $next): void { $next(); };');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Middleware must return an array/');
        $this->route($this->makeRouter(), 'GET', '/');
    }

    // ── Request ──────────────────────────────────────────────

    public function testRequestHasPatternAndModule(): void
    {
        $capturedReq = null;
        $this->putFile('core/routes/users/[id].php',
            '<?php \phorq\directives()->push("html", ["content" => $req->pattern . "|" . $req->module, "code" => 200]);');
        $res = $this->route($this->makeRouter(), 'GET', '/users/7');
        $this->assertStringContainsString('core', $this->htmlContent($res));
    }

    public function testRequestInput(): void
    {
        $this->putFile('core/routes/echo.php',
            '<?php echo $req->string("name", "nobody");');
        $req = new Request('GET', 'echo', ['name' => 'Alice']);
        $res = $this->makeRouter()->route(null, $req);
        $this->assertSame('Alice', $this->htmlContent($res));
    }

    public function testRequestHtmxHeaders(): void
    {
        $this->putFile('core/routes/partial.php',
            '<?php echo $req->isHtmx() ? "htmx" : "full";');
        $router = $this->makeRouter();

        $plain = new Request('GET', 'partial');
        $htmx  = new Request('GET', 'partial', headers: ['hx-request' => 'true']);

        $this->assertSame('full', $this->htmlContent($router->route(null, $plain)));
        $this->assertSame('htmx', $this->htmlContent($router->route(null, $htmx)));
    }

    public function testRequestDatastarSignals(): void
    {
        $this->putFile('core/routes/counter.php',
            '<?php echo $req->signal("count", 0);');
        $req = new Request('POST', 'counter', input: ['count' => 42]);
        $res = $this->makeRouter()->route(null, $req);
        $this->assertSame('42', $this->htmlContent($res));
    }

    // ── resolve() ────────────────────────────────────────────

    public function testResolveFallsBackToCore(): void
    {
        $this->putFile('core/layout.php', '<?php // layout');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();
        $res    = $this->route($router, 'GET', '/blog');

        $resolved = $router->resolve('layout', $res->module);
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('core/layout.php', $resolved);
    }

    public function testResolvePrefersActiveModule(): void
    {
        $this->putFile('core/layout.php', '<?php // core-layout');
        $this->putFile('blog/layout.php', '<?php // blog-layout');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();
        $res    = $this->route($router, 'GET', '/blog');

        $resolved = $router->resolve('layout', $res->module);
        $this->assertStringContainsString('blog/layout.php', $resolved);
        $this->assertStringNotContainsString('core/layout.php', $resolved);
    }

    public function testResolveReturnsNullForMissing(): void
    {
        $this->assertNull($this->makeRouter()->resolve('nonexistent'));
    }

    // ── Result shape ─────────────────────────────────────────

    public function testResultContainsModule(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $this->putFile('blog/config.php', '<?php return ["mount" => "blogger"];');
        $this->putFile('blog/routes/index.php', '<?php echo "y";');
        $router = $this->makeRouter();

        $this->assertSame('core', $this->route($router, 'GET', '/')->module);
        $this->assertSame('blog', $this->route($router, 'GET', '/blogger')->module);
    }

    public function testNotFoundResultHasErrorDirective(): void
    {
        $this->putFile('core/routes/index.php', '<?php echo "x";');
        $res = $this->route($this->makeRouter(), 'GET', '/missing');
        $this->assertTrue($res->has('error'));
        $this->assertSame(404, $res->first('error')['payload']['code']);
    }

    public function testResultHasAndAll(): void
    {
        $this->putFile('core/routes/multi.php',
            '<?php
            \phorq\directives()->push("html", ["content" => "first", "code" => 200]);
            \phorq\directives()->push("html", ["content" => "second", "code" => 200]);
            ');
        $res = $this->route($this->makeRouter(), 'GET', '/multi');
        $this->assertTrue($res->has('html'));
        $this->assertCount(2, $res->all('html'));
    }

    // ── Cache ────────────────────────────────────────────────

    public function testCacheFile(): void
    {
        $cacheFile = "{$this->tmpDir}/_cache/routes.php";
        $this->putFile('core/routes/index.php', '<?php echo "cached";');

        Router::create($this->tmpDir, $cacheFile);
        $this->assertFileExists($cacheFile);

        // New route added after cache — should be invisible
        $this->putFile('core/routes/new-page.php', '<?php echo "new";');
        $router2 = Router::create($this->tmpDir, $cacheFile);

        $this->assertSame('cached', $this->htmlContent($this->route($router2, 'GET', '/')));
        $this->assertTrue($this->route($router2, 'GET', '/new-page')->has('error'));
    }

    // ── Edge cases ───────────────────────────────────────────

    public function testStaticRouteBeforeDynamic(): void
    {
        $this->putFile('core/routes/users/index.php',  '<?php echo "list";');
        $this->putFile('core/routes/users/[id].php',   '<?php echo "user-{$id}";');
        $this->putFile('core/routes/users/admin.php',  '<?php echo "admin-page";');
        $router = $this->makeRouter();

        $this->assertSame('admin-page', $this->htmlContent($this->route($router, 'GET', '/users/admin')));
        $this->assertSame('user-42',    $this->htmlContent($this->route($router, 'GET', '/users/42')));
    }

    public function testCoreRouteFallback(): void
    {
        $this->putFile('core/routes/foo/index.php', '<?php echo "foo-core";');
        $res = $this->route($this->makeRouter(), 'GET', '/foo');
        $this->assertSame('foo-core', $this->htmlContent($res));
    }

    public function testModuleMountShadowsCoreRoute(): void
    {
        $this->putFile('core/routes/api/index.php', '<?php echo "core-api";');
        $this->putFile('api/routes/index.php', '<?php echo "module-api";');
        $res = $this->route($this->makeRouter(), 'GET', '/api');
        $this->assertSame('module-api', $this->htmlContent($res));
    }

    public function testContextPassedToRouteFile(): void
    {
        $this->putFile('core/routes/ctx.php', '<?php echo $ctx->name;');
        $ctx = new \stdClass();
        $ctx->name = 'phorq';
        $req = new Request('GET', 'ctx');
        $res = $this->makeRouter()->route($ctx, $req);
        $this->assertSame('phorq', $this->htmlContent($res));
    }

    // ── Ambiguity detection ───────────────────────────────────

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