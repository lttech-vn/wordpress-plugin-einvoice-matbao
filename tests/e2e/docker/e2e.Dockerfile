# syntax=docker/dockerfile:1.7
# Runner image: node (e2e needs module.registerHooks) + bun for installs + Chromium with its OS dependencies.
FROM oven/bun:1.4.3@sha256:ec06c3b6cea04192ae6770c434f668ca41d343ad19fa6472216c7b48be39c598 AS deps
WORKDIR /e2e
COPY package.json bun.lock ./
RUN bun install --frozen-lockfile

FROM node:24.8.0-bookworm-slim@sha256:cadbfafeb6baf87eaaffa40b3640209c4b7fd38cebde65059d15bc39cd636b85 AS army
ENV E2E_TELEMETRY_DISABLED=1 PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
WORKDIR /e2e
COPY --from=deps /e2e/node_modules ./node_modules
RUN node node_modules/@e2e-dev/web/dist/bin.js install chromium --with-deps
COPY package.json e2e.army.config.ts ./
COPY army ./army
