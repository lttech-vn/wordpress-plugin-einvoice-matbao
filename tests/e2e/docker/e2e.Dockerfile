# syntax=docker/dockerfile:1.7
# Runner image: node (e2e needs module.registerHooks) + bun for installs + Chromium with its OS dependencies.
FROM oven/bun:1.4.3@sha256:ec06c3b6cea04192ae6770c434f668ca41d343ad19fa6472216c7b48be39c598 AS deps
WORKDIR /e2e
COPY package.json bun.lock ./
RUN bun install --frozen-lockfile

FROM node:24.8.0-bookworm-slim@sha256:cadbfafeb6baf87eaaffa40b3640209c4b7fd38cebde65059d15bc39cd636b85 AS army
ENV E2E_TELEMETRY_DISABLED=1 PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
ARG TARGETARCH
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl libstdc++6 && rm -rf /var/lib/apt/lists/*
# Lightpanda 1.0.2, pinned by sha256 per architecture.
RUN case "$TARGETARCH" in \
	amd64) file=lightpanda-x86_64-linux; sum=ae7d01d261efb9e480e18ce3424533332883863bcf70b12a4321b5733f37e19e ;; \
	arm64) file=lightpanda-aarch64-linux; sum=ac546dfc43c533430da92b75ab36f2a29da7d6324d5d38baf606ef2e0d849345 ;; \
	*) echo "unsupported arch $TARGETARCH" >&2; exit 1 ;; esac \
	&& curl -fsSL -o /usr/local/bin/lightpanda "https://github.com/lightpanda-io/browser/releases/download/1.0.2/$file" \
	&& echo "$sum  /usr/local/bin/lightpanda" | sha256sum -c - && chmod +x /usr/local/bin/lightpanda
WORKDIR /e2e
COPY --from=deps /e2e/node_modules ./node_modules
RUN node node_modules/@e2e-dev/web/dist/bin.js install chromium --with-deps
COPY package.json e2e.army.config.ts ./
COPY army ./army
