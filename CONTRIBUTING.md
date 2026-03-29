# Contributing

## Ground Rules

- keep the public product story accurate
- do not widen claims beyond what tests and benchmarks prove
- keep the Laravel package API Laravel-first
- keep contributor workflow details out of public install docs

## Local Setup

Use the contributor guide:

- [docs/development.md](docs/development.md)

Core commands:

```bash
make test
make build-stagehand
make example-app
```

## Pull Requests

- keep changes scoped
- include tests when behavior changes
- update docs when public behavior, install flow, or positioning changes
- do not merge benchmark-driven claims unless the harnesses support them

## Release Notes

If your change affects public behavior, installation, or release mechanics, update:

- [CHANGELOG.md](CHANGELOG.md)
- relevant README content
- benchmark or development docs when needed
