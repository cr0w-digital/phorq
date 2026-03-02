<?php
declare(strict_types=1);

namespace phorq;

/**
 * Immutable value object returned by Router::route() on a successful match.
 *
 * @property-read string $body   The rendered response body.
 * @property-read string $module The module that handled the request.
 */
final readonly class Result
{
    public function __construct(
        public string $body,
        public string $module = '',
    ) {}
}
