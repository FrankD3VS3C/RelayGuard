# RelayGuard Architecture

## 1. System Overview

RelayGuard is a webhook ingestion and delivery service built with Laravel.

It provides an authenticated API for receiving events, persists them with idempotency guarantees, and asynchronously delivers them to registered webhook endpoints.

The architecture prioritizes:

- Reliable event persistence.
- Controlled asynchronous processing.
- Secure outbound HTTP requests.
- Concurrent processing protection for individual deliveries.
- Bounded retries and resource consumption.

The application is organized around four main responsibilities:

1. API authentication and event ingestion.
2. Event and delivery persistence.
3. Background delivery processing.
4. Secure outbound HTTP communication.

## 2. Technology Stack

| Component | Technology |
|---|---|
| Application framework | Laravel 13 |
| Runtime | PHP 8.5 |
| Relational database | PostgreSQL 17 |
| Queue and distributed locks | Redis 7 |
| HTTP client | Guzzle with cURL |
| Automated testing | PHPUnit 12 |
| Infrastructure | Docker Compose |

Laravel runs directly in the development environment. PostgreSQL and Redis run as Docker Compose services.

## 3. High-Level Architecture

```text
                  API CLIENT
                      |
                      |
              POST /api/v1/events
                      |
                      v
             +------------------+
             | Request Middleware|
             |------------------|
             | Payload size     |
             | API authentication|
             +------------------+
                      |
                      v
             +------------------+
             | EventController  |
             |------------------|
             | Validation       |
             | Idempotency      |
             | Persistence      |
             +------------------+
                      |
                      v
             +------------------+
             | Database         |
             |------------------|
             | ApiClient        |
             | WebhookEvent     |
             | WebhookEndpoint  |
             | WebhookDelivery  |
             +------------------+
                      |
                After commit
                      |
                      v
             +------------------+
             | Redis Queue      |
             +------------------+
                      |
                      v
             +------------------+
             | Delivery Job     |
             |------------------|
             | Distributed lock |
             | Retry handling   |
             | Status updates   |
             +------------------+
                      |
                      v
             +------------------+
             | Secure HTTP      |
             | Client           |
             |------------------|
             | URL validation   |
             | DNS validation   |
             | IP pinning        |
             | TLS verification |
             +------------------+
                      |
                      v
                WEBHOOK TARGET
```

## 4. Core Components

### 4.1 API Authentication

API clients authenticate using Bearer tokens.

The application stores a SHA-256 hash of each API credential rather than storing the plaintext token.

The authentication middleware:

1. Reads the Bearer token.
2. Calculates its SHA-256 hash.
3. Finds the corresponding API client.
4. Checks whether the client is active.
5. Rejects invalid or inactive credentials.

API credentials are generated through an Artisan command. The plaintext credential is displayed only when it is created.

### 4.2 Request Validation

The event ingestion endpoint applies two middleware controls:

- Payload size validation.
- API client authentication.

The controller validates the event type, payload, and `Idempotency-Key` header.

The accepted payload size is limited to 64 KiB.

The idempotency key is restricted to a maximum of 128 characters and an allowed character set.

The API also enforces a Redis-backed rate limit of 60
requests per minute per authenticated API client.
Requests exceeding this limit receive HTTP 429 Too Many
Requests and include a `Retry-After` response header.

### 4.3 Event Ingestion

The event ingestion workflow is implemented in `EventController`.

Each incoming event is associated with an API client and an idempotency key.

RelayGuard distinguishes three situations:

| Situation | Behavior |
|---|---|
| New idempotency key | Creates a new event |
| Existing key with identical content | Returns the existing event as a duplicate |
| Existing key with different content | Returns HTTP 409 Conflict |

Event content is fingerprinted using canonicalized JSON and SHA-256.

Associative object keys are normalized recursively, while list ordering is preserved. This allows equivalent JSON objects to produce consistent fingerprints without treating differently ordered lists as equivalent.

### 4.4 Transactional Persistence

When a new event is accepted, RelayGuard creates the event and its delivery records inside a database transaction.

A delivery record is created for every active endpoint belonging to the API client.

This ensures that event persistence and delivery record creation succeed or fail together.

Jobs are dispatched using Laravel's `DB::afterCommit()` mechanism.

This is important because a worker must not process a delivery before the transaction containing its event and delivery records has committed.

If the transaction rolls back, its associated jobs are not dispatched.

Delivery jobs are dispatched through
`WebhookDeliveryDispatcher`.

The dispatcher checks delivery eligibility and records
the dispatch timestamp before scheduling the job.

Queue dispatching takes place after the database
transaction commits, preventing workers from processing
deliveries before their associated records are persisted.

## 5. Data Model

RelayGuard uses four primary models.

### ApiClient

Represents an API consumer authorized to submit events.

Main responsibilities:

- Stores the client name.
- Stores the hashed API credential.
- Tracks whether the client is active.
- Has many events and webhook endpoints.

### WebhookEvent

Represents an event received through the API.

Main responsibilities:

- Belongs to an API client.
- Stores the event type and JSON payload.
- Stores the idempotency key.
- Records the reception timestamp.
- Has many delivery records.

The database enforces uniqueness for the combination of API client and idempotency key.

### WebhookEndpoint

Represents a destination configured to receive webhook events.

Main responsibilities:

- Belongs to an API client.
- Stores its name and destination URL.
- Stores an encrypted signing secret.
- Tracks whether the endpoint is active.
- Has many delivery records.

Inactive endpoints are excluded from new delivery creation.

### WebhookDelivery

Represents an individual attempt lifecycle for delivering an event to an endpoint.

Main responsibilities:

- References its event and endpoint.
- Tracks delivery status and attempt count.
- Stores the last HTTP status and response body.
- Stores the last error.
- Tracks the next retry time and successful delivery timestamp.
- Tracks the last dispatch timestamp.

The database enforces uniqueness for each event and endpoint combination.

This prevents multiple delivery records from being created for the same event-endpoint pair.

## 6. Delivery Lifecycle

Delivery processing is implemented in `ProcessWebhookDelivery`.

A delivery job receives a delivery ID and performs the following steps:

1. Acquires a distributed Redis lock for the delivery.
2. Loads the delivery, event, and endpoint.
3. Checks whether the delivery is still pending.
4. Checks whether the next retry time has arrived.
5. Verifies that the endpoint is active.
6. Checks the maximum attempt count.
7. Increments the attempt counter.
8. Builds the outgoing JSON envelope.
9. Signs the exact request body using HMAC-SHA256.
10. Sends the HTTP request through the secure HTTP client.
11. Records the result and updates the delivery state.

The job avoids sending requests for deliveries that are already completed, inactive, or not yet eligible for another attempt.

### Delivery States

The current implementation uses these principal states:

| State | Meaning |
|---|---|
| `pending` | Waiting to be processed or retried |
| `delivered` | Successfully delivered with a 2xx response |
| `failed` | Permanently failed or no more attempts are allowed |

HTTP responses are handled according to their status:

- **2xx:** marks the delivery as successful.
- **408, 429, and 5xx:** treated as retryable failures.
- **Other non-2xx responses:** treated as permanent failures.

Network errors are also treated as retryable failures.

## 7. Retry Strategy

RelayGuard uses a bounded retry strategy with a maximum of five delivery attempts.

The configured retry delays are:

| Retry | Delay |
|---|---:|
| 1 | 10 seconds |
| 2 | 30 seconds |
| 3 | 120 seconds |
| 4 | 300 seconds |

When a retryable failure occurs, the application:

1. Records the error.
2. Stores the next eligible attempt timestamp.
3. Schedules another delivery job.
4. Allows the job to be processed when the retry time arrives.

When the maximum attempt count is reached, the delivery is marked as failed.

The retry timestamp is also checked by the job itself. This prevents an early or duplicate job from sending the webhook before its scheduled retry time.

## 8. Concurrency Control

RelayGuard uses Laravel's distributed cache locking mechanism to prevent concurrent processing of the same delivery.

Each delivery uses a unique lock key:

```text
webhook-delivery:{delivery_id}
```

The current timing configuration is:

| Mechanism | Duration |
|---|---:|
| Job execution timeout | 30 seconds |
| Distributed lock | 45 seconds |
| Redis retry-after | 90 seconds |

The lock prevents another worker from processing the same delivery while the first worker holds it.

The timing relationship provides a safety margin between job execution, lock expiration, and queue redelivery.

## 9. Secure Outbound HTTP Communication

Outbound requests are handled by `SecureWebhookHttpClient`.

Before making an HTTP request, RelayGuard validates the destination URL and its resolved IP addresses.

### Destination Validation

The URL validator:

- Allows HTTP and HTTPS schemes.
- Rejects URL credentials.
- Restricts destination ports to 80 and 443.
- Rejects localhost and selected internal hostnames.
- Resolves hostname A and AAAA records.
- Rejects destinations resolving to private or special-purpose IP addresses.
- Rejects hostnames without valid public DNS results.

### DNS Rebinding Mitigation

Validating a hostname only once is not sufficient if the HTTP client performs another DNS lookup later.

RelayGuard therefore pins the selected validated IP address during the outbound request using cURL's `CURLOPT_RESOLVE`.

This reduces the risk of DNS rebinding between validation and connection establishment.

### HTTP Client Restrictions

The secure HTTP client also:

- Disables automatic redirects.
- Disables proxy usage.
- Verifies TLS certificates.
- Uses connection and total request timeouts.
- Limits the response body to 256 KiB.
- Sends the exact serialized request body used for signature generation.

These controls reduce exposure to SSRF, unexpected network routing, and excessive resource consumption.

## 10. Webhook Signatures

Outgoing webhook requests include an HMAC-SHA256 signature.

The signature is calculated over the exact serialized JSON request body:

```text
HMAC_SHA256(request_body, endpoint_signing_secret)
```

The signature is sent through:

```http
X-RelayGuard-Signature: sha256={signature}
```

Each delivery also includes an `X-RelayGuard-Delivery-Id` header.

Consumers can use the signature to verify that the payload was produced by a party possessing the configured endpoint secret.

## 11. Background Worker

RelayGuard uses Laravel's Redis queue connection.

Start the worker with:

```bash
php artisan queue:work redis
```

The configured Redis queue name is `default`.

The queue's `retry_after` value is 90 seconds.

Delivery retry scheduling is managed by the application through delivery records and scheduled jobs. This is separate from Laravel's queue-level retry behavior.

Pending delivery recovery is handled separately by the
Laravel scheduler. See Section 12 for the recovery workflow
and its scheduling configuration.

## 12. Pending Delivery Recovery

RelayGuard includes a scheduled recovery mechanism for
pending deliveries that were not successfully dispatched.

The recovery workflow is implemented through
`WebhookDeliveryDispatcher` and the
`relayguard:deliveries:recover` Artisan command.

The dispatcher:

1. Selects pending deliveries eligible for processing.
2. Excludes deliveries whose next retry time has not arrived.
3. Excludes deliveries dispatched within the previous
   five minutes.
4. Locks each delivery record before scheduling a job.
5. Records the dispatch timestamp.
6. Dispatches the job after the database transaction commits.

The recovery command processes a configurable batch
of deliveries, with a default limit of 100 and a
maximum limit of 500.

Laravel's scheduler runs the recovery command every
minute.

This mechanism helps recover pending deliveries when
queue dispatching fails or a delivery job is not
successfully enqueued.

The cooldown reduces redundant dispatching but does
not provide exactly-once queue delivery.

## 13. Testing Strategy

The project includes automated feature and unit tests.

Important areas covered include:

- API authentication and event ingestion.
- Idempotency and conflicting submissions.
- Transaction rollback behavior.
- Dispatching jobs after transaction commit.
- Destination URL validation.
- Public IP address validation.
- Secure HTTP request construction.
- HMAC signature generation.
- Retry scheduling.
- Delivery state transitions.
- Distributed locking.
- Prevention of premature delivery attempts.

The test environment uses an in-memory SQLite database, array cache, and synchronous queue to keep tests isolated from local infrastructure services.

Run the suite with:

```bash
php artisan test
```

## 14. Current Scope and Future Improvements

RelayGuard is under active development.

The current implementation establishes the core event ingestion and secure delivery workflow.

Potential future improvements include:

- Delivery inspection and management API.
- Endpoint registration and management API.
- Operational metrics and observability.
- Structured audit logging.
- More comprehensive integration tests using real Redis workers.
- Additional destination validation coverage based on the latest IANA special-purpose address registries.

These improvements are not represented as completed features in the current implementation.