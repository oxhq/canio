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
- evolve toward browser pooling, artifacts, replay, and post-processing

## Intentional Gaps In This First Cut

- Chromium/CDP orchestration is not wired yet
- job queue lifecycle is modeled in the contract but not executed asynchronously yet
- artifacts, replay, and watch are represented in the spec, not fully implemented

The goal of this scaffold is to lock the repo shape and public contracts first, so the implementation can grow without rewriting the product surface.
