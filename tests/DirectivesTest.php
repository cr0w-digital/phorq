<?php

declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;

class DirectivesTest extends TestCase
{
    protected function setUp(): void
    {
        \phorq\directives()->reset();
    }

    protected function tearDown(): void
    {
        \phorq\directives()->reset();
    }

    // ── DirectiveStack ────────────────────────────────────────

    public function testPushAndSweep(): void
    {
        $stack = \phorq\directives();
        $stack->push('html', ['content' => 'hi', 'code' => 200]);
        $swept = $stack->sweep();

        $this->assertCount(1, $swept);
        $this->assertSame('html', $swept[0]['type']);
        $this->assertSame('hi', $swept[0]['payload']['content']);
    }

    public function testSweepClearsStack(): void
    {
        $stack = \phorq\directives();
        $stack->push('html', ['content' => 'x', 'code' => 200]);
        $stack->sweep();
        $this->assertSame([], $stack->sweep());
    }

    public function testReset(): void
    {
        $stack = \phorq\directives();
        $stack->push('html', ['content' => 'x', 'code' => 200]);
        $stack->reset();
        $this->assertSame([], $stack->sweep());
    }

    // ── Core directives ───────────────────────────────────────

    public function testHtmlPushesDirective(): void
    {
        $d = html('<p>hello</p>');
        $this->assertSame('html', $d[0]['type']);
        $this->assertSame('<p>hello</p>', $d[0]['payload']['content']);
        $this->assertSame(200, $d[0]['payload']['code']);
    }

    public function testHtmlWithCustomCode(): void
    {
        $d = html('<p>error</p>', 422);
        $this->assertSame(422, $d[0]['payload']['code']);
    }

    public function testJsonReturnsSweptDirectives(): void
    {
        $result = json(['ok' => true]);
        $this->assertIsArray($result);
        $this->assertSame('json', $result[0]['type']);
        $this->assertSame(['ok' => true], $result[0]['payload']['data']);
        $this->assertSame([], \phorq\directives()->sweep()); // swept
    }

    public function testJsonDefaultCode(): void
    {
        $result = json(['ok' => true]);
        $this->assertSame(200, $result[0]['payload']['code']);
    }

    public function testJsonCustomCode(): void
    {
        $result = json(['error' => 'bad'], 422);
        $this->assertSame(422, $result[0]['payload']['code']);
    }

    public function testRedirectReturnsSweptDirectives(): void
    {
        $result = redirect('/login');
        $this->assertIsArray($result);
        $this->assertSame('redirect', $result[0]['type']);
        $this->assertSame('/login', $result[0]['payload']['url']);
        $this->assertSame(302, $result[0]['payload']['code']);
        $this->assertSame([], \phorq\directives()->sweep());
    }

    public function testRedirectCustomCode(): void
    {
        $result = redirect('/new', 301);
        $this->assertSame(301, $result[0]['payload']['code']);
    }

    public function testErrorReturnsSweptDirectives(): void
    {
        $result = error(404);
        $this->assertIsArray($result);
        $this->assertSame('error', $result[0]['type']);
        $this->assertSame(404, $result[0]['payload']['code']);
        $this->assertNull($result[0]['payload']['content']);
        $this->assertSame([], \phorq\directives()->sweep());
    }

    public function testErrorWithContent(): void
    {
        $result = error(422, '<p>invalid</p>');
        $this->assertSame('<p>invalid</p>', $result[0]['payload']['content']);
    }

    public function testJsonSweepsAccumulatedDirectives(): void
    {
        // modifier directives pushed before json() should be swept too
        trigger('foo');
        $result = json(['ok' => true]);
        $this->assertCount(2, $result);
        $this->assertSame('htmx:trigger', $result[0]['type']);
        $this->assertSame('json', $result[1]['type']);
    }

    public function testPublishPushesDirective(): void
    {
        $ev = event('tick', ['t' => 1]);
        publish('/topics/foo', $ev);
        $d = \phorq\directives()->sweep();
        $this->assertSame('publish', $d[0]['type']);
        $this->assertSame('/topics/foo', $d[0]['payload']['topic']);
        $this->assertSame($ev, $d[0]['payload']['event']);
    }

    public function testSubscribePushesDirective(): void
    {
        $d = subscribe('/topics/prices');
        $this->assertSame('subscribe', $d[0]['type']);
        $this->assertSame('/topics/prices', $d[0]['payload']['topic']);
    }

    // ── HTMX directives ───────────────────────────────────────

    public function testTriggerPushesDirective(): void
    {
        trigger('toast', ['message' => 'Saved!']);
        $d = \phorq\directives()->sweep();
        $this->assertSame('htmx:trigger', $d[0]['type']);
        $this->assertSame('toast', $d[0]['payload']['event']);
        $this->assertSame(['message' => 'Saved!'], $d[0]['payload']['data']);
        $this->assertSame('default', $d[0]['payload']['timing']);
    }

    public function testOnSwapUsesSwapTiming(): void
    {
        on_swap('refresh');
        $d = \phorq\directives()->sweep();
        $this->assertSame('swap', $d[0]['payload']['timing']);
    }

    public function testOnSettleUsesSettleTiming(): void
    {
        on_settle('done');
        $d = \phorq\directives()->sweep();
        $this->assertSame('settle', $d[0]['payload']['timing']);
    }

    public function testPushUrlPushesDirective(): void
    {
        push_url('/users/42');
        $d = \phorq\directives()->sweep();
        $this->assertSame('htmx:push_url', $d[0]['type']);
        $this->assertSame('/users/42', $d[0]['payload']);
    }

    public function testReplaceUrlPushesDirective(): void
    {
        replace_url('/search?q=foo');
        $d = \phorq\directives()->sweep();
        $this->assertSame('htmx:replace_url', $d[0]['type']);
        $this->assertSame('/search?q=foo', $d[0]['payload']);
    }

    public function testTitlePushesDirective(): void
    {
        title('My Page');
        $d = \phorq\directives()->sweep();
        $this->assertSame('title', $d[0]['type']);
        $this->assertSame('My Page', $d[0]['payload']);
    }

    public function testOobPushesDirective(): void
    {
        oob('#notifications', '<span>3</span>');
        $d = \phorq\directives()->sweep();
        $this->assertSame('htmx:oob', $d[0]['type']);
        $this->assertSame('#notifications', $d[0]['payload']['selector']);
        $this->assertSame('<span>3</span>', $d[0]['payload']['html']);
        $this->assertSame('innerHTML', $d[0]['payload']['swap']);
    }

    public function testSwapPushesRetargetAndHtml(): void
    {
        $d = swap('#errors', '<p>bad</p>', 'outerHTML');
        $this->assertSame('htmx:retarget', $d[0]['type']);
        $this->assertSame('#errors', $d[0]['payload']['selector']);
        $this->assertSame('outerHTML', $d[0]['payload']['mode']);
        $this->assertSame('html', $d[1]['type']);
    }
}