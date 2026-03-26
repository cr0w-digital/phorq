<?php

declare(strict_types=1);

namespace phorq;

/**
 * Per-request directives stack.
 *
 * Route files and middleware write directives imperatively.
 * The router sweeps them into Result when the chain unwinds.
 * Dispatch interprets them.
 *
 * Isolated per coroutine in Swoole, per fiber in FrankenPHP,
 * static in FPM.
 *
 * Usage:
 *
 *   html(h('.card', $data));
 *   trigger('toast', ['message' => 'Saved!']);
 *   push_url('/users/' . $id);
 *   // no return needed
 */
final class Directives
{
    /** @var array<array{type: string, payload: mixed}> */
    private array $stack = [];

    public function push(string $type, mixed $payload): void
    {
        $this->stack[] = ['type' => $type, 'payload' => $payload];
    }

    /**
     * Sweep and clear — returns all directives and resets the stack.
     *
     * @return array<array{type: string, payload: mixed}>
     */
    public function sweep(): array
    {
        $directives  = $this->stack;
        $this->stack = [];
        return $directives;
    }

    public function isEmpty(): bool
    {
        return $this->stack === [];
    }

    public function reset(): void
    {
        $this->stack = [];
    }
}

/**
 * True if the array looks like a swept directives list rather than
 * a phml node or fragment.
 *
 * A directives list is a sequential array of arrays each with
 * 'type' (string) and 'payload' keys.
 *
 * @internal
 */
function is_directive_list(array $arr): bool
{
    if ($arr === [] || array_keys($arr) !== range(0, count($arr) - 1)) {
        return false;
    }

    foreach ($arr as $item) {
        if (!is_array($item) || !isset($item['type'], $item['payload']) || !is_string($item['type'])) {
            return false;
        }
    }

    return true;
}