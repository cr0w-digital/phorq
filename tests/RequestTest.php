<?php

declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;
use phorq\Request;

class RequestTest extends TestCase
{
    // ── Basic ─────────────────────────────────────────────────

    public function testMethodIsUppercased(): void
    {
        $req = new Request('get', 'foo');
        $this->assertSame('GET', $req->method);
    }

    public function testPathIsStripped(): void
    {
        $req = new Request('GET', '/foo/bar/');
        $this->assertSame('foo/bar', $req->path);
    }

    public function testHeadersNormalized(): void
    {
        $req = new Request('GET', '', headers: ['X_FORWARDED_PROTO' => 'https']);
        $this->assertSame('https', $req->header('x-forwarded-proto'));
    }

    public function testHeaderCaseInsensitive(): void
    {
        $req = new Request('GET', '', headers: ['content-type' => 'application/json']);
        $this->assertSame('application/json', $req->header('Content-Type'));
    }

    public function testHeaderDefaultNull(): void
    {
        $req = new Request('GET', '');
        $this->assertNull($req->header('x-missing'));
        $this->assertSame('fallback', $req->header('x-missing', 'fallback'));
    }

    // ── Method checks ─────────────────────────────────────────

    public function testMethodChecks(): void
    {
        $this->assertTrue((new Request('GET',    ''))->isGet());
        $this->assertTrue((new Request('POST',   ''))->isPost());
        $this->assertTrue((new Request('PUT',    ''))->isPut());
        $this->assertTrue((new Request('PATCH',  ''))->isPatch());
        $this->assertTrue((new Request('DELETE', ''))->isDelete());
        $this->assertFalse((new Request('GET',   ''))->isPost());
    }

    // ── Input ─────────────────────────────────────────────────

    public function testStringAccessor(): void
    {
        $req = new Request('GET', '', query: ['q' => '  hello  ']);
        $this->assertSame('hello', $req->string('q'));
    }

    public function testStringDefault(): void
    {
        $req = new Request('GET', '');
        $this->assertSame('fallback', $req->string('missing', 'fallback'));
    }

    public function testIntAccessor(): void
    {
        $req = new Request('GET', '', query: ['page' => '3']);
        $this->assertSame(3, $req->int('page'));
    }

    public function testIntDefault(): void
    {
        $req = new Request('GET', '');
        $this->assertSame(1, $req->int('page', 1));
    }

    public function testFloatAccessor(): void
    {
        $req = new Request('GET', '', query: ['price' => '9.99']);
        $this->assertSame(9.99, $req->float('price'));
    }

    public function testBoolAccessor(): void
    {
        $req = new Request('GET', '', query: ['active' => 'true']);
        $this->assertTrue($req->bool('active'));
    }

    public function testBoolFalseValue(): void
    {
        $req = new Request('GET', '', query: ['active' => 'false']);
        $this->assertFalse($req->bool('active'));
    }

    public function testHasKey(): void
    {
        $req = new Request('GET', '', query: ['q' => 'foo']);
        $this->assertTrue($req->has('q'));
        $this->assertFalse($req->has('missing'));
    }

    public function testInputPreferredOverQuery(): void
    {
        $req = new Request('POST', '', query: ['key' => 'query'], input: ['key' => 'input']);
        $this->assertSame('input', $req->string('key'));
    }

    // ── isJson / isSecure ────────────────────────────────────

    public function testIsJson(): void
    {
        $req = new Request('POST', '', headers: ['content-type' => 'application/json']);
        $this->assertTrue($req->isJson());
    }

    public function testIsSecureViaHeader(): void
    {
        $req = new Request('GET', '', headers: ['x-forwarded-proto' => 'https']);
        $this->assertTrue($req->isSecure());
    }

    // ── HTMX ─────────────────────────────────────────────────

    public function testIsHtmx(): void
    {
        $plain = new Request('GET', '');
        $htmx  = new Request('GET', '', headers: ['hx-request' => 'true']);
        $this->assertFalse($plain->isHtmx());
        $this->assertTrue($htmx->isHtmx());
    }

    public function testIsBoosted(): void
    {
        $req = new Request('GET', '', headers: ['hx-boosted' => 'true']);
        $this->assertTrue($req->isBoosted());
    }

    public function testTarget(): void
    {
        $req = new Request('GET', '', headers: ['hx-target' => '#main']);
        $this->assertSame('#main', $req->target());
        $this->assertNull((new Request('GET', ''))->target());
    }

    public function testTrigger(): void
    {
        $req = new Request('GET', '', headers: ['hx-trigger' => 'btn-save']);
        $this->assertSame('btn-save', $req->trigger());
    }

    public function testTriggerName(): void
    {
        $req = new Request('GET', '', headers: ['hx-trigger-name' => 'save']);
        $this->assertSame('save', $req->triggerName());
    }

    // ── Datastar ─────────────────────────────────────────────

    public function testIsDatastar(): void
    {
        $plain = new Request('GET', '');
        $ds    = new Request('GET', '', headers: ['datastar-request' => 'true']);
        $this->assertFalse($plain->isDatastar());
        $this->assertTrue($ds->isDatastar());
    }

    public function testSignalsFromGetQuery(): void
    {
        $req = new Request('GET', '', query: ['datastar' => '{"count":5}']);
        $this->assertSame(['count' => 5], $req->signals());
    }

    public function testSignalsFromPostBody(): void
    {
        $req = new Request('POST', '', input: ['count' => 42]);
        $this->assertSame(['count' => 42], $req->signals());
    }

    public function testSignalsEmptyWhenAbsent(): void
    {
        $req = new Request('GET', '');
        $this->assertSame([], $req->signals());
    }

    public function testSignalWithDefault(): void
    {
        $req = new Request('POST', '', input: ['count' => 7]);
        $this->assertSame(7, $req->signal('count', 0));
        $this->assertSame(0, $req->signal('missing', 0));
    }

    public function testSignalDotNotation(): void
    {
        $req = new Request('POST', '', input: ['user' => ['name' => 'Alice']]);
        $this->assertSame('Alice', $req->signal('user.name'));
        $this->assertNull($req->signal('user.missing'));
    }

    // ── withMatch ────────────────────────────────────────────

    public function testWithMatch(): void
    {
        $req     = new Request('GET', 'users/42');
        $matched = $req->withMatch('/core/users/[id]', 'core');
        $this->assertSame('/core/users/[id]', $matched->pattern);
        $this->assertSame('core', $matched->module);
        $this->assertSame('GET', $matched->method); // unchanged
    }
}