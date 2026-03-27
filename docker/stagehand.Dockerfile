FROM golang:1.26-bookworm AS build

WORKDIR /src

COPY runtime/stagehand/go.mod runtime/stagehand/go.sum ./runtime/stagehand/
RUN cd runtime/stagehand && go mod download

COPY runtime/stagehand ./runtime/stagehand

RUN cd runtime/stagehand && \
    CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="-s -w" -o /out/stagehand ./cmd/stagehand

FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        chromium \
        fonts-liberation \
        fonts-noto-cjk \
        tini && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/lib/canio

COPY --from=build /out/stagehand /usr/local/bin/stagehand

EXPOSE 9514
VOLUME ["/var/lib/canio/state"]

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/stagehand"]
CMD ["serve", "--host", "0.0.0.0", "--port", "9514", "--chromium-path", "/usr/bin/chromium", "--state-dir", "/var/lib/canio/state", "--log-format", "json"]
