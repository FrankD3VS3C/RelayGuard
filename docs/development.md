# RelayGuard Development Guide

## 1. Introduction

This guide explains how to configure, run, and test RelayGuard in a local development environment.

RelayGuard uses Laravel for the application runtime, PostgreSQL for persistent storage, and Redis for background job processing and distributed locks.

The development setup runs PHP and Laravel on the host machine, while PostgreSQL and Redis run as Docker Compose services.

## 2. Prerequisites

Install the following tools before setting up the project:

| Requirement | Purpose |
|---|---|
| PHP 8.3+ (tested with PHP 8.5) | Application runtime |
| Composer | PHP dependency management |
| Docker | Container runtime |
| Docker Compose | Local infrastructure |
| Git | Source control |

PHP must have the extensions required by Laravel and the project dependencies, including database connectivity and cURL support.

Check your installed versions:

```bash
php --version
composer --version
docker --version
docker compose version
```

## 3. Project Setup

### 3.1 Obtain the Source Code

If you already have the project directory, navigate to it:

```bash
cd relayguard
```

If the repository has been published, clone it using its actual Git remote URL.

### 3.2 Install PHP Dependencies

Run:

```bash
composer install
```
This installs the dependencies defined in `composer.lock`.

### 3.3 Configure Environment Variables

Create the local environment file:

```bash
cp .env.example .env
```

Review `.env` and configure the database, Redis, queue, and cache settings.

At minimum, Docker Compose requires `DB_PASSWORD` to be set because PostgreSQL uses it when initializing the database.

The application must use database settings compatible with the PostgreSQL service:

- Host: `127.0.0.1` or `localhost`.
- Port: `5432`.
- Database: `relayguard`.
- Username: `relayguard`.
- Password: the configured `DB_PASSWORD`.

Redis is exposed locally on port `6379`.

For the application runtime, the queue and cache stores should use Redis.

Do not commit `.env` or share credentials, application keys, API tokens, or signing secrets.

### 3.4 Generate the Application Key

Run:

```bash
php artisan key:generate
```

Laravel uses this key for application encryption, including encrypted webhook signing secrets.

Keep the generated key private and preserve it when restarting an existing environment. Do not generate a new key casually after encrypted data has been stored.

## 4. Start Local Infrastructure

Start PostgreSQL and Redis:

```bash
docker compose up -d
```

Check the service status:

```bash
docker compose ps
```

Both services include health checks.

To inspect their logs:

```bash
docker compose logs postgres
docker compose logs redis
```

The Compose configuration binds both services to the local loopback interface, rather than exposing their ports on every network interface.

### Stop Infrastructure

To stop the services without deleting persistent data:

```bash
docker compose down
```

To start them again:

```bash
docker compose up -d
```

Avoid `docker compose down -v` unless you intentionally want to delete the persistent database and Redis volumes.

## 5. Database Setup

After PostgreSQL is running and the Laravel environment is configured, execute:

```bash
php artisan migrate
```

To inspect the registered migrations:

```bash
php artisan migrate:status
```

The application uses PostgreSQL for local development.

Automated tests use a separate in-memory SQLite database, as described in the testing section.

### Webhook Endpoint Provisioning

The current version does not expose a public API or Artisan
command for registering webhook endpoints.

An active endpoint must be provisioned through an authorized
local development or administrative process before testing
outbound webhook delivery.

Endpoint registration and management are planned improvements.

## 6. Run the Application

Start the Laravel development server:

```bash
php artisan serve
```

By default, the application is available at:

```text
http://127.0.0.1:8000
```

The event ingestion endpoint is:

```text
POST /api/v1/events
```

## 7. Start the Queue Worker

RelayGuard processes webhook deliveries asynchronously using Laravel queues backed by Redis.

Open a separate terminal in the project directory and run:

```bash
php artisan queue:work redis
```

The worker processes jobs from the configured Redis queue, whose default name is `default`.

Keep the worker running while testing webhook delivery.

When application code or job classes change, restart the worker so it loads the latest code:

```bash
php artisan queue:restart
```

Then start the worker again if necessary.

## 8. Start the Task Scheduler

RelayGuard uses Laravel's scheduler to recover pending
webhook deliveries that were not successfully dispatched.

The recovery command is scheduled to run every minute.

For local development, start the scheduler in a separate
terminal:

```bash
php artisan schedule:work
```

To execute the recovery command manually:

```bash
php artisan relayguard:deliveries:recover
```

The recovery command processes up to 100 deliveries by
default. The batch size can be customized using the
`--limit` option, which accepts values between 1 and 500.

For example:

```bash
php artisan relayguard:deliveries:recover --limit=50
```

To inspect the registered scheduled tasks:

```bash
php artisan schedule:list
```

## 9. Create an API Client

RelayGuard provides an Artisan command to create API clients.

Run:

```bash
php artisan relayguard:client:create "Local Development Client"
```

The command generates an API credential and displays the plaintext token once.

Store it securely for local testing.

Do not commit the token or paste it into public issue trackers, screenshots, or documentation.

## 10. Submit a Test Event

With the application server and queue worker running, submit an event using an authorized API credential:

```bash
curl -i -X POST http://127.0.0.1:8000/api/v1/events \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: local-order-001" \
  -d '{
    "event_type": "order.created",
    "payload": {
      "order_id": 123,
      "amount": 49.99,
      "currency": "USD"
    }
  }'
```

### API Response Codes

The event ingestion endpoint returns the following HTTP status codes:

| Status code | Meaning |
|---|---|
| `202 Accepted` | A new event was successfully stored and accepted for asynchronous processing |
| `200 OK` | The event was already accepted with the same idempotency key and identical content |
| `401 Unauthorized` | API authentication is missing, invalid, or inactive |
| `409 Conflict` | The idempotency key was previously used with different event content |
| `413 Content Too Large` | The request payload exceeds the configured size limit |
| `422 Unprocessable Content` | The request fails validation |
| `429 Too Many Requests` | The authenticated API client exceeded its rate limit of 60 requests per minute |

Rate-limited responses include a `Retry-After` header
indicating when the client can retry.

Successful event ingestion does not guarantee successful delivery to every webhook endpoint. Outbound delivery is processed asynchronously.

Replace `YOUR_API_KEY` with the credential generated for your local API client.

The API uses the idempotency key to identify repeated submissions.

Expected behavior:

- A new event is persisted and delivery records are created for active endpoints.
- Repeating the same key with identical content is treated as a duplicate.
- Reusing the key with different content returns HTTP 409 Conflict.

An active webhook endpoint must exist for the client before a delivery job can be generated for it.

## 11. Run Automated Tests

Run the complete test suite:

```bash
php artisan test
```

The test environment is configured through `phpunit.xml` and uses:

| Setting | Test configuration |
|---|---|
| Database | SQLite |
| Database storage | In-memory |
| Queue | Synchronous |
| Cache | Array |
| Application environment | Testing |

This isolates automated tests from the local PostgreSQL and Redis services.

The suite includes tests for:

- Event ingestion and idempotency.
- Transaction rollback and job dispatch.
- API authentication.
- Payload size validation.
- URL and IP address validation.
- Secure outbound HTTP behavior.
- HMAC request signatures.
- Delivery state transitions.
- Retry scheduling.
- Distributed locking.
- Pending delivery recovery and dispatch eligibility.
- Scheduled recovery command validation and batch limits.

To run a focused test class:

```bash
php artisan test --filter=ProcessWebhookDeliveryTest
```

To run tests related to event ingestion:

```bash
php artisan test --filter=StoreWebhookEventTest
```

## 12. Useful Development Commands

| Command | Purpose |
|---|---|
| `php artisan route:list` | List registered routes |
| `php artisan migrate:status` | Inspect migration status |
| `php artisan queue:work redis` | Start the Redis queue worker |
| `php artisan queue:restart` | Request worker restart |
| `php artisan test` | Run automated tests |
| `php artisan config:clear` | Clear cached configuration |
| `php artisan schedule:work` | Run the task scheduler locally |
| `php artisan schedule:list` | List scheduled tasks |
| `php artisan relayguard:deliveries:recover` | Recover eligible pending deliveries |
| `php artisan relayguard:client:create "Client Name"` | Create an API client |
| `docker compose ps` | Inspect infrastructure status |
| `docker compose logs -f` | Follow infrastructure logs |
| `git status --short` | Inspect local source changes |
| `git diff --check` | Check for whitespace errors |

## 13. Troubleshooting

### PostgreSQL Connection Refused

Check that the PostgreSQL container is running and healthy:

```bash
docker compose ps
docker compose logs postgres
```

Verify the database host, port, username, password, and database name in `.env`.

### Redis Connection Refused

Check Redis:

```bash
docker compose ps
docker compose logs redis
```

Verify that Laravel is configured to connect to Redis at the local host and port.

### Configuration Changes Are Not Applied

Laravel may have cached configuration.

Clear it with:

```bash
php artisan config:clear
```

### Jobs Are Not Being Processed

Verify that:

1. Redis is running.
2. The application uses the Redis queue connection.
3. The queue worker is running.
4. The worker is listening to the expected queue.

Start the worker with:

```bash
php artisan queue:work redis
```

### Database Migration Errors

Verify PostgreSQL availability and the database credentials.

Then inspect migration status:

```bash
php artisan migrate:status
```

### Tests Behave Differently from Local Development

Remember that automated tests use SQLite, an array cache, and a synchronous queue.

They do not automatically exercise the complete production-style Redis worker lifecycle.

For that reason, Redis integration testing should be performed separately when validating queue behavior.

## 14. Development Workflow

A recommended local workflow is:

1. Start PostgreSQL and Redis.
2. Run database migrations when necessary.
3. Start the Laravel application server.
4. Start the Redis queue worker in a separate terminal.
5. Start the Laravel task scheduler in a separate terminal.
6. Execute API requests and inspect their results.
7. Run focused tests while developing.
8. Run the complete test suite before considering a change ready.
9. Review the Git diff and check for accidental secrets before committing.

RelayGuard is actively under development. Consult the architecture and security documentation for additional implementation details and known limitations.
