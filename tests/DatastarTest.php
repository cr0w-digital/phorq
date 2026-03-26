<?php

declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;

class DatastarTest extends TestCase
{
    // ── patch_elements ────────────────────────────────────────

    public function testPatchElementsEventName(): void
    {
        $ev = \phorq\datastar\elements('<div id="x">hi</div>');
        $this->assertStringContainsString('event: datastar-patch-elements', $ev->encode());
    }

    public function testPatchElementsHtmlLine(): void
    {
        $ev = \phorq\datastar\elements('<div id="x">hi</div>');
        $this->assertStringContainsString('elements <div id="x">hi</div>', $ev->encode());
    }

    public function testPatchElementsHasDataPrefix(): void
    {
        $ev = \phorq\datastar\elements('<div id="x">hi</div>');
        $this->assertStringContainsString('data: elements <div id="x">hi</div>', $ev->encode());
    }

    public function testPatchElementsWithMode(): void
    {
        $ev = \phorq\datastar\elements('<li>item</li>', mode: 'append');
        $this->assertStringContainsString('mode append', $ev->encode());
    }

    public function testPatchElementsDefaultModeOmitted(): void
    {
        $ev = \phorq\datastar\elements('<div id="x">hi</div>');
        $this->assertStringNotContainsString('mode morph', $ev->encode());
    }

    public function testPatchElementsWithSelector(): void
    {
        $ev = \phorq\datastar\elements('<p>new</p>', selector: '#target');
        $this->assertStringContainsString('selector #target', $ev->encode());
    }

    public function testPatchElementsWithViewTransition(): void
    {
        $ev = \phorq\datastar\elements('<div id="x">hi</div>', useViewTransition: true);
        $this->assertStringContainsString('useViewTransition true', $ev->encode());
    }

    public function testPatchElementsViewTransitionOmittedWhenFalse(): void
    {
        $ev = \phorq\datastar\elements('<div id="x">hi</div>');
        $this->assertStringNotContainsString('useViewTransition', $ev->encode());
    }

    public function testPatchElementsIsRawSseEvent(): void
    {
        $ev = \phorq\datastar\elements('<div id="x">hi</div>');
        $this->assertTrue($ev->raw);
    }

    // ── patch_signals ─────────────────────────────────────────

    public function testPatchSignalsEventName(): void
    {
        $ev = \phorq\datastar\signals(['count' => 1]);
        $this->assertStringContainsString('event: datastar-patch-signals', $ev->encode());
    }

    public function testPatchSignalsJsonPayload(): void
    {
        $ev = \phorq\datastar\signals(['count' => 1, 'name' => 'Alice']);
        $this->assertStringContainsString('signals {"count":1,"name":"Alice"}', $ev->encode());
    }

    public function testPatchSignalsHasDataPrefix(): void
    {
        $ev = \phorq\datastar\signals(['x' => 1]);
        $this->assertStringContainsString('data: signals {"x":1}', $ev->encode());
    }

    public function testPatchSignalsOnlyIfMissing(): void
    {
        $ev = \phorq\datastar\signals(['x' => 1], onlyIfMissing: true);
        $this->assertStringContainsString('onlyIfMissing true', $ev->encode());
    }

    public function testPatchSignalsOnlyIfMissingOmittedWhenFalse(): void
    {
        $ev = \phorq\datastar\signals(['x' => 1]);
        $this->assertStringNotContainsString('onlyIfMissing', $ev->encode());
    }

    // ── execute_script ────────────────────────────────────────

    public function testExecuteScriptEventName(): void
    {
        $ev = \phorq\datastar\script("console.log('hi')");
        $this->assertStringContainsString('event: datastar-execute-script', $ev->encode());
    }

    public function testExecuteScriptLine(): void
    {
        $ev = \phorq\datastar\script("console.log('hi')");
        $this->assertStringContainsString("script console.log('hi')", $ev->encode());
    }

    public function testExecuteScriptMultiline(): void
    {
        $ev = \phorq\datastar\script("const x = 1;\nconsole.log(x)");
        $encoded = $ev->encode();
        $this->assertStringContainsString('script const x = 1;', $encoded);
        $this->assertStringContainsString('script console.log(x)', $encoded);
    }

    public function testExecuteScriptHasDataPrefix(): void
    {
        $ev = \phorq\datastar\script('alert(1)');
        $this->assertStringContainsString('data: script alert(1)', $ev->encode());
    }
}