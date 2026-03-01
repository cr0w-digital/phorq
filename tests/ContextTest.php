<?php
declare(strict_types=1);

namespace phorq\Tests;

use PHPUnit\Framework\TestCase;
use phorq\Context;

class ContextTest extends TestCase
{
    public function testDefaultsAreEmpty(): void
    {
        $ctx = new Context();
        $this->assertSame([], $ctx->wrap);
        $this->assertSame([], $ctx->state);
    }

    public function testConstructorSetsWrapAndState(): void
    {
        $wrap = ['html' => fn(string $s) => "<html>{$s}</html>"];
        $state = ['user' => 'Alice'];

        $ctx = new Context($wrap, $state);

        $this->assertArrayHasKey('html', $ctx->wrap);
        $this->assertSame('Alice', $ctx->state['user']);
        $this->assertSame('<html>hi</html>', ($ctx->wrap['html'])('hi'));
    }
}
