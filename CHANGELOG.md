# Changelog

## 0.5.1 (2026-11-03)

### Fixed

- **`gcdn:update` now reaches a new minor version.** Terminus records the plugin at install time as `^0.4.0`, which for 0.x versions stops below 0.5.0, so `terminus self:plugin:update` kept 0.4.0 while `gcdn:update` reported "Updated to v0.5.0". It now checks the installed version afterwards (`terminus self:plugin:list`), reinstalls the plugin when the version didn't change, and only reports an update it has confirmed. If the reinstall fails, it puts the previous version back, prints the manual steps, and exits `1`.
- **A local checkout is left alone:** when the plugin is installed from a path repository or a `dev-` branch, `gcdn:update` doesn't uninstall it.

### Upgrading from 0.4.0 or earlier

Your installed `gcdn:update` still has the old behavior, so reinstall once:

```
terminus self:plugin:uninstall pantheon-systems/terminus-gcdn-plugin
terminus self:plugin:install pantheon-systems/terminus-gcdn-plugin
```

From 0.5.0, `terminus gcdn:update` reaches 0.5.1 (same minor version), and from 0.5.1 on it handles every later version.

## 0.5.0 (2026-11-03)

Next-gen GCDN readiness (EDRT-9618, EDRT-9789) and User-Agent bypass entries (EDRT-9784).

### Added

- **`gcdn:ua-bypass:list`, `gcdn:ua-bypass:add` and `gcdn:ua-bypass:remove`** manage a domain's User-Agent bypass entries: requests whose `User-Agent` exactly equals an entry skip the GCDN bot challenge on that domain, for monitors and tools that can't send a bot-bypass token. Up to 8 entries per domain; browser-like User-Agents need `--allow-browser-ua`. See the README.

### Changed

- **`gcdn:verify` rechecks the domain** (like Force Recheck in the dashboard) for GCDN custom domains that aren't active yet, then shows the status. It rechecks a domain at most every 5 minutes from your machine (a run sooner shows the latest poll and how long until a force recheck can run, as with `--no-recheck`), and only once a record Cloudflare checks is in DNS (or the domain is ready or paused); `--recheck` skips both limits. `--no-recheck` only shows the status. `--method` now switches the validation method through the same recheck; before, Cloudflare ignored it.
- **Exit codes:** `gcdn:verify`, `gcdn:dns` and `gcdn:o2o` exit `1` when a domain isn't ready, isn't found, or its status is unavailable. `gcdn:challenge` exits `1` when a toggle fails. All of them used to exit `0`. Update any scripts that depend on the old behavior.
- **New "DNS records ready (confirmed by Pantheon)" state** for domains served through Cloudflare today (your own Cloudflare account or your current host). It shows every record, Cloudflare's errors and the ways to switch DNS in step 1's "Issues and Notes", and, in step 3, what to run after the switch. Before the matching Pantheon backend change deploys, these domains show as pending.
- **The certificate validation record is now the DCV delegation CNAME** (`_acme-challenge.<domain>`), never the rotating TXT value. The CNAME comes from the API when Pantheon provides it, and otherwise from the plugin's zone table.
- **"Ownership verified, certificate pending"** is shown separately, with the certificate errors.
- **Three step tables** in `gcdn:verify`, `gcdn:dns` and `gcdn:o2o` (ownership, certificate, traffic), then one "Next:" line. Each table's title is in its status color: red until done, yellow for a caution, green when done. Each record has a Type / Name / Value row with an "Action required" row under it, and "Issues and Notes" holds Cloudflare's errors, word for word, with what can be done. Names and values only wrap when the terminal is too narrow for them, so they copy in one piece. `gcdn:verify` looks up the step 1 and 2 records and says whether each is found, missing, or set to a different value, including TXT records at `_acme-challenge` that conflict with the CNAME. The state is light blue (green once active), and Cloudflare's own status values are green, yellow or red.
- **Step 3 (traffic)** shows option 1, the CNAME, and option 2, the A and AAAA records, each with what DNS returns today. For a domain proxied by Cloudflare, it says the addresses are Cloudflare's own (a local check against Cloudflare's published ranges), and that in your own Cloudflare account the CNAME is the option to use, after deleting the A and AAAA records.
- **`--cert-txt`** on `gcdn:verify` shows the certificate's `_acme-challenge` TXT record instead of the DCV CNAME, for DNS that can't take the CNAME. Its value changes with every certificate order. Step 2 mentions it while the CNAME isn't in place.
- **The DCV CNAME's renewal advice** follows DNS: keep it, add it, or change it if a proxy sits in front of Pantheon, and recommended anyway as insurance for renewals.
- **New "Action required" state** when Cloudflare reports a status that adding records can't fix (blocked, being deleted, or unknown): "Don't switch DNS. Contact Pantheon support." No recheck is sent, and `gcdn:challenge` doesn't change these domains.
- **Zone hold:** a domain blocked because its zone, in the owner's own Cloudflare account, has a zone hold says so, and says that only the domain owner can release it (not Pantheon support). After the release, `gcdn:verify` rechecks it (with the usual 5-minute cooldown) but sends no verify-ownership request. `domain:dns` lists these domains in their own footer.
- **Cloudflare's paused checks (`moved`)** are shown with a warning, and **`deleted`** with a red one: `gcdn:verify`'s recheck restores both. A ready domain stays ready only while Cloudflare still reports it pending (or paused) and its certificate is active.
- **`gcdn:challenge`** no longer changes domains whose DNS records are ready, never switches a domain served through Cloudflare to HTTP, and never changes platform domains. After a toggle it reads the records again.
- **`gcdn:o2o`** uses the same step tables, read-only (no recheck, no DNS lookups), with the proxied CNAME alone in step 3. It opens the traffic step once ownership is confirmed. It no longer lends one domain's zone to another, and it shows customer-safe text for an unknown zone.
- **Platform domains** (`*.pantheonsite.io`) show "Managed by Pantheon", with no records and no requests.
- **A blank CDN status** reads "status unavailable", not "not on GCDN".
- **Domain names** match regardless of case.
- **Colors** are only written to a terminal; `--no-ansi` and piped output are plain.
- **`gcdn:upgrade`** no longer reports an error as "already migrated". It fails with the API's status and message.
- **Requires Terminus 3.2 or later** (it already did in practice).
- **Smaller package:** tests and CI files are left out of what Composer installs.

### Fixed

- `gcdn:verify` no longer sends the verify request for Fastly or platform domains, and an error from that request no longer hides the status.

## 0.4.0

See the GitHub release.
