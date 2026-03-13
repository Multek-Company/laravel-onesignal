# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

Laravel wrapper for the official OneSignal PHP SDK. Implements the `laravel-customer-engagement` driver contracts for OneSignal as the push notification provider.

## Development Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Feature/SomeTest.php

# Run a specific test by name
./vendor/bin/pest --filter "test name"

# Code style fix
./vendor/bin/pint
```

## Directory Structure

```
src/
├── Builders/              # Notification payload builders
├── Channels/              # Laravel notification channels
├── Concerns/              # Shared traits
├── Events/                # Laravel events
├── Facades/               # OneSignal facade
├── Jobs/                  # Async jobs
├── Messages/              # Message types
├── OneSignalDriver.php    # Customer engagement driver implementation
├── OneSignalManager.php   # Main manager class
└── OneSignalServiceProvider.php

tests/                     # Test suite
```

## Architecture

- **Namespace**: `Multek\OneSignal`
- **ServiceProvider**: `Multek\OneSignal\OneSignalServiceProvider`
- **Facade**: `Multek\OneSignal\Facades\OneSignal`
- **Dependency**: Requires `multek/laravel-customer-engagement` — implements its driver contracts
- Uses the official `onesignal/onesignal-php-api` SDK

### Cross-Package Dependency

This package depends on `laravel-customer-engagement`. For local development, the `composer.json` includes a path repository pointing to `../laravel-customer-engagement`.

## Testing Guidelines

- Use Pest framework with Orchestra Testbench
- Mock OneSignal API calls
- Test notification building, channel dispatch, and driver contract compliance
