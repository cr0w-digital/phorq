<?php

declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;
use phorq\Request;
use phorq\Result;
use phorq\ResponseEmitter;

/**
 * Spy emitter — records all calls for assertion.
 */
final class SpyEmitter implements ResponseEmitter
{
    public int    $statusCode  = 200;
    public array  $headers     = [];
    public string $body        = '';
    public array  $written     = [];
    public bool   $closed      = false;
    public bool   $connected   = true;

    public function status(int $code): void       { $this->statusCode = $code; }
    public function header(string $n, string $v): void { $this->headers[$n] = $v; }
    public function body(string $content): void   { $this->body = $content; }
    public function write(string $content): bool  { $this->written[] = $content; return $this->connected; }
    public function isConnected(): bool           { return $this->connected; }
    public function close(): void                 { $this->closed = true; }
}

class DispatchTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phorq_dispatch_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        \phorq\directives()->reset();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
        \phorq\directives()->reset();
    }

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

    private function putFile(string $rel, string $content): void
    {
        $abs = "{$this->tmpDir}/{$rel}";
        if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, $content);
    }

    private function makeResult(array $directives, string $module = 'core'): Result
    {
        return new Result($module, $directives);
    }

    private function resolve(?string $file = null): callable
    {
        return fn(string $name) => $file;
    }

    private function resolveFrom(string $dir): callable
    {
        return function(string $name) use ($dir): ?string {
            $path = "{$dir}/{$name}.php";
            return is_file($path) ? $path : null;
        };
    }

    // ── emit_html — layout wrapping ───────────────────────────

    public function testFullRequestWrapsInLayout(): void
    {
        $this->putFile('layout.php', '<html><?= $content ?></html>');
 
        $result  = $this->makeResult([['type' => 'html', 'payload' => ['content' => '<p>body</p>', 'code' => 200]]]);
        $emitter = new SpyEmitter();
        $req     = new Request('GET', 'page');
 
        \phorq\dispatch($result, $this->resolveFrom($this->tmpDir), $req, $emitter);
 
        $this->assertSame('<html><p>body</p></html>', $emitter->body);
    }
 
    public function testFullRequestWithNoLayoutRendersDirectly(): void
    {
        $result  = $this->makeResult([['type' => 'html', 'payload' => ['content' => '<p>body</p>', 'code' => 200]]]);
        $emitter = new SpyEmitter();
        $req     = new Request('GET', 'page');

        \phorq\dispatch($result, $this->resolve(), $req, $emitter);

        $this->assertSame('<p>body</p>', $emitter->body);
    }

    public function testHtmxRequestSkipsLayout(): void
    {
        $this->putFile('layout.php',
            '<?php return fn(string $c): string => "<html>{$c}</html>";');

        $result  = $this->makeResult([['type' => 'html', 'payload' => ['content' => '<p>fragment</p>', 'code' => 200]]]);
        $emitter = new SpyEmitter();
        $req     = new Request('GET', 'page', headers: ['hx-request' => 'true']);

        \phorq\dispatch($result, $this->resolveFrom($this->tmpDir), $req, $emitter);

        $this->assertSame('<p>fragment</p>', $emitter->body);
        $this->assertStringNotContainsString('<html>', $emitter->body);
    }

    public function testDatastarRequestSkipsLayout(): void
    {
        $this->putFile('layout.php',
            '<?php return fn(string $c): string => "<html>{$c}</html>";');

        $result  = $this->makeResult([['type' => 'html', 'payload' => ['content' => '<p>ds</p>', 'code' => 200]]]);
        $emitter = new SpyEmitter();
        $req     = new Request('GET', 'page', headers: ['datastar-request' => 'true']);

        \phorq\dispatch($result, $this->resolveFrom($this->tmpDir), $req, $emitter);

        $this->assertSame('<p>ds</p>', $emitter->body);
        $this->assertStringNotContainsString('<html>', $emitter->body);
    }

    public function testHtmlStatusCode(): void
    {
        $result  = $this->makeResult([['type' => 'html', 'payload' => ['content' => '', 'code' => 201]]]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame(201, $emitter->statusCode);
    }

    // ── emit_json ────────────────────────────────────────────

    public function testJsonResponse(): void
    {
        $result  = $this->makeResult([['type' => 'json', 'payload' => ['data' => ['ok' => true], 'code' => 200]]]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame('application/json', $emitter->headers['Content-Type']);
        $this->assertSame('{"ok":true}', $emitter->body);
        $this->assertSame(200, $emitter->statusCode);
    }

    public function testJsonCustomStatusCode(): void
    {
        $result  = $this->makeResult([['type' => 'json', 'payload' => ['data' => [], 'code' => 422]]]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame(422, $emitter->statusCode);
    }

    // ── emit_redirect ─────────────────────────────────────────

    public function testPlainRedirect(): void
    {
        $result  = $this->makeResult([['type' => 'redirect', 'payload' => ['url' => '/login', 'code' => 302]]]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame(302, $emitter->statusCode);
        $this->assertSame('/login', $emitter->headers['Location']);
    }

    public function testHtmxRedirectUsesHxHeader(): void
    {
        $result  = $this->makeResult([['type' => 'redirect', 'payload' => ['url' => '/login', 'code' => 302]]]);
        $emitter = new SpyEmitter();
        $req     = new Request('GET', '', headers: ['hx-request' => 'true']);

        \phorq\dispatch($result, $this->resolve(), $req, $emitter);

        $this->assertArrayHasKey('HX-Redirect', $emitter->headers);
        $this->assertSame('/login', $emitter->headers['HX-Redirect']);
        $this->assertArrayNotHasKey('Location', $emitter->headers);
    }

    public function testPermanentRedirect(): void
    {
        $result  = $this->makeResult([['type' => 'redirect', 'payload' => ['url' => '/new', 'code' => 301]]]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame(301, $emitter->statusCode);
    }

    // ── emit_error ────────────────────────────────────────────

    public function testErrorWithContent(): void
    {
        $result  = $this->makeResult([['type' => 'error', 'payload' => ['code' => 422, 'content' => '<p>invalid</p>']]]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame(422, $emitter->statusCode);
        $this->assertSame('<p>invalid</p>', $emitter->body);
    }

    public function testErrorResolvesView(): void
    {
        $this->putFile('errors/404.php', '<?php echo "custom " . $code;');
 
        $result  = $this->makeResult([['type' => 'error', 'payload' => ['code' => 404, 'content' => null]]]);
        $emitter = new SpyEmitter();
 
        \phorq\dispatch($result, $this->resolveFrom($this->tmpDir), new Request('GET', ''), $emitter);
 
        $this->assertSame(404, $emitter->statusCode);
        $this->assertSame('custom 404', $emitter->body);
    }

    public function testErrorFallsBackToDefaultMessage(): void
    {
        $result  = $this->makeResult([['type' => 'error', 'payload' => ['code' => 404, 'content' => null]]]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame(404, $emitter->statusCode);
        $this->assertStringContainsString('Not Found', $emitter->body);
    }

    public function testEmptyDirectivesResultsIn404(): void
    {
        $result  = $this->makeResult([]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame(404, $emitter->statusCode);
    }

    // ── title -───────────────────────────────────────────────

    public function testTitleInjectedIntoLayoutScope(): void
    {
        $this->putFile('layout.php', '<?php echo "<title>{$title}</title>" . $content;');
 
        $result = $this->makeResult([
            ['type' => 'title', 'payload' => 'My Page'],
            ['type' => 'html',  'payload' => ['content' => '<p>body</p>', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();
 
        \phorq\dispatch($result, $this->resolveFrom($this->tmpDir), new Request('GET', ''), $emitter);
 
        $this->assertStringContainsString('<title>My Page</title>', $emitter->body);
        $this->assertStringContainsString('<p>body</p>', $emitter->body);
    }
 
    public function testTitleEmptyStringWhenNotSet(): void
    {
        $this->putFile('layout.php', '<?php echo "<title>{$title}</title>" . $content;');
 
        $result  = $this->makeResult([['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]]]);
        $emitter = new SpyEmitter();
 
        \phorq\dispatch($result, $this->resolveFrom($this->tmpDir), new Request('GET', ''), $emitter);
 
        $this->assertStringContainsString('<title></title>', $emitter->body);
    }
 
    public function testTitleAppendedToBody(): void
    {
        $result = $this->makeResult([
            ['type' => 'title', 'payload' => 'My Page'],
            ['type' => 'html', 'payload' => ['content' => '<p>content</p>', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();
        $req     = new Request('GET', '', headers: ['hx-request' => 'true']);
 
        \phorq\dispatch($result, $this->resolve(), $req, $emitter);
 
        $this->assertStringContainsString('<title hx-swap-oob="true">My Page</title>', $emitter->body);
        $this->assertStringContainsString('<p>content</p>', $emitter->body);
    }

    // ── HTMX headers ─────────────────────────────────────────

    public function testHtmxTriggerHeader(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:trigger', 'payload' => ['event' => 'toast', 'data' => null, 'timing' => 'default']],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertArrayHasKey('HX-Trigger', $emitter->headers);
        $this->assertSame('toast', $emitter->headers['HX-Trigger']);
    }

    public function testHtmxTriggerWithData(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:trigger', 'payload' => ['event' => 'toast', 'data' => ['message' => 'Saved!'], 'timing' => 'default']],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertStringContainsString('toast', $emitter->headers['HX-Trigger']);
        $this->assertStringContainsString('Saved!', $emitter->headers['HX-Trigger']);
    }

    public function testHtmxTriggerAfterSwap(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:trigger', 'payload' => ['event' => 'refresh', 'data' => null, 'timing' => 'swap']],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertArrayHasKey('HX-Trigger-After-Swap', $emitter->headers);
    }

    public function testHtmxTriggerAfterSettle(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:trigger', 'payload' => ['event' => 'done', 'data' => null, 'timing' => 'settle']],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertArrayHasKey('HX-Trigger-After-Settle', $emitter->headers);
    }

    public function testHtmxPushUrl(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:push_url', 'payload' => '/users/42'],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame('/users/42', $emitter->headers['HX-Push-Url']);
    }

    public function testHtmxReplaceUrl(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:replace_url', 'payload' => '/search?q=foo'],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame('/search?q=foo', $emitter->headers['HX-Replace-Url']);
    }

    public function testHtmxRetarget(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:retarget', 'payload' => ['selector' => '#errors', 'mode' => 'innerHTML']],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame('#errors', $emitter->headers['HX-Retarget']);
        $this->assertArrayNotHasKey('HX-Reswap', $emitter->headers);
    }

    public function testHtmxRetargetWithReswap(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:retarget', 'payload' => ['selector' => '#errors', 'mode' => 'outerHTML']],
            ['type' => 'html', 'payload' => ['content' => 'ok', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        $this->assertSame('outerHTML', $emitter->headers['HX-Reswap']);
    }

    // ── HTMX body extras ─────────────────────────────────────

    public function testHtmxOobAppendedToBody(): void
    {
        $result = $this->makeResult([
            ['type' => 'htmx:oob', 'payload' => ['selector' => '#badge', 'html' => '<span>3</span>', 'swap' => 'innerHTML']],
            ['type' => 'html', 'payload' => ['content' => '<p>main</p>', 'code' => 200]],
        ]);
        $emitter = new SpyEmitter();
        $req     = new Request('GET', '', headers: ['hx-request' => 'true']);

        \phorq\dispatch($result, $this->resolve(), $req, $emitter);

        $this->assertStringContainsString('hx-swap-oob="innerHTML"', $emitter->body);
        $this->assertStringContainsString('<span>3</span>', $emitter->body);
        $this->assertStringContainsString('<p>main</p>', $emitter->body);
    }

    // ── Last directive wins ───────────────────────────────────

    public function testLastPrimaryDirectiveWins(): void
    {
        $result = $this->makeResult([
            ['type' => 'html',     'payload' => ['content' => 'first', 'code' => 200]],
            ['type' => 'redirect', 'payload' => ['url' => '/login', 'code' => 302]],
        ]);
        $emitter = new SpyEmitter();

        \phorq\dispatch($result, $this->resolve(), new Request('GET', ''), $emitter);

        // redirect is last primary — wins
        $this->assertArrayHasKey('Location', $emitter->headers);
        $this->assertSame('', $emitter->body);
    }
}