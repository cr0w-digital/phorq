# Pub/sub notifications demo

This demo requires a publisher configured. Use Docker Compose to start Redis and Mercure:

## Redis

```bash
PUBLISHER=redis docker compose up
```

Open http://localhost:8080/notifications in two tabs. Send a notification — both tabs receive it instantly.

## Mercure

```bash
PUBLISHER=mercure docker compose up
```

Same experience — but Mercure handles the persistent connections externally, PHP just publishes and returns. Scales better for high-concurrency scenarios.

## Without Docker

Start Redis locally, then:

```bash
PUBLISHER=redis php -S localhost:8080 examples/fpm/index.php
```

Or with FrankenPHP (recommended for SSE):

```bash
PUBLISHER=redis frankenphp php-server
```

## How it works

- `/notifications` — page with HTMX SSE extension subscribed to `/notifications/stream`
- `/notifications/stream` — calls `subscribe('/topics/notifications')` — transport handled by publisher
- `/notifications/publish` — calls `publish('/topics/notifications', event(...))` — all subscribers receive it

The route files are identical regardless of publisher. Switch between Redis and Mercure by changing `PUBLISHER` env var.