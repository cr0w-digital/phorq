<?php

declare(strict_types=1);

namespace phorq;

/**
 * Immutable value object returned by Router::route() on a successful match.
 *
 * Carries the module that handled the request and the directives accumulated
 * during the middleware/route chain — swept from \phorq\directives() at
 * route() return time.
 */
final readonly class Result
{
    /**
     * @param array<array{type: string, payload: mixed}> $directives
     */
    public function __construct(
        public string $module,
        public array  $directives = [],
    ) {}

    public static function notFound(): self
    {
        return new self('core', [
            ['type' => 'error', 'payload' => ['code' => 404, 'content' => null]],
        ]);
    }

    public function first(string $type): ?array
    {
        foreach ($this->directives as $d) {
            if ($d['type'] === $type) return $d;
        }
        return null;
    }

    /**
     * Find all directives of a given type.
     *
     * @return array<array{type: string, payload: mixed}>
     */
    public function all(string $type): array
    {
        return array_values(array_filter(
            $this->directives,
            fn($d) => $d['type'] === $type,
        ));
    }

    public function has(string $type): bool
    {
        return $this->first($type) !== null;
    }
}