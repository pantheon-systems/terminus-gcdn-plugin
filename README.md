# Terminus GCDN Plugin

[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#early-access)

A Terminus plugin that enables upgrading a site to GCDN with bot protection.

## Installation

To install this plugin using Terminus 3 or later:

```
terminus self:plugin:install pantheon-systems/terminus-gcdn-plugin
```

## Usage

### Upgrade a site to GCDN

```
terminus gcdn:upgrade <site>
```

This activates the GCDN upgrade for the specified site, enabling the migration from Fastly to new-gcdn.

### Example

```
terminus gcdn:upgrade my-site
```

### Post-upgrade steps for existing domains

If you already have custom domains on your site, follow these steps after running `gcdn:upgrade`:

1. Get your DNS records and TXT verification challenges:
   ```
   terminus gcdn:dns <site>.live
   ```
   This will show the TXT records needed for domain ownership and certificate validation.

2. Add the TXT records from step 1 to your DNS provider.

3. Wait a few minutes for DNS propagation, then verify each domain. Verification typically takes a few minutes to complete:
   ```
   terminus gcdn:verify <site>.live example.com
   terminus gcdn:verify <site>.live www.example.com
   ```

4. Once verification passes, add the CNAME or A/AAAA records shown in the `gcdn:dns` output to point your domains to the new GCDN edge.

## Help

Run `terminus help gcdn:upgrade` for help. You can also run `terminus help gcdn:dns` and `terminus help gcdn:verify` for details on those commands.
