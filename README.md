# LorePort for WordPress

The WordPress plugin for [LorePort](https://loreport.ca), the newsletter service for fiction authors: a signup form block and shortcode, and opt-in checkboxes on the comment form and WooCommerce checkout. It uses LorePort's public API ([docs](https://loreport.ca/docs/api)).

`readme.txt` is the WordPress.org description. Decision record: D-029 in the LorePort repository.

## Layout

- `loreport.php`: plugin header and bootstrap.
- `includes/`: one class per job. `LorePort_Client` (API calls), `LorePort_Settings` (LorePort → Website), `LorePort_Admin` (the LorePort menu: embedded Subscribers, Lists and Newsletters; dashboard widget), `LorePort_Popup`, `LorePort_Signups` (sending signups, form handling, spam checks, retry queue), `LorePort_Form` (shortcode and block rendering), `LorePort_Comments`, `LorePort_WooCommerce`, `LorePort_Privacy`.
- `blocks/signup/`: the block. Plain JavaScript using the editor's own scripts, so there's no build step; `index.asset.php` lists its dependencies by hand.
- `assets/`: the form's CSS and the small script that sends forms in the background.
- `uninstall.php`: removes the plugin's options when it's deleted.

## Rules

- Everything is prefixed `loreport_` / `LorePort_`. Escape all output, sanitize all input.
- The API key never leaves the server.
- Readers' details are sent only to `POST /api/v1/signups`; the retry queue keeps them at most a week.
- Checkboxes are never ticked by default (CASL, GDPR).
- Supports WordPress 6.4+ and PHP 8.0+.

## Testing

On a throwaway WordPress site with WooCommerce (never a client site). Point it at a LorePort copy with `define( 'LOREPORT_API_URL', 'https://...' );` in `wp-config.php` if needed. Check with the Plugin Check plugin before a release.

## Licence

GPL-2.0-or-later. See `LICENSE`.
