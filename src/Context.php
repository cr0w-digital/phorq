<?php
declare(strict_types=1);

namespace phorq;

/**
 * Simple request context container.
 *
 * Holds type-specific wrappers (html, json, etc.) and arbitrary shared state
 * that middleware and route handlers can read/write.
 */
class Context
{
    /**
     * Output wrappers keyed by type name.
     * Each value is a callable(mixed): string.
     *
     * @var array<string, callable(mixed): string>
     */
    public array $wrap = [];

    /**
     * Arbitrary shared state for middleware / handlers.
     *
     * @var array<string, mixed>
     */
    public array $state = [];

    public function __construct(array $wrap = [], array $state = [])
    {
        $this->wrap  = $wrap;
        $this->state = $state;
    }
}
