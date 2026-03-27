# Docker And Deploy

This directory now includes the first production-oriented deployment assets:

- [stagehand.Dockerfile](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/stagehand.Dockerfile) for a browser-capable Stagehand image
- [php-fpm.Dockerfile](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/php-fpm.Dockerfile) plus [php-fpm-entrypoint.sh](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/php-fpm-entrypoint.sh) for the example Laravel app
- [docker-compose.example.yml](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/docker-compose.example.yml) to run Laravel + Nginx + Stagehand + Redis together
- [nginx/canio.conf](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/nginx/canio.conf) to serve the app and proxy Stagehand health/metrics
- [systemd/stagehand.service](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/systemd/stagehand.service) plus [systemd/stagehand.env.example](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/systemd/stagehand.env.example) for host deployments

## Local Container Stack

1. Bootstrap the example app:

```bash
./examples/laravel-app/create-project.sh
```

2. Start the stack:

```bash
docker compose -f docker/docker-compose.example.yml up --build
```

3. Open:

- app: [http://127.0.0.1:8080](http://127.0.0.1:8080)
- Stagehand health: [http://127.0.0.1:8080/stagehand/healthz](http://127.0.0.1:8080/stagehand/healthz)
- Stagehand metrics: [http://127.0.0.1:8080/stagehand/metrics](http://127.0.0.1:8080/stagehand/metrics)

The example stack uses Redis-backed Stagehand jobs, signed Laravel <-> Stagehand traffic, and webhook push from Stagehand back into Laravel through Nginx.

## Host Deployment

For non-container Linux hosts:

1. Install the Stagehand binary into `/usr/local/bin/stagehand`
2. Copy [systemd/stagehand.service](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/systemd/stagehand.service) to `/etc/systemd/system/stagehand.service`
3. Copy [systemd/stagehand.env.example](/Users/garaekz/Documents/projects/packages/oxhq/canio/docker/systemd/stagehand.env.example) to `/etc/canio/stagehand.env` and set real secrets
4. Run `systemctl daemon-reload && systemctl enable --now stagehand`

Protect `/metrics` and `/healthz` at the network or reverse-proxy layer if they should not be public.
