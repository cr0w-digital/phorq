<?php
declare(strict_types=1);

namespace phorq;

/**
 * Immutable value object returned by Router::route().
 *
 * @property-read int    $status 200 for success, 404 for not found.
 * @property-read string $body   The rendered response body (empty on 404).
 * @property-read string $module The module that handled the request.
 */
final readonly class Result
{
    public function __construct(
        public int $status,
        public string $body,
        public string $module = '',
    ) {}

    /** True when the route matched successfully. */
    public function ok(): bool
    {
        return $this->status === 200;
    }
}
