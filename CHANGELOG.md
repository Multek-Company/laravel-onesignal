# Changelog

All notable changes to `multek/laravel-onesignal` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-28

### Added

- Native profile properties: the engagement driver now maps the `Customer` DTO's
  `language` (ISO 639-1), `timezone` (IANA id) and `country` (ISO 3166-1 alpha-2)
  fields — first-class in `laravel-customer-engagement` v1.1 — to OneSignal's native
  user properties (`PropertiesObject::setLanguage()` / `setTimezoneId()` / `setCountry()`)
  when non-null ([#3](https://github.com/Multek-Company/laravel-onesignal/issues/3)).
  Profile fields are never written as data tags: tags are plan-limited (Free: 2/user),
  native properties are free on all plans. `Customer::$attributes` still flows to tags.
- `OneSignalManager::updateUser($externalId, $tags, $properties)` to update tags and
  native properties in one call; `OneSignalManager::createUser()` accepts an optional
  third `$properties` argument (`language`, `timezone_id`, `country`).

### Changed

- Requires `multek/laravel-customer-engagement` `^1.1`.

## [1.0.1] - 2026-07-28

### Fixed

- `OneSignalManager::updateUserTags()` now sends the correct `UpdateUserRequest` model to
  `DefaultApi::updateUser()` instead of a `User` model. The malformed request body caused
  OneSignal to upsert users **without any data tags** in production ([#1](https://github.com/Multek-Company/laravel-onesignal/issues/1)).
- `updateUserTags()` and `removeUserTags()` now declare the return type the SDK actually
  returns (`PropertiesBody` instead of `User`), fixing a `TypeError` thrown even on
  successful HTTP calls.

### Upgrade notes

If you sync users through the `laravel-customer-engagement` driver, after updating:

```bash
composer update multek/laravel-onesignal
```

re-run your user sync backfill so existing users get their tags applied — the sync is
idempotent, so re-running it is safe.

## [1.0.0] - 2026-04-01

- Initial release: OneSignal manager, notification builder, Laravel notification channel,
  custom events, user management, and `laravel-customer-engagement` driver integration.
