# RelayGuard Deployment Guide

## 1. Deployment Overview

This document describes the requirements and operational steps for deploying RelayGuard in a production-like environment.

RelayGuard uses Laravel, PostgreSQL, and Redis to receive webhook events, persist delivery records, and process outbound webhook requests asynchronously.

A production deployment requires the application, database, Redis instance, queue worker, and Laravel task scheduler to be configured and running correctly.

This guide describes deployment requirements and recommended operational practices. It does not represent a deployment that has been independently validated in a production environment.

## 2. Production Requirements

The deployment environment should provide:

| Component | Requirement |
|---|---|
| PHP | 8.3 or later |
| Composer | Compatible with the project dependencies |
| PostgreSQL | Supported application database |
| Redis | Cache, distributed locks, rate limiting, and queue backend |
| Web server | Nginx, Apache, or an equivalent platform |
| Process manager | Supervisor, systemd, or platform-managed processes |
| HTTPS | Required for secure application access |

The application has been tested with PHP 8.5, PostgreSQL 17, and Redis 7.

## 3. Application Configuration

### Dependency Installation

Install PHP dependencies using Composer:

```bash
composer install --no-dev --optimize-autoloader
```

Configure the production environment before starting the application.

Recommended settings include:

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning

CACHE_STORE=redis
CACHE_LIMITER=redis
QUEUE_CONNECTION=redis

SESSION_SECURE_COOKIE=true
```

Configure the database and Redis connection settings according to the deployment infrastructure.

Generate the application key securely when preparing a new application environment:

```bash
php artisan key:generate
```

Do not regenerate an existing production application key without a planned key migration procedure. Existing encrypted webhook signing secrets depend on this key.

Cache the production configuration after setting
the environment variables:

```bash
php artisan config:cache
```

Ensure that the application can write to the
storage and bootstrap/cache directories.

Never commit `.env` files or expose application credentials, API keys, or webhook signing secrets.

## 4. Database Setup and Migrations

Create the production PostgreSQL database and configure the application database credentials.

Run migrations using:

```bash
php artisan migrate --force
```

Verify the migration status:

```bash
php artisan migrate:status
```

Database backups should be configured and periodically tested.

## 5. Redis Configuration

RelayGuard uses Redis for:

- Queue job storage.
- Distributed delivery locks.
- API rate limiting.
- Cache operations.

Configure Redis connectivity and credentials through the application environment.

Verify the Redis connection before starting queue workers.

Redis should not be exposed directly to untrusted networks. Restrict access to the application infrastructure and configure authentication and network controls as appropriate.

## 6. Queue Worker Configuration

Outbound webhook deliveries are processed asynchronously.

Start a queue worker with:

```bash
php artisan queue:work redis
```

The worker must remain running continuously.

For production, run the worker under a process manager such as Supervisor, systemd, or a platform-managed process service.

The process manager should restart the worker if it exits unexpectedly.

After deploying new application code, restart workers gracefully:

```bash
php artisan queue:restart
```

The queue worker must have sufficient resources and permissions to execute application jobs.

Configure the process manager to run the worker using
the same application environment and deployment release
as the web application.

Worker processes should be restarted after deployments
to ensure that they load the updated application code.

## 7. Task Scheduler Configuration

RelayGuard uses Laravel's scheduler to recover eligible pending webhook deliveries.

The recovery command is scheduled to run every minute.

Start the scheduler using:

```bash
php artisan schedule:work
```

For production environments, configure the system scheduler to invoke Laravel's scheduler every minute:

```cron
* * * * * cd /path/to/relayguard && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/path/to/relayguard` with the actual application directory.

The scheduler and queue worker serve different purposes: the scheduler dispatches eligible pending deliveries, while queue workers process dispatched jobs.

## 8. Web Server and HTTPS

Configure the web server document root to point to the Laravel `public` directory.

Do not expose the application root, `.env` file, storage directory, or other internal application files through the web server.

Use HTTPS for application access.

When deploying behind a reverse proxy or load balancer, configure Laravel to trust only the proxy addresses controlled by the infrastructure.

Do not trust forwarded headers from arbitrary clients.

## 9. Network and Security Considerations

RelayGuard sends outbound HTTP requests to webhook destinations.

Production infrastructure should consider:

- Restricting outbound network access.
- Blocking access to cloud metadata services.
- Preventing access to internal network ranges.
- Monitoring outbound traffic.
- Restricting access to PostgreSQL and Redis.
- Protecting application credentials and encryption keys.
- Using HTTPS for webhook destinations whenever possible.

Application-level destination validation does not replace network-level egress controls.

## 10. Monitoring and Failed Jobs

Monitor the following operational components:

- Application availability.
- PostgreSQL connectivity and storage.
- Redis availability and memory usage.
- Queue worker process health.
- Failed queue jobs.
- Pending webhook deliveries.
- Application errors and delivery failures.

Inspect failed jobs with:

```bash
php artisan queue:failed
```

Retry failed jobs when appropriate:

```bash
php artisan queue:retry all
```

Review delivery records and application logs when investigating unsuccessful webhook deliveries.

Do not assume that retrying a queue job guarantees successful delivery.

## 11. Deployment Verification

After deployment, verify the following:

1. The application responds successfully through the configured HTTPS endpoint.
2. Database connectivity is working.
3. Redis connectivity is working.
4. Database migrations are up to date.
5. Queue workers are running.
6. The Laravel scheduler is running.
7. The recovery command is registered:

```bash
php artisan schedule:list
```

8. API client authentication works.
9. An authorized webhook endpoint has been provisioned.
10. A test event can be accepted and delivered successfully.

Endpoint registration must be completed through an
authorized administrative or local provisioning process.

The current version does not expose a public API or
Artisan command for endpoint registration.

A successful API response confirms event ingestion, not necessarily successful outbound delivery.

## 12. Operational Limitations

The current project does not include:

- A production Dockerfile or container orchestration configuration.
- A bundled Supervisor or systemd configuration.
- Automated infrastructure provisioning.
- A built-in administrative interface for endpoint management.
- Automated data retention and cleanup.
- A complete production monitoring and alerting stack.

These capabilities require deployment-specific configuration or future development.

Production readiness should be assessed against the actual infrastructure, operational requirements, security controls, and recovery procedures of the deployment environment.