<?php

declare(strict_types=1);

namespace phorq;

/**
 * Immutable HTTP request value object.
 *
 * Constructed once per request and passed through the middleware stack into
 * every route file. All properties are read-only after construction.
 *
 * Usage in route files:
 *
 *   $req->isPost()
 *   $req->isHtmx()
 *   $req->string('email')
 *   $req->int('page', 1)
 *   $req->input('payload')
 *   $req->query('sort', 'asc')
 *   $req->module
 *   $req->pattern
 */
final class Request
{
    // ── Properties ───────────────────────────────────────────

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

    // ── Constructor ──────────────────────────────────────────

    /**
     * All parameters accept mixed input and are normalised on construction:
     *
     *   $method  → uppercased string, defaults to 'GET'
     *   $path    → trimmed string, no leading or trailing slashes
     *   $query   → array, objects cast to array, anything else becomes []
     *   $input   → array, same coercion as $query
     *   $headers → array, keys lowercased and underscores replaced with hyphens;
     *              array values are reduced to their first element
     */
    public function __construct(
        mixed $method   = 'GET',
        mixed $path     = '',
        mixed $query    = [],
        mixed $input    = [],
        mixed $headers  = [],
        ?string $pattern = null,
        ?string $module  = null,
    ) {
        $this->method  = strtoupper((string) $method ?: 'GET');
        $this->path    = trim((string) $path, '/');
        $this->pattern = $pattern;
        $this->module  = $module;

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

    /**
     * Build a Request from PHP superglobals.
     *
     * POST bodies are read from $_POST when populated, otherwise the raw
     * php://input stream is decoded as JSON. GET requests produce no input.
     * Headers are extracted from HTTP_* keys in $_SERVER.
     */
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

    /**
     * Return a new Request with the matched pattern and module set.
     * Called by Router immediately after route resolution, before dispatch.
     */
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

    /**
     * True when the request carries or expects JSON.
     *
     * Checks both Content-Type (body is JSON) and Accept (response should be
     * JSON) so it covers both POST/PUT payloads and GET API requests.
     */
    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'application/json')
            || str_contains($this->header('Accept', ''), 'application/json');
    }

    /**
     * True when the request arrived over HTTPS.
     *
     * Checks X-Forwarded-Proto first for reverse proxy and FrankenPHP setups,
     * then falls back to the HTTPS server variable.
     */
    public function isSecure(): bool
    {
        return $this->header('X-Forwarded-Proto') === 'https'
            || ($_SERVER['HTTPS'] ?? '') === 'on';
    }

    // ── HTMX ─────────────────────────────────────────────────

    /** True when the request was initiated by HTMX. */
    public function isHtmx(): bool
    {
        return isset($this->headers['hx-request']);
    }

    /** True when the HTMX request was triggered by a boosted element. */
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

    // ── Input ────────────────────────────────────────────────

    /**
     * Retrieve a raw value from the request body.
     * Returns $default when the key is absent.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    /**
     * Retrieve a raw value from the query string.
     * Returns $default when the key is absent.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Retrieve a trimmed string from input or query string.
     * Input takes precedence over query when both are present.
     */
    public function string(string $key, string $default = ''): string
    {
        return trim((string) ($this->input[$key] ?? $this->query[$key] ?? $default));
    }

    /** Retrieve an integer from input or query string. */
    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->input[$key] ?? $this->query[$key] ?? $default);
    }

    /** Retrieve a float from input or query string. */
    public function float(string $key, float $default = 0.0): float
    {
        return (float) ($this->input[$key] ?? $this->query[$key] ?? $default);
    }

    /**
     * Retrieve a boolean from input or query string.
     *
     * Uses filter_var to handle 'true', 'false', '1', '0', 'yes', 'no', etc.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $val = $this->input[$key] ?? $this->query[$key] ?? null;
        if ($val === null) return $default;
        return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * True when the key is present in either input or query string.
     * Use hasInput() to check only the request body.
     */
    public function has(string $key): bool
    {
        return isset($this->input[$key]) || isset($this->query[$key]);
    }

    /** True when the key is present in the request body. */
    public function hasInput(string $key): bool
    {
        return isset($this->input[$key]);
    }

    // ── Headers ──────────────────────────────────────────────

    /**
     * Retrieve a header value by name (case-insensitive).
     * Returns $default when the header is absent.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }
}