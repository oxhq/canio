# Canio Architecture

## Product Shape

Canio ships as one product with two implementation layers:

1. `packages/laravel` owns the public API, Laravel integration, install story, and storage-oriented ergonomics.
2. `runtime/stagehand` owns execution, runtime state, and the document-render contract.

## Package Responsibilities

- fluent builder for `view`, `html`, and `url`
- Laravel-native `save`, `download`, and `stream`
- runtime installation and health tooling
- profile selection and package defaults
- host-app friendly testing seams

## Runtime Responsibilities

- accept `RenderSpec`
- expose runtime status and restart endpoints
- produce `RenderResult`
- render through a pluggable browser driver while preserving the `RenderSpec` to `RenderResult` contract
- manage browser pooling, artifacts, replay, async jobs, dead letters, cleanup, and runtime observability

## Renderer Drivers

Stagehand owns renderer selection. The Laravel package passes renderer configuration into Stagehand, but Laravel code should not depend on a specific browser library.

- `rod-cdp` is the default native-Go local driver. Rod owns local browser launch, cleanup, document preparation, readiness waiting, PDF generation, and debug artifact capture while Stagehand keeps the same artifacts, queues, and Laravel contract.
	Current runtime tests cover driver selection, startup/cleanup lifecycle, pool slot reuse/eviction behavior, cross-driver PDF smoke parity with `local-cdp`, and Rod-native screenshot, DOM snapshot, and console capture.
	Rod-specific failure-mode behavior should still be treated as evolving until wider CI/platform coverage is expanded.
- `local-cdp` is the direct CDP fallback driver. It launches a local Chrome/Chromium process from Stagehand and controls it through Chrome DevTools Protocol.
- `remote-cdp` connects Stagehand to an already-running Chrome/CDP endpoint, such as browserless, a containerized Chrome, an internal render farm, or a future Canio Cloud renderer.

Future drivers can be added behind the same contract when there is evidence they improve packageability or fidelity. Rod is now evaluated as a Stagehand implementation detail, not as a separate Laravel-facing runtime contract.

## Current Packageability Gaps

- local Chromium/Chrome dependencies, fonts, sandboxing, and OS packages remain part of production deployment
- Linux shared-library and font dependencies are still the host operator's responsibility
- browser bundle update policy is explicit command-driven, not automatic

The goal is to keep Canio's public Laravel API stable while Stagehand gets better at installing, inspecting, replacing, or remotely delegating the browser runtime.
