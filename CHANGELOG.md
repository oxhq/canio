# Changelog

All notable changes to Canio are documented in this file.

## [1.0.5] - 2026-05-14

Release hardening patch for the current Rod-backed runtime line.

Highlights:

- made `php artisan canio:install` idempotent for existing managed runtime and browser bundles
- made embedded runtime doctor failures explicit, including Redis backend diagnostics
- cleaned stale Rod Chromium profile locks across both old and current profile layouts
- made release smoke defaults derive from the package version and work under Windows `composer.bat`
- tightened release-surface checks so stale public docs and site release links fail before tag publication

## [1.0.1] - 2026-03-29

First post-launch patch release.

Highlights:

- refreshed GitHub Actions workflows onto Node 24-compatible action versions
- added a public docs site at `https://oxhq.github.io/canio/`
- added a production deployment guide for embedded versus remote runtime modes
- added a release verification lane for tag, split, assets, and Packagist distribution
- aligned package metadata so Packagist can point at the public docs surface

## [1.0.0] - 2026-03-29

First public GA launch of the Laravel package.

Highlights:

- public Composer install path for `oxhq/canio`
- embedded Stagehand runtime bootstrap with release-based binary install
- browser-grade rendering with explicit readiness and runtime JavaScript support
- debug artifacts, async jobs, replay, and runtime operations
- reproducible benchmark harnesses for fidelity, fairness, and JS capability
- monorepo release flow with Laravel package split distribution

## [0.1.x]

Internal and pre-launch iterations that informed the public `1.0.0` release.
