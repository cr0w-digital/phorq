<?php

declare(strict_types=1);

namespace phorq;

/* -------------------------------------------------
 * Publisher interface
 * ------------------------------------------------- */

/**
 * Pub/sub publisher interface.
 *
 * Implementations handle the transport-specific mechanics of
 * publishing events to topics and subscribing clients to them.
 *
 * Register a publisher at bootstrap:
 *
 *   \phorq\set_publisher(new MercurePublisher($hubUrl, $secret));
 *   \phorq\set_publisher(new RedisPublisher($redis));
 *   \phorq\set_publisher(fn($t, $e, $em) => ...); // callable shorthand
 */
interface Publisher
{
    /**
     * Publish an event to a topic.
     * Fire and forget — returns immediately.
     */
    public function publish(string $topic, SseEvent $event): void;

    /**
     * Subscribe a client to a topic.
     *
     * Implementations handle the transport:
     *   Mercure  — redirects client to hub with subscriber JWT
     *   Redis    — opens SSE connection, forwards events from channel
     *   Custom   — whatever makes sense
     */
    public function subscribe(string $topic, ResponseEmitter $emitter): void;
}

/* -------------------------------------------------
 * Publisher registry
 * ------------------------------------------------- */

/**
 * Register the active publisher.
 *
 * @param Publisher|callable $publisher
 */
function set_publisher(Publisher|callable $publisher): void
{
    static $registry = null;

    if ($publisher instanceof Publisher) {
        $GLOBALS['__phorq_publisher'] = $publisher;
    } else {
        $GLOBALS['__phorq_publisher'] = new class($publisher) implements Publisher {
            public function __construct(private $fn) {}
            public function publish(string $topic, SseEvent $event): void
            {
                ($this->fn)('publish', $topic, $event, null);
            }
            public function subscribe(string $topic, ResponseEmitter $emitter): void
            {
                ($this->fn)('subscribe', $topic, null, $emitter);
            }
        };
    }
}

/**
 * Get the active publisher.
 *
 * @internal
 */
function get_publisher(): ?Publisher
{
    return $GLOBALS['__phorq_publisher'] ?? null;
}

/* -------------------------------------------------
 * Mercure publisher
 * ------------------------------------------------- */

/**
 * Publishes events to a Mercure hub.
 * Subscribes clients by redirecting them to the hub with a signed JWT.
 *
 * Usage:
 *   \phorq\set_publisher(new MercurePublisher(
 *       hubUrl: 'https://example.com/.well-known/mercure',
 *       secret: $_ENV['MERCURE_SECRET'],
 *   ));
 */
final class MercurePublisher implements Publisher
{
    public function __construct(
        private string $hubUrl,
        private string $secret,
        private int    $ttl = 3600,
    ) {}

    public function publish(string $topic, SseEvent $event): void
    {
        $jwt  = $this->publisherJwt([$topic]);
        $data = http_build_query([
            'topic' => $topic,
            'data'  => $event->encode(),
        ]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Bearer {$jwt}\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => $data,
        ]]);

        @file_get_contents($this->hubUrl, false, $ctx);
    }

    public function subscribe(string $topic, ResponseEmitter $emitter): void
    {
        $jwt = $this->subscriberJwt([$topic]);
        $url = $this->hubUrl . '?topic=' . urlencode($topic);

        // For HTMX: send HX-Redirect to the hub
        // The client connects directly — PHP is done
        $emitter->header('HX-Redirect', $url . '&authorization=' . $jwt);
    }

    private function publisherJwt(array $topics): string
    {
        return $this->jwt(['mercure' => ['publish' => $topics]]);
    }

    private function subscriberJwt(array $topics): string
    {
        return $this->jwt(['mercure' => ['subscribe' => $topics]]);
    }

    private function jwt(array $claims): string
    {
        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64url(json_encode(array_merge($claims, [
            'iat' => time(),
            'exp' => time() + $this->ttl,
        ])));

        $sig = $this->base64url(hash_hmac('sha256', "{$header}.{$payload}", $this->secret, true));

        return "{$header}.{$payload}.{$sig}";
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/* -------------------------------------------------
 * Redis publisher
 * ------------------------------------------------- */

/**
 * Publishes events via Redis Pub/Sub.
 * Subscribes clients by holding an SSE connection and forwarding events.
 *
 * Requires ext-redis or a Redis client that exposes subscribe().
 *
 * Usage:
 *   $redis = new \Redis();
 *   $redis->connect('127.0.0.1', 6379);
 *   \phorq\set_publisher(new RedisPublisher($redis));
 */
final class RedisPublisher implements Publisher
{
    public function __construct(private \Redis $redis) {}

    public function publish(string $topic, SseEvent $event): void
    {
        $this->redis->publish($topic, $event->encode());
    }

    public function subscribe(string $topic, ResponseEmitter $emitter): void
    {
        $emitter->status(200);
        $emitter->header('Content-Type',      'text/event-stream');
        $emitter->header('Cache-Control',     'no-cache');
        $emitter->header('X-Accel-Buffering', 'no');

        if ($emitter instanceof FpmEmitter) {
            while (ob_get_level() > 0) ob_end_flush();
            ob_implicit_flush(true);
            set_time_limit(0);
            ignore_user_abort(false);
        }

        // Use a separate Redis connection for blocking subscribe
        $subscriber = new \Redis();
        $subscriber->connect(
            $this->redis->getHost(),
            $this->redis->getPort(),
        );

        $subscriber->subscribe([$topic], function (\Redis $redis, string $channel, string $message) use ($emitter) {
            if (!$emitter->write($message)) {
                $redis->close();
            }
        });

        $emitter->close();
    }
}