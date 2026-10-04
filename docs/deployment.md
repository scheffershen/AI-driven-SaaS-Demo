# Docker deployment

This deliverable is ready to run locally and package for a PHP/container host. The user selected a Docker package for now; no public hostname or hosting account has been configured.

1. Copy or clone this standalone project on a server with Docker Compose.
2. Build and start with `docker compose -f compose.yaml -f compose.production.yaml up -d --build`.
3. Put a TLS reverse proxy in front of `127.0.0.1:8094`. Keep the application port bound to localhost. Route the whole origin, including `/media`, to that container; do not expose the data volume as static files.
4. Retain the `workspace-data` volume. The entrypoint generates its application secret and stores it in the volume. Use your host's normal protected backup mechanism if retention beyond the demo's expiry is needed.
5. Schedule `docker compose exec -T app php bin/console app:cleanup` hourly. Creation also removes expired workspaces, but an idle host still needs its scheduled cleanup.
6. Verify `/health`, a fresh workspace, manual draft creation, a valid MP4 upload, ownership isolation, and cleanup over the HTTPS origin.
7. Enable native browser WebMCP for the test client or enroll the actual HTTPS origin in the browser's origin trial. This package does not include an origin-trial token; it must be obtained for the selected hostname.

Production Compose turns on secure session cookies, so use HTTPS. A plain HTTP local test should use the default development Compose file.

Set `TRUSTED_PROXIES` to the exact proxy IP or narrowly scoped CIDR as seen by Apache before starting production Compose. The proxy must overwrite `X-Forwarded-For` and set `X-Forwarded-Proto`/`X-Forwarded-Port`. Unconfigured or untrusted forwarding headers are ignored; a configured proxy preserves separate visitor creation quotas. Do not use a blanket trust of all remote addresses. See [Symfony proxy configuration](https://symfony.com/doc/7.4/deployment/proxies.html).

For public operation, keep the image and dependencies updated and monitor the named volume. Quotas prevent unbounded normal upload storage but do not replace your hosting provider's resource limits. The demo has no durable customer accounts and does not publish videos. A real SaaS must connect the shared operation to its own authentication, tenant authorization and business rules.

Do not use `docker compose down -v` unless you intend to erase the demo volume and secret. Ordinary `docker compose down` preserves them.
