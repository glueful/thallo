# Security Policy

## Reporting a vulnerability

**Do not open a public issue for security reports.** Email the maintainer (see
`composer.json` authors) with a description and, where possible, a reproduction. You will get
an acknowledgement, and a fix lands as an immutable release (`beta.N+1` during the Developer
Preview) with the issue credited if you wish.

## Supported versions

During the Developer Preview, only the **latest** `1.0.0-beta.N` release receives fixes.
From `1.0.0`, the latest minor receives security fixes.

## Deployment posture that matters

Thallo's security-sensitive engineering assumes the documented production posture — in
particular: `APP_ENV=production`, real generated keys, HTTPS with a canonical `BASE_URL`,
compiled-state clearing on upgrade, and the log-redaction settings described in
[docs/production.md](docs/production.md). Bearer credentials (payment-link tokens) are
custody-controlled inside the application (hash-only storage, redacted logging, structural
egress limits); reverse-proxy and CDN access logs are outside the application and remain the
operator's responsibility (redaction recipes ship in `packages/thallo-commerce/README.md`).
