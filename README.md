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
- **CNAME `_acme-challenge.<domain>`:** lets Cloudflare issue and renew the certificate (DCV delegation). Set it to DNS only (not proxied) and keep it permanently.
- **Traffic records:** the CNAME, or the A/AAAA records, that point the domain to Pantheon.

### 3. Add the TXT and `_acme-challenge` CNAME records

Add them at your DNS provider. Remove any existing `_acme-challenge.<domain>` TXT records first; they conflict with the CNAME.

### 4. Verify each domain

```
terminus gcdn:verify <site>.live example.com
terminus gcdn:verify <site>.live www.example.com
```

`gcdn:verify` asks Cloudflare to check the domain again now (like **Force Recheck** in the dashboard), then shows its status. Pantheon sees the result within about 10 minutes, so run the command again to see it. A domain is rechecked at most once every 5 minutes from your machine, and not at all once it's active. To only show the status, use `--no-recheck`.

The status is one of:

- **Ownership and certificate active:** done. Point DNS to Pantheon if you haven't yet.
- **DNS records ready (confirmed by Pantheon):** the domain is served through Cloudflare today (your own Cloudflare account or your current host), and Pantheon has confirmed you own it. The command lists the ways to switch DNS. After you switch, run `gcdn:verify` again.
- **Ownership verified, certificate pending**, or **ownership and certificate pending:** add the records shown, then run `gcdn:verify` again.

To switch certificate validation to HTTP as part of the recheck (not possible while the domain is served through Cloudflare):

```
terminus gcdn:verify <site>.live example.com --method=http
```

### 5. Update your DNS records

Once the domain is ready or active, point it to Pantheon with the CNAME or A/AAAA records shown by `gcdn:dns`.

### Exit codes

`gcdn:verify`, `gcdn:dns` and `gcdn:o2o` exit `0` when every domain they report on is active or ready, and `1` when one isn't ready yet, isn't found, or its status is unavailable. `gcdn:challenge` exits `1` when a toggle fails. Scripts can rely on this since 0.5.0.

## Orange-to-Orange (O2O) migrations

If your domain is already proxied through Cloudflare (your own Cloudflare zone, orange-clouded), use the O2O flow:

```
terminus gcdn:o2o <site>.live
```

This prints the per-domain record set to add in the Cloudflare zone that serves the domain today: the hostname ownership TXT record, the DCV delegation CNAME (DNS only / grey cloud, permanent), and the traffic CNAME. The traffic CNAME is ready to switch once Pantheon has confirmed ownership. Pass a domain as a second argument to limit output to one hostname.

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

## Updating the plugin

Check for and install the latest version:

```
terminus gcdn:update
```

This checks the latest release on GitHub and runs the update automatically.

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

Run `terminus help gcdn:upgrade`, `terminus help gcdn:dns`, `terminus help gcdn:verify`, `terminus help gcdn:challenge`, `terminus help gcdn:o2o`, `terminus help gcdn:bot-bypass`, or `terminus help gcdn:update` for details on each command.
