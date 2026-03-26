<?php

declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;
use phorq\SseEvent;

class SseEventTest extends TestCase
{
    // ── comment ───────────────────────────────────────────────

    public function testCommentEncodes(): void
    {
        $ev = SseEvent::comment('keepalive');
        $this->assertSame(": keepalive\n\n", $ev->encode());
    }

    public function testCommentFactory(): void
    {
        $ev = comment('ping');
        $this->assertSame(": ping\n\n", $ev->encode());
    }

    // ── event() helper ────────────────────────────────────────

    public function testUnnamedEventWithStringData(): void
    {
        $ev = event(null, 'hello');
        $this->assertSame("data: hello\n\n", $ev->encode());
    }

    public function testNamedEventWithStringData(): void
    {
        $ev = event('tick', 'hello');
        $this->assertSame("event: tick\ndata: hello\n\n", $ev->encode());
    }

    public function testNamedEventWithArrayData(): void
    {
        $ev = event('price', ['btc' => 42]);
        $this->assertSame('event: price' . "\n" . 'data: {"btc":42}' . "\n\n", $ev->encode());
    }

    public function testEventWithId(): void
    {
        $ev = event('tick', 'x', id: '123');
        $this->assertStringContainsString("id: 123\n", $ev->encode());
        $this->assertStringContainsString("event: tick\n", $ev->encode());
    }

    public function testMultilineDataGetsMultipleDataLines(): void
    {
        $ev = event(null, "line1\nline2");
        $this->assertSame("data: line1\ndata: line2\n\n", $ev->encode());
    }

    // ── raw mode (Datastar) ───────────────────────────────────

    public function testRawModeHasDataPrefix(): void
    {
        $ev = new SseEvent(
            name: 'datastar-patch-signals',
            data: 'signals {"count":1}',
            raw:  true,
        );
        $encoded = $ev->encode();
        $this->assertSame("event: datastar-patch-signals\ndata: signals {\"count\":1}\n\n", $encoded);
    }

    public function testRawModeWithMultipleLines(): void
    {
        $ev = new SseEvent(
            name: 'datastar-patch-elements',
            data: "elements <div id=\"x\">hello</div>\nmode morph",
            raw:  true,
        );
        $encoded = $ev->encode();
        $this->assertStringContainsString("data: elements <div id=\"x\">hello</div>", $encoded);
        $this->assertStringContainsString("data: mode morph", $encoded);
    }
}