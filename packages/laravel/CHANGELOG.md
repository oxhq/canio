# Changelog

## [1.0.3] - 2026-04-29

Renderer and runtime packaging hardening.

- made the Rod-backed Stagehand renderer the default local renderer
- added managed Chrome for Testing bundle install, repair, doctor, and serve integration
- kept `local-cdp` as an explicit fallback and `remote-cdp` as the production remote-browser path
- added Rod-native render coverage for PDF output, readiness, debug artifacts, and failed URL status handling

For full monorepo release notes, see:

- [oxhq/canio release notes](https://github.com/oxhq/canio/blob/main/docs/releases/v1.0.3.md)

## [1.0.2] - 2026-04-24

Runtime compatibility patch.

- aligned the default Stagehand runtime release with the Laravel command surface
- rejected stale Stagehand binaries that do not expose required serve flags
- fixed Windows binary resolution for absolute drive-letter paths
- isolated Chromium profiles per Stagehand runtime process
- fixed browser-pool acquire-timeout cleanup races

For full monorepo release notes, see:

- [oxhq/canio release notes](https://github.com/oxhq/canio/blob/main/docs/releases/v1.0.2.md)

## [1.0.1] - 2026-03-29

First post-launch patch release.

- refreshed GitHub Actions workflows onto Node 24-compatible action versions
- published public docs at `https://oxhq.github.io/canio/`
- added a production deployment guide for embedded versus remote runtime modes
- added release verification for tag-to-distribution launches
- aligned Packagist metadata with the public docs surface

For full monorepo release notes, see:

- [oxhq/canio release notes](https://github.com/oxhq/canio/blob/main/docs/releases/v1.0.1.md)

## [1.0.0] - 2026-03-29

First public GA release of the Laravel package.

- public Composer install path for `oxhq/canio`
- embedded runtime bootstrap and runtime install command
- browser-grade rendering with explicit readiness and JS support
- debug artifacts and async runtime operations

For full monorepo release notes, see:

- [oxhq/canio release notes](https://github.com/oxhq/canio/blob/main/docs/releases/v1.0.0.md)
