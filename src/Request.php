<?php

declare(strict_types=1);

namespace phorq;

/**
 * Immutable HTTP request value object.
 *
 * Constructed once per request and passed through the middleware stack into
 * every route file. All properties are read-only after construction.
 */
final class Request
{
    /** HTTP method, uppercased — e.g. 'GET', 'POST'. */
    public readonly string $method;

    /** Request path, stripped of leading and trailing slashes. */
    public readonly string $path;

    /** Query string parameters ($_GET). */
    public readonly array $query;

    /** Request body input ($_POST or decoded JSON body). */
    public readonly array $input;

    /** Request headers, normalised to lowercase kebab-case keys. */
    public readonly array $headers;

    /** Matched route pattern — e.g. '/users/[id]'. Set by Router before dispatch. */
    public readonly ?string $pattern;

    /** Matched module name — e.g. 'account'. Set by Router before dispatch. */
    public readonly ?string $module;

    /** Matched route file path. Set by Router before dispatch. */
    public readonly ?string $file;

    public function __construct(
        mixed $method   = 'GET',
        mixed $path     = '',
        mixed $query    = [],
        mixed $input    = [],
        mixed $headers  = [],
        ?string $pattern = null,
        ?string $module  = null,
        ?string $file    = null,
    ) {
        $this->method  = strtoupper((string) $method ?: 'GET');
        $this->path    = trim((string) $path, '/');
        $this->pattern = $pattern;
        $this->module  = $module;
        $this->file    = $file;

        $toArray = static fn (mixed $v): array =>
            is_array($v) ? $v : (is_object($v) ? (array) $v : []);

        $this->query = $toArray($query);
        $this->input = $toArray($input);

        $normalized = [];
        foreach ((array) $headers as $k => $v) {
            $key = strtolower(str_replace('_', '-', (string) $k));
            $normalized[$key] = is_array($v) ? (string) reset($v) : ($v === null ? null : (string) $v);
        }
        $this->headers = $normalized;
    }

    // ── Factory ──────────────────────────────────────────────

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

        $input = match (true) {
            $method === 'GET' => [],
            !empty($_POST)    => $_POST,
            default           => json_decode((string) file_get_contents('php://input'), true) ?? [],
        };

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }

        return new self($method, $path, $_GET, $input, $headers);
    }

    public function withMatch(string $pattern, string $module): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->input,
            $this->headers,
            $pattern,
            $module,
        );
    }

    // ── HTTP method ──────────────────────────────────────────

    public function isGet(): bool    { return $this->method === 'GET'; }
    public function isPost(): bool   { return $this->method === 'POST'; }
    public function isPut(): bool    { return $this->method === 'PUT'; }
    public function isPatch(): bool  { return $this->method === 'PATCH'; }
    public function isDelete(): bool { return $this->method === 'DELETE'; }

    // ── Request type ─────────────────────────────────────────

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'application/json')
            || str_contains($this->header('Accept', ''), 'application/json');
    }

    public function isSecure(): bool
    {
        return $this->header('X-Forwarded-Proto') === 'https'
            || ($_SERVER['HTTPS'] ?? '') === 'on';
    }

    // ── Input ────────────────────────────────────────────────

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return trim((string) ($this->input[$key] ?? $this->query[$key] ?? $default));
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->input[$key] ?? $this->query[$key] ?? $default);
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float) ($this->input[$key] ?? $this->query[$key] ?? $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $val = $this->input[$key] ?? $this->query[$key] ?? null;
        if ($val === null) return $default;
        return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->input[$key]) || isset($this->query[$key]);
    }

    public function hasInput(string $key): bool
    {
        return isset($this->input[$key]);
    }

    // ── Headers ──────────────────────────────────────────────

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    // ── HTMX ─────────────────────────────────────────────────

    /** True when the request was initiated by HTMX. */
    public function isHtmx(): bool
    {
        return isset($this->headers['hx-request']);
    }

    /** True when the request was triggered by a boosted element. */
    public function isBoosted(): bool
    {
        return isset($this->headers['hx-boosted']);
    }

    /** The value of HX-Target, or null if absent. */
    public function target(): ?string
    {
        return $this->headers['hx-target'] ?? null;
    }

    /** The value of HX-Trigger (element id), or null if absent. */
    public function trigger(): ?string
    {
        return $this->headers['hx-trigger'] ?? null;
    }

    /** The value of HX-Trigger-Name (element name attribute), or null if absent. */
    public function triggerName(): ?string
    {
        return $this->headers['hx-trigger-name'] ?? null;
    }

    // ── Datastar ─────────────────────────────────────────────

    /** True when the request was initiated by Datastar. */
    public function isDatastar(): bool
    {
        return isset($this->headers['datastar-request']);
    }

    /**
     * All signals sent by the Datastar frontend.
     *
     * GET requests: signals arrive as ?datastar={"count":42}
     * All other methods: signals arrive as the JSON body directly — {"count":42}
     */
    public function signals(): array
    {
        if ($this->isGet()) {
            $raw = $this->query['datastar'] ?? null;
            return $raw ? (json_decode($raw, true) ?? []) : [];
        }

        // POST/PUT/PATCH/DELETE — signals are the entire JSON body
        return $this->input;
    }

    /**
     * Read a single signal value with an optional default.
     * Supports dot notation for nested signals: signal('user.name')
     */
    public function signal(string $key, mixed $default = null): mixed
    {
        $signals = $this->signals();
        $parts   = explode('.', $key);

        foreach ($parts as $part) {
            if (!is_array($signals) || !array_key_exists($part, $signals)) {
                return $default;
            }
            $signals = $signals[$part];
        }

        return $signals;
    }
}