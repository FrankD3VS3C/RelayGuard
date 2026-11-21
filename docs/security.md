# RelayGuard Security

## 1. Security Overview

Security is a core design consideration in RelayGuard.

As a webhook delivery service, RelayGuard handles authenticated API requests, stores event data, manages endpoint signing secrets, and makes outbound HTTP requests to destinations that may be controlled by external parties.

The security design focuses on reducing the risks associated with:

- Unauthorized API access.
- Credential exposure.
- Duplicate or conflicting event submissions.
- Server-Side Request Forgery (SSRF).
- DNS rebinding.
- Untrusted HTTP responses.
- Resource exhaustion.
- Concurrent delivery processing.

This document describes the controls currently implemented, their purpose, and known limitations.

It does not represent a formal security audit or a guarantee that all attack vectors have been eliminated.

## 2. API Authentication and Credentials

### Bearer Token Authentication

API clients authenticate using Bearer tokens.

Each API credential is generated with cryptographically secure random bytes and stored as a SHA-256 hash.

The plaintext token is displayed only during creation.

This design reduces the impact of database exposure because the database does not contain reusable plaintext API credentials.

### Client Activation

Each API client has an activation status.

Inactive clients cannot authenticate successfully, even if they present a previously valid credential.

### Credential Handling

Operational recommendations:

- Never commit API credentials to version control.
- Never include credentials in application logs.
- Do not expose credentials in screenshots or public demonstrations.
- Rotate credentials if exposure is suspected.
- Restrict access to the database and application environment.

### API Rate Limiting

The webhook ingestion API enforces a rate limit of 60
requests per minute per API client.

Requests exceeding the configured limit receive
`429 Too Many Requests` and include a `Retry-After`
response header indicating when the client can retry.

Rate limit counters use Redis through Laravel's dedicated
cache limiter store.

Each API client has an independent rate limit.


## 3. Webhook Signing Secrets

Each webhook endpoint has an individual signing secret.

RelayGuard uses Laravel's encrypted Eloquent cast to encrypt signing secrets before storing them in the database.

The secret is decrypted when the application needs to sign an outgoing request.

### HMAC-SHA256

Each outgoing webhook contains an HMAC-SHA256 signature calculated over the exact serialized request body.

Conceptually:

```text
signature = HMAC-SHA256(request_body, endpoint_secret)
```

The signature is sent using:

```http
X-RelayGuard-Signature: sha256={signature}
```

The delivery also includes:

```http
X-RelayGuard-Delivery-Id: {delivery_id}
```

The receiving application should:

1. Read the raw request body.
2. Calculate the HMAC using its configured secret.
3. Compare the expected signature with the received signature using a constant-time comparison.
4. Reject requests with invalid signatures.
5. Implement its own replay and duplicate-event handling.

Consumers should not deserialize and re-serialize the JSON before signature verification because the signature applies to the exact transmitted body.

### Secret Management

The Laravel application encryption key (`APP_KEY`) is essential to protecting encrypted signing secrets.

If this key is lost, encrypted application data may become unrecoverable. If it is exposed, stored encrypted secrets may be compromised.

It must therefore be:

- Kept outside version control.
- Stored securely in the deployment environment.
- Accessible only to authorized application processes.
- Backed up and managed through a controlled key-management procedure.

Key rotation requires an explicit strategy for handling existing encrypted values.

## 4. Request Validation and Resource Limits

The event ingestion API applies request validation before persisting data.

Current limits include:

| Control | Limit |
|---|---:|
| Maximum request payload | 64 KiB |
| Maximum top-level payload entries | 100 |
| Maximum event type length | 150 characters |
| Maximum idempotency key length | 128 characters |

The idempotency key also uses an allowlist of accepted characters.

These restrictions reduce the risk of unexpectedly large requests and malformed input.

The application validates the payload as an array before processing it.

## 5. Idempotency and Data Integrity

RelayGuard enforces uniqueness for the combination of API client and idempotency key.

A repeated submission with identical content is treated as a duplicate.

A repeated key with different content is rejected with HTTP 409 Conflict.

The event fingerprint is calculated using canonicalized JSON and SHA-256.

This prevents associative object key ordering from changing the fingerprint while preserving list ordering.

Event creation and delivery record creation happen within a database transaction.

Jobs are dispatched after transaction commit using `DB::afterCommit()`.

These controls help prevent inconsistent application state and jobs referencing records that were never committed.

## 6. SSRF Protection

Server-Side Request Forgery is one of the primary security concerns for webhook delivery services.

An attacker may attempt to register a destination that causes the application to access internal infrastructure, cloud metadata services, or other restricted network resources.

RelayGuard validates destination URLs before sending outbound requests.

### URL Scheme and Structure

The URL validator:

- Allows HTTP and HTTPS.
- Rejects URLs containing embedded credentials.
- Restricts ports to 80 and 443.
- Applies a maximum URL length.
- Normalizes hostnames.
- Rejects localhost and selected internal hostname suffixes.

### IP Address Validation

The destination validator checks IP literals and DNS resolution results.

It rejects private, loopback, link-local, and selected special-purpose IPv4 and IPv6 ranges.

Hostnames without valid public DNS results are rejected.

When a hostname resolves to multiple addresses, all returned addresses must pass validation.

This prevents accepting a hostname merely because one of its returned addresses is public while another is private or otherwise restricted.

### DNS Rebinding Mitigation

DNS validation alone is insufficient if the HTTP client performs a new DNS lookup when connecting.

RelayGuard uses cURL's `CURLOPT_RESOLVE` to pin the selected validated IP address for the outbound request.

This reduces the possibility that DNS resolution changes between validation and connection establishment.

The original hostname remains the request destination, preserving hostname-based HTTP and TLS behavior.

### HTTP Client Restrictions

The outbound HTTP client applies additional restrictions:

| Control | Configuration |
|---|---|
| Redirects | Disabled |
| Proxy usage | Disabled |
| TLS verification | Enabled |
| Connection timeout | 3 seconds |
| Total request timeout | 10 seconds |
| Maximum response body | 256 KiB |
| Allowed destination ports | 80 and 443 |

Disabling redirects prevents a validated public destination from redirecting the application to an unvalidated internal destination.

Disabling proxies avoids routing requests through an unexpected proxy configuration.

TLS verification protects HTTPS connections against invalid certificates and certain interception attacks.

## 7. Outbound Response Handling

Webhook destinations are external and therefore untrusted.

RelayGuard limits the amount of response data it accepts and stores.

Current limits include:

| Resource | Maximum |
|---|---:|
| HTTP response body | 256 KiB |
| Stored delivery response body | 16 KiB |
| Stored error message | 2 KiB |

The HTTP client enforces the response limit while receiving data, rather than relying exclusively on the response's `Content-Length` header.

This helps protect the application from oversized or unbounded responses.

## 8. Delivery Reliability and Concurrency

Delivery processing uses Redis distributed locks.

Each delivery has a unique lock key, preventing multiple workers from processing the same delivery simultaneously while the lock is held.

The current timing configuration is:

| Mechanism | Duration |
|---|---:|
| Job timeout | 30 seconds |
| Distributed lock | 45 seconds |
| Queue retry-after | 90 seconds |

The application also checks delivery state and retry timestamps before sending a request.

The maximum number of delivery attempts is five.

Retryable failures include network exceptions and HTTP 408, 429, and 5xx responses.

These controls reduce duplicate processing, uncontrolled retries, and excessive outbound traffic.

However, webhook delivery is not an exactly-once transport guarantee. A network failure can occur after the destination has processed a request but before RelayGuard receives its response.

For that reason, consumers should use the delivery ID or another stable event identifier to implement idempotent processing.

