# Terminus GCDN Plugin

[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#actively-maintained)

A Terminus plugin for upgrading a site to GCDN with bot protection and managing the DNS migration for your existing domains.

## Installation

To install this plugin using Terminus 3 or later:

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

### 2. Get your DNS records and TXT verification challenges

```
terminus gcdn:dns <site>.live
```

This will show the TXT records needed for domain ownership and certificate validation.

### 3. Add TXT records to your DNS provider

Add the TXT records from step 2 to your DNS provider.

### 4. Verify your domains

Wait a few minutes for DNS propagation, then verify each domain. Verification typically takes a few minutes to complete:

```
terminus gcdn:verify <site>.live example.com
terminus gcdn:verify <site>.live www.example.com
```

### 5. Update your DNS records

Once verification passes, add the CNAME or A/AAAA records shown in the `gcdn:dns` output to point your domains to the new GCDN edge.

## Orange-to-Orange (O2O) migrations

If your domain is already proxied through your own Cloudflare zone (orange-clouded), use the O2O flow instead of the plain TXT verification above:

```
terminus gcdn:o2o <site>.live
```

This prints the per-domain record set to add in your Cloudflare DNS: the hostname ownership TXT record, the DCV delegation CNAME (which must stay in place permanently and be set to DNS only / grey cloud), and the final traffic CNAME. Pass a domain as a second argument to limit output to one hostname. See the O2O documentation for the surrounding steps (Zone Hold, SSL/TLS encryption mode).

## Help

Run `terminus help gcdn:upgrade`, `terminus help gcdn:dns`, `terminus help gcdn:verify`, or `terminus help gcdn:o2o` for details on each command.
