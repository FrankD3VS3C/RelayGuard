# RelayGuard

**A secure, reliable webhook delivery service built with Laravel.**

RelayGuard receives events through an authenticated API, validates idempotency, and dispatches them to registered webhook endpoints using background jobs.

The project focuses on secure outbound HTTP communication, reliable delivery processing, and defensive backend engineering.

> **Project status:** Functional v1 MVP — pre-release.

## Key Features

- **API authentication:** Bearer token authentication using SHA-256 hashed API credentials.
- **Idempotent event ingestion:** Prevents duplicate event processing and detects conflicting requests.
- **Asynchronous delivery:** Processes webhook deliveries through Laravel queued jobs.
- **HMAC signatures:** Signs outgoing webhook payloads using each endpoint's secret.
- **SSRF protection:** Validates destination URLs and resolved IP addresses before making outbound requests.
- **DNS rebinding mitigation:** Pins validated destination addresses during HTTP requests.
- **Retry handling:** Implements bounded retries with fixed backoff delays.
- **Concurrency protection:** Uses distributed cache locks to prevent simultaneous processing of the same delivery.
- **Response limits:** Restricts outbound response sizes to reduce resource exhaustion risks.
- **API rate limiting:** Enforces 60 requests per minute per API client.
- **Delivery recovery:** Periodically recovers eligible pending deliveries using a scheduled command and a five-minute dispatch cooldown.
- **Automated testing:** Includes feature and unit tests for ingestion, delivery, validation, and security controls.

## Technology Stack

| Component | Technology |
|---|---|
| Backend | PHP 8.3+, Laravel 13 (tested with PHP 8.5) |
| Database | PostgreSQL 17 |
| Queue and cache | Redis 7 |
| HTTP client | Guzzle with cURL |
| Testing | PHPUnit 12 |
| Infrastructure | Docker Compose |

## Architecture Overview

RelayGuard follows an event-driven delivery workflow:

1. An API client submits an event.
2. Middleware validates the request size and authenticates the client.
3. The application checks the idempotency key.
4. A new event and its delivery records are persisted transactionally.
5. Delivery jobs are dispatched after the database transaction commits.
6. A background worker retrieves each delivery and validates its destination.
7. RelayGuard sends the payload with an HMAC signature.
8. The delivery is marked as successful, permanently failed, or scheduled for retry.

See [Architecture](docs/architecture.md) for more details.

## Requirements

- PHP 8.3 or later within the supported dependency constraints.
- Composer.
- Docker and Docker Compose.
- PHP extensions required by Laravel, PostgreSQL, and cURL.

## Local Development

If you already have the project source code, navigate to its directory and install the PHP dependencies:

```bash
cd relayguard
composer install
```

Create your local environment file:

```bash
cp .env.example .env
```

Configure the required environment variables in `.env`. In particular, set a `DB_PASSWORD` value because Docker Compose requires it to start PostgreSQL.

Start the infrastructure:

```bash
docker compose up -d
```

Generate the application key:

```bash
php artisan key:generate
```

Run database migrations:

```bash
php artisan migrate
```

Start the Laravel development server:

```bash
php artisan serve
```

In another terminal, start the queue worker:

```bash
php artisan queue:work redis
```

To enable automatic recovery of pending deliveries during local
development, start the Laravel scheduler in another terminal:

```bash
php artisan schedule:work
```

The scheduler runs the pending delivery recovery command every
minute. This is only required to test automatic recovery; normal
delivery processing works through the queue worker.

The API will be available at:

```text
http://127.0.0.1:8000
```

## API

### Submit an event

```http
POST /api/v1/events
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json
Idempotency-Key: order-123-created
```

Example request:

```json
{
  "event_type": "order.created",
  "payload": {
    "order_id": 123,
    "amount": 49.99,
    "currency": "USD"
  }
}
```

The API uses the `Idempotency-Key` header to identify repeated submissions.

- A repeated key with the same event content is treated as a duplicate.
- A repeated key with different content returns a conflict.
- New events generate delivery records for active endpoints.

API credentials are generated through an Artisan command and stored as SHA-256 hashes. The plaintext credential is displayed only when created.

Create an API client using:

```bash
php artisan relayguard:client:create "My Application"
```

The command displays the API key once. Store it securely;
only its SHA-256 hash is persisted in the database.

### Webhook Endpoint Provisioning

Webhook endpoints must currently be registered through
an authorized local development or administrative process.

The current version does not expose a public endpoint
management API or Artisan provisioning command.

See the Deployment Guide for operational details.

## Testing

Run the complete test suite:

```bash
php artisan test
```

The test environment uses an in-memory SQLite database, an array cache store, and a synchronous queue. This keeps automated tests isolated from the local PostgreSQL and Redis services.

## Security

Security is a core design concern rather than an afterthought.

Current controls include:

- Hashed API credentials.
- Encrypted webhook signing secrets.
- Request payload size limits.
- Destination URL and IP address validation.
- Protection against requests to private and special-purpose IP ranges.
- DNS resolution validation and destination IP pinning.
- Disabled HTTP redirects.
- TLS certificate verification.
- Bounded HTTP timeouts and response sizes.
- HMAC-SHA256 request signatures.
- Delivery concurrency locks and bounded retries.

See [Security](docs/security.md) for implementation details and known limitations.

## Development Guide

For environment setup, commands, testing, and troubleshooting,
see [Development Guide](docs/development.md).

For production deployment considerations and operational
configuration, see [Deployment Guide](docs/deployment.md).

## License and Copyright

Copyright (c) 2026. All rights reserved.

This repository is publicly available for portfolio review
and technical evaluation.

Unless explicitly authorized in writing by the copyright
holder, no permission is granted to use, reproduce, modify,
distribute, or commercially exploit this software.

Third-party dependencies remain subject to their
respective licenses.
