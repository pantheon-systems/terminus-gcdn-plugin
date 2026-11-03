# Terminus GCDN Plugin

[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#actively-maintained)

A Terminus plugin for upgrading a site to GCDN with bot protection, managing the DNS migration for your existing domains, and retrieving bot-bypass tokens for your own monitors and automation.

## Installation

To install this plugin using Terminus 3.2 or later:

```
terminus self:plugin:install pantheon-systems/terminus-gcdn-plugin
```

## Usage

If you have existing custom domains on your site, follow all of the steps below to upgrade and migrate your DNS.

### 1. Upgrade your site to GCDN

```
terminus gcdn:upgrade <site>
```

This migrates the site from Fastly to GCDN across all environments.

### 2. Get your DNS records

```
terminus gcdn:dns <site>.live
```

For each domain this shows its status and the records it needs:

- **TXT `_cf-custom-hostname.<domain>`:** proves you own the domain. You can remove it once the domain is active.
- **CNAME `_acme-challenge.<domain>`:** lets Cloudflare issue and renew the certificate (DCV delegation). Set it to DNS only (not proxied). If you use another proxy in front of Pantheon (Cloudflare or otherwise), keep it in place: without it, your certificate can fail to renew. If you don't, we still recommend keeping it: it's extra insurance that nothing can interfere with certificate renewals. Once steps 1 and 2 are done, `gcdn:verify` checks DNS for it and words this for what it finds.
- **Traffic records:** the CNAME, or the A/AAAA records, that point the domain to Pantheon.

### 3. Add the TXT and `_acme-challenge` CNAME records

Add them at your DNS provider. Remove any existing `_acme-challenge.<domain>` TXT records first; they conflict with the CNAME.

### 4. Verify each domain

```
terminus gcdn:verify <site>.live example.com
terminus gcdn:verify <site>.live www.example.com
```

`gcdn:verify` asks Cloudflare to check the domain again now (like **Force Recheck** in the dashboard), then shows its status. Pantheon sees the result within about 10 minutes; run the command again to see it. The command only rechecks once there's something new to check: the ownership TXT or certificate CNAME is in DNS with the value shown, or the domain points to Pantheon. It rechecks a domain at most every 5 minutes from your machine (a run sooner shows the latest poll and how long until a force recheck can run, as with `--no-recheck`), and not at all once it's active. `--recheck` skips both limits. To only show the status, use `--no-recheck`.

The output lists three steps, each red until it's done and green after: 1. ownership (TXT record), 2. certificate (DCV CNAME), 3. switch traffic to Pantheon. `gcdn:verify` also looks up the step 1 and 2 records in DNS and says whether each is found, missing, or set to a different value. For step 2 it also flags TXT records at `_acme-challenge`, which conflict with the CNAME, and a CNAME pointing to another Cloudflare account's validation (the current host's). A "Next:" line says what to do now.

The status is one of:

- **Ownership and certificate active:** done. Point DNS to Pantheon if you haven't yet.
- **DNS records ready (confirmed by Pantheon):** the domain is served through Cloudflare today (your own Cloudflare account or your current host), and Pantheon has confirmed you own it. Cloudflare still reports its errors (it can't pre-validate a domain that's already on Cloudflare), so step 1's "Issues and Notes" shows them, with "What can be done" and the ways to switch DNS. Step 3's first row says what to run after you switch: wait about 5 minutes for DNS to propagate and run `gcdn:verify` again (with `--recheck` if you ran it in the last 5 minutes).
- **Ownership verified, certificate pending**, or **ownership and certificate pending:** add the records shown, then run `gcdn:verify` again.
- **Action required:** Cloudflare reports a status that adding records can't fix (for example blocked or being deleted). Don't switch DNS; contact Pantheon support. `gcdn:verify` sends no recheck for these domains.
  - **Zone hold:** the exception. The domain's zone, in the owner's own Cloudflare account, has a zone hold that stops Pantheon from adding the domain. The owner releases it on the domain's Overview page in Cloudflare; then `gcdn:verify` rechecks.

If a domain waits about 11 days for the DNS switch, Cloudflare pauses its checks (status `moved`) and marks the domain `deleted` if it stays paused for 7 more days. The command says so. Running `gcdn:verify` restarts the checks, and restores a deleted domain while Cloudflare still lists it.

To switch certificate validation to HTTP as part of the recheck (not possible while the domain is served through Cloudflare):

```
terminus gcdn:verify <site>.live example.com --method=http
```

If your DNS can't take the `_acme-challenge` CNAME (for example, that name is already delegated to another provider), show the certificate's TXT record instead:

```
terminus gcdn:verify <site>.live example.com --cert-txt
```

The TXT value changes with every certificate order, so add it as shown that day. Once traffic points to Pantheon, Cloudflare renews non-wildcard certificates over HTTP without it. If a renewal needs a TXT again, it has a new value: contact Pantheon Support. The CNAME avoids all of this, so use it when you can.

### 5. Update your DNS records

Once the domain is ready or active, point it to Pantheon with one of the two options in the step 3 table (`gcdn:verify` or `gcdn:dns`): option 1, the CNAME, or option 2, the A and AAAA records. "In DNS now" shows what DNS returns today. For a domain proxied by Cloudflare, those are Cloudflare's own addresses, not your records, and the status says "behind Cloudflare". In your own Cloudflare account, use option 1 set to Proxied: delete the A and AAAA records for the name first, since Cloudflare won't add a CNAME next to them.

### Exit codes

`gcdn:verify`, `gcdn:dns` and `gcdn:o2o` exit `0` when every domain they report on is active or ready, and `1` when one isn't ready yet, needs action, isn't found, or its status is unavailable. `gcdn:challenge` exits `1` when a toggle fails. Scripts can rely on this since 0.5.0.

## Orange-to-Orange (O2O) migrations

If your domain is already proxied through Cloudflare (your own Cloudflare zone, orange-clouded), use the O2O flow:

```
terminus gcdn:o2o <site>.live
```

This prints, for each domain, the same three step tables as `gcdn:verify`, to fill in the Cloudflare zone that serves the domain today: the hostname ownership TXT record, the DCV delegation CNAME (DNS only / grey cloud, recommended permanently), and the traffic CNAME, Proxied. Step 3 has only the CNAME: Cloudflare-to-Cloudflare doesn't work with A/AAAA records. The traffic CNAME is ready to switch once Pantheon has confirmed ownership. Unlike `gcdn:verify`, it's read-only: no recheck and no DNS lookups. Pass a domain as a second argument to limit output to one hostname.

## Certificate challenge method

View or change the certificate challenge method (DNS or HTTP) for a domain:

```
terminus gcdn:challenge <site>.live example.com
terminus gcdn:challenge <site>.live example.com --method=http
terminus gcdn:challenge <site>.live example.com --method=dns
```

To switch all eligible Cloudflare domains on an environment at once:

```
terminus gcdn:challenge <site>.live --all --method=http
```

Active domains, and domains whose DNS records are ready, are never modified. HTTP is refused for domains served through Cloudflare today, because the proxy intercepts HTTP validation. Domains already on the requested method are skipped. The command prints a per-domain status line and a summary:

```
  example.com — switched to HTTP
  www.example.com — skipped (already HTTP)
  blog.example.com — skipped (active)

Summary
  Toggled:                  1
  Skipped (active):         1
  Skipped (already http):   1
```

When set to DNS, the command shows the DCV delegation CNAME and the ownership TXT record. When set to HTTP, it shows the cutover instructions and the serve-file challenge token.

Note: A site converge resets the method to the default for the hostname type (custom → DNS, platform → HTTP).

## Bot-bypass tokens

If your site is on GCDN with bot protection, your own uptime monitors, load tests and automation can be exempted from bot challenges by sending a per-site bot-bypass token in a request header. Retrieve the tokens with:

```
terminus gcdn:bot-bypass <site>
```

The command prints two tokens: the **current** token, and a **next** token that becomes valid three months later. Each row shows the window the token is accepted in and the header name to send it in. Switch to the next token on or after its `Valid From` date; both are accepted until the current token expires, so there is never a gap.

One token covers every environment on the site (dev, test, live and all multidevs). The argument is a site name, not `<site>.<env>`.

For CI pipelines and monitoring configuration, use the machine-readable form:

```
terminus gcdn:bot-bypass <site> --format=json
```

Treat these tokens as secrets. Do not commit them or paste them into shared logs. If the token service is unavailable, Terminus retries for up to about a minute before reporting the failure.

## User-Agent bypass entries

When a monitor or tool cannot send a bot-bypass token, you can exempt it by its User-Agent instead. Requests whose `User-Agent` header **exactly** equals one of a domain's entries skip the GCDN bot challenge on that domain. Other bot protections still apply.

```
terminus gcdn:ua-bypass:list <site>.<env> [--hostname=<domain>]
terminus gcdn:ua-bypass:add <site>.<env> <domain> "<user-agent>" [--allow-browser-ua]
terminus gcdn:ua-bypass:remove <site>.<env> <domain> "<user-agent>"
```

- Matching is exact and case-sensitive. Use the client's full User-Agent string, including its version, and update the entry whenever the client's version string changes.

- Entries are per domain and per environment. A domain can have up to 8 entries.

- An entry must be 1 to 256 printable ASCII characters, with no double quote or backslash and no leading or trailing space. Quote it in your shell.

- An entry that looks like a web browser's User-Agent is refused, because any bot imitating that browser would also skip the challenge. If you really need one, `--allow-browser-ua` adds it after you confirm. Removing an entry is never restricted.

- Without `--hostname`, `list` checks every custom domain of the environment (up to 50).

- Saved changes may take a few minutes to take effect.

## Updating the plugin

Check for and install the latest version:

```
terminus gcdn:update
```

This checks the latest release on GitHub, updates the plugin, and confirms the installed version. When Terminus can't update it in place (a new minor version, such as 0.5 to 0.6), it reinstalls the plugin. A plugin installed from a local checkout is left alone.

From 0.4.0 or earlier, reinstall once instead, because those versions' `gcdn:update` can't move to a new minor version:

```
terminus self:plugin:uninstall pantheon-systems/terminus-gcdn-plugin
terminus self:plugin:install pantheon-systems/terminus-gcdn-plugin
```

Check the installed version with `terminus self:plugin:list`.

## Development

Requires Terminus 3.2 or later. The tests run offline: every command test fails on any HTTP call it didn't script.

```
composer install
composer test
```

CI runs the suite on PHP 8.2 and 8.5 against Terminus 3.2.0, the latest 3.x, 4.0.0 and the latest 4.x. To try another Terminus locally:

```
composer require --dev --no-update "pantheon-systems/terminus:3.2.0" && composer update
```

Before tagging a release, set `VERSION` in `src/Commands/UpdatePluginCommand.php` to the tag; CI fails a tag that doesn't match.

## Help

Run `terminus help gcdn:upgrade`, `terminus help gcdn:dns`, `terminus help gcdn:verify`, `terminus help gcdn:challenge`, `terminus help gcdn:o2o`, `terminus help gcdn:bot-bypass`, `terminus help gcdn:ua-bypass:list`, `terminus help gcdn:ua-bypass:add`, `terminus help gcdn:ua-bypass:remove`, or `terminus help gcdn:update` for details on each command.
