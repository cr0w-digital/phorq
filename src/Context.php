<?php
declare(strict_types=1);

namespace phorq;

/**
 * Base context for phorq routing.
 *
 * Holds output wrappers that route handlers select by returning a single-key
 * array (e.g. `return ['json' => $data]`). Middleware can register wrappers
 * at runtime via the public `$wrap` array.
 *
 * Extend this class to add your own application services (logger, cache, etc.).
 * Simple apps can use it directly.
 */
class Context
{
    /**
     * Output wrappers keyed by type name.
     * Each value is a callable(mixed): mixed.
     *
     * @var array<string, callable(mixed): mixed>
     */
    public array $wrap = [];
}
