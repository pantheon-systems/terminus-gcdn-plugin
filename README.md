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

### Post-upgrade steps

After enabling the GCDN upgrade, you will need to re-add and verify your custom domains:

1. Add your domains to the live environment:
   ```
   terminus domain:add <site>.live example.com
   terminus domain:add <site>.live www.example.com
   ```

2. Verify domain ownership:
   ```
   terminus domain:verify <site>.live example.com
   terminus domain:verify <site>.live www.example.com
   ```

3. Review the recommended DNS settings:
   ```
   terminus domain:dns <site>.live
   ```

4. Update your DNS records with the values from step 3.

## Help

Run `terminus help gcdn:upgrade` for help.

## TODO

Add this to packagist. 