RelayGuard also uses a scheduled recovery mechanism
to redispatch eligible pending deliveries that may
have been left without a successfully enqueued job.

The recovery process uses a five-minute dispatch
cooldown to reduce redundant queue dispatching.

The cooldown tracks when a delivery was last dispatched
for queue processing. It does not confirm that a worker
received or completed the job.

The recovery mechanism reduces the risk of deliveries
remaining pending without a queued job, but it does not
provide exactly-once processing or guarantee successful
delivery.

This mechanism does not guarantee exactly-once delivery.
Consumers must continue to implement idempotent
processing.

## 9. Known Limitations and Future Hardening

The current security implementation provides several important defensive controls, but it still has limitations that must be considered before production deployment.

### IP Range Coverage

The IPv4 and IPv6 rejection rules are intentionally conservative.

They should be reviewed against the latest IANA special-purpose address registries and maintained as those registries change.

The current implementation should not be treated as a complete or permanently authoritative representation of every reserved network range.

### DNS and Network Boundaries

IP pinning reduces DNS rebinding risk, but it is not a substitute for network-level isolation.

Production deployments should also consider:

- Egress firewall rules.
- Blocking access to cloud metadata services.
- Restricting access to internal network ranges.
- Network segmentation.
- Monitoring outbound connections.

### HTTP Destinations

HTTP is currently accepted in addition to HTTPS.

HTTP does not provide transport encryption or server authentication.

HTTPS should be required for production webhook destinations whenever possible, especially when payloads contain sensitive information.

### Data Retention and Deletion

The current implementation does not provide an automated
data retention or cleanup mechanism.

Webhook events, delivery response bodies, and error messages
may remain stored indefinitely unless removed through an
authorized database maintenance process.

A configurable retention policy and automated cleanup
mechanism are planned improvements.

Deployments should consider the storage of sensitive
information in event payloads and webhook responses.

### Production Environment Configuration

Production deployments should use the following security
settings:

- Set `APP_ENV=production`.
- Set `APP_DEBUG=false`.
- Use an appropriate production logging level, such as
  `warning`.
- Require HTTPS for application access.
- Set `SESSION_SECURE_COOKIE=true` when serving the
  application over HTTPS.
- Keep the session HTTP-only setting enabled.
  The current application configuration sets this to `true`.
- Use an appropriate `SESSION_SAME_SITE` policy.

When deployed behind a reverse proxy or load balancer,
configure Laravel to trust only the proxy addresses
controlled by the deployment infrastructure.

Forwarded headers must not be trusted from arbitrary
clients.

Application logs must not contain plaintext API keys,
webhook signing secrets, Authorization headers, or
sensitive webhook payloads.

### Additional Controls Not Yet Implemented

The following are potential improvements and should not be considered existing features:

- Per-client delivery quotas.
- Automated credential rotation.
- Dedicated secret management integration.
- Security audit logging.
- Network-level egress enforcement.
- Formal penetration testing.
- Comprehensive integration testing with real Redis workers.

## 10. Security Testing

The automated test suite includes tests for:

- API authentication behavior.
- Request payload size restrictions.
- Public and restricted IP address validation.
- URL scheme and hostname validation.
- DNS resolution checks.
- Secure HTTP client configuration.
- Response body size enforcement.
- Exact outgoing request body preservation.
- HMAC signature generation.
- Delivery concurrency locking.
- Retry limits and delivery state transitions.
- Per-client API rate limiting.
- Pending delivery recovery eligibility and dispatch cooldown.
- Recovery command batch limits and invalid option handling.

Run the test suite with:

```bash
php artisan test
```

Automated tests provide regression protection for implemented controls, but they do not replace a security audit or production environment testing.

## 11. Responsible Disclosure

If a security vulnerability is discovered in RelayGuard, avoid publishing exploit details or sensitive information before the issue can be assessed and addressed.

A dedicated security contact and disclosure process should be established before the project is used in production.