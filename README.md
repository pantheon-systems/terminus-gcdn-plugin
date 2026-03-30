# Terminus GCDN Plugin

[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#early-access)

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

## Help

Run `terminus help gcdn:upgrade`, `terminus help gcdn:dns`, or `terminus help gcdn:verify` for details on each command.
