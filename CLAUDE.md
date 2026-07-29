# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

Standalone Laravel wrapper for the official OneSignal PHP SDK. Owns the full OneSignal lifecycle: user profiles (identity + native properties + Email/SMS subscriptions), segmentation tags, custom events, and push notifications.

Key behaviors:
- **Disabled mode**: when `ONESIGNAL_ENABLED=false` or `onesignal.app_id` is empty, every operation is a silent no-op with a `Log::debug` line — local/test environments need zero keys and produce zero HTTP calls.
- **Event guard**: `ONESIGNAL_TRACK_EVENTS` (default `false`) gates custom events — the OneSignal Free plan rejects them with 403.
- **Sync is a single upsert**: `syncToOneSignal()` makes one `createUser` call carrying tags + native properties (language/timezone/country) + Email/SMS subscriptions. No try/catch control flow.
- **Tags are custom segmentation only** — identity fields (email/phone/name) are never auto-copied into tags. Email/phone become native subscriptions instead.

## Development Commands

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run a single test file
./vendor/bin/pest tests/Feature/SomeTest.php

# Run a specific test by name
./vendor/bin/pest --filter "test name"

# Live smoke tests (against the real API; skipped without credentials)
ONESIGNAL_TEST_APP_ID=... ONESIGNAL_TEST_REST_API_KEY=... ./vendor/bin/pest tests/Live

# Code style fix
./vendor/bin/pint
```

## Directory Structure

```
src/
├── Builders/              # NotificationBuilder (fluent payload builder)
├── Channels/              # OneSignalChannel (Laravel notification channel)
├── Commands/              # onesignal:backfill console command
├── Concerns/              # HasOneSignal trait (model contract)
├── Events/                # NotificationSent / NotificationFailed
├── Facades/               # OneSignal facade
├── Jobs/                  # SyncUserToOneSignal (queued sync)
├── Messages/              # OneSignalMessage
├── OneSignalManager.php   # Core API wrapper (enabled gate lives here)
└── OneSignalServiceProvider.php

config/onesignal.php       # enabled, app_id, rest_api_key, track_events, sync_model, default_tags, queue
tests/                     # Unit + Feature (mocked) + Live (env-gated, real API)
```

## Architecture

- **Namespace**: `Multek\OneSignal`
- **ServiceProvider**: `Multek\OneSignal\OneSignalServiceProvider`
- **Facade**: `Multek\OneSignal\Facades\OneSignal`
- No cross-package dependencies — uses only illuminate components and the official `onesignal/onesignal-php-api` SDK.
- The enabled/disabled gate lives inside `OneSignalManager` (single choke point); the facade, trait, channel, jobs, and backfill command all inherit it.

> History: v1.x implemented driver contracts for `multek/laravel-customer-engagement`. That dependency was removed in v2.0.0 (see UPGRADE.md); the abstraction is deprecated and frozen at v1.1.0.

## Testing Guidelines

- Pest framework with Orchestra Testbench
- Mock OneSignal API calls (`Mockery::mock(DefaultApi::class)`) and assert real SDK payloads via `withArgs`
- `tests/Live/` runs the full user lifecycle against the real API — it is the acceptance test for the createUser upsert assumption; keep it green before releases
