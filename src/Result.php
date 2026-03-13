<?php
declare(strict_types=1);

namespace phorq;

/**
 * Immutable value object returned by Router::route() on a successful match.
 *
 * @property-read mixed $value  The response value.
 * @property-read string $module The module that handled the request.
 */
final readonly class Result
{
    public function __construct(
        public mixed $value,
        public string $module = '',
    ) {}
}
