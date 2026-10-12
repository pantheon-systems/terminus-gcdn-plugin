# Changelog

## 0.5.0-beta.1 (unreleased, `next` branch)

Next-gen GCDN readiness (EDRT-9618). A pre-release: `gcdn:update` and Composer's default stability don't install it.

### Changed

- **`gcdn:verify` rechecks the domain** (like Force Recheck in the dashboard) for GCDN custom domains that aren't active yet, then shows the status. It rechecks a domain at most once every 5 minutes from your machine. `--no-recheck` only shows the status. `--method` now switches the validation method through the same recheck; before, Cloudflare ignored it.
- **Exit codes:** `gcdn:verify`, `gcdn:dns` and `gcdn:o2o` exit `1` when a domain isn't ready, isn't found, or its status is unavailable. `gcdn:challenge` exits `1` when a toggle fails. All of them used to exit `0`. Update any scripts that depend on the old behavior.
- **New "DNS records ready (confirmed by Pantheon)" state** for domains served through Cloudflare today (your own Cloudflare account or your current host). It shows every record and the ways to switch DNS. Before the matching Pantheon backend change deploys, these domains show as pending.
- **The certificate validation record is now the DCV delegation CNAME** (`_acme-challenge.<domain>`), never the rotating TXT value. The CNAME comes from the API when Pantheon provides it, and otherwise from the plugin's zone table.
- **"Ownership verified, certificate pending"** is shown separately, with the certificate errors.
- **`gcdn:challenge`** no longer changes domains whose DNS records are ready, never switches a domain served through Cloudflare to HTTP, and never changes platform domains. After a toggle it reads the records again.
- **`gcdn:o2o`** opens the traffic step once ownership is confirmed. It no longer lends one domain's zone to another, and it shows customer-safe text for an unknown zone.
- **Platform domains** (`*.pantheonsite.io`) show "Managed by Pantheon", with no records and no requests.
- **A blank CDN status** reads "status unavailable", not "not on GCDN".
- **Domain names** match regardless of case.
- **Colors** are only written to a terminal; `--no-ansi` and piped output are plain.
- **`gcdn:upgrade`** no longer reports an error as "already migrated". It fails with the API's status and message.
- **Requires Terminus 3.2 or later** (it already did in practice).

### Fixed

- `gcdn:verify` no longer sends the verify request for Fastly or platform domains, and an error from that request no longer hides the status.

## 0.4.0

See the GitHub release.
