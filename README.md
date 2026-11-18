# LorePort for WordPress

The WordPress plugin for [LorePort](https://loreport.ca), the newsletter service for fiction authors: a signup form block and shortcode, and opt-in checkboxes on the comment form and WooCommerce checkout. It uses LorePort's public API ([docs](https://loreport.ca/docs/api)).

`readme.txt` is the WordPress.org description. Decision record: D-029 in the LorePort repository.

## Layout

- `loreport.php`: plugin header and bootstrap.
- `includes/`: one class per job. `LorePort_Client` (API calls), `LorePort_Settings` (LorePort → Settings), `LorePort_Admin` (the LorePort menu, built from LorePort's `GET /embed-menu` and cached for a day, with a built-in fallback: LorePort's pages embedded in a frame; dashboard widget), `LorePort_Popup`, `LorePort_Signups` (sending signups, form handling, spam checks, retry queue), `LorePort_Form` (shortcode and block rendering), `LorePort_Comments`, `LorePort_WooCommerce`, `LorePort_Privacy`, `LorePort_MailPoet` (read-only access to MailPoet's tables) and `LorePort_Import` (the "Import from MailPoet" tab and its step runner, LorePort D-040).
- `blocks/signup/`: the block. Plain JavaScript using the editor's own scripts, so there's no build step; `index.asset.php` lists its dependencies by hand.
- `assets/`: the form's CSS, the small script that sends forms in the background, and `import.js`, which runs the MailPoet import step by step.
- `uninstall.php`: removes the plugin's options when it's deleted.

## Rules

- Everything is prefixed `loreport_` / `LorePort_`. Escape all output, sanitize all input.
- The API key never leaves the server.
- Readers' details are sent only to `POST /api/v1/signups` (the retry queue keeps them at most a week), and, when an administrator runs "Import from MailPoet", to LorePort's `/api/v1/imports` endpoints. The import only reads MailPoet's tables, never changes them.
- Checkboxes are never ticked by default (CASL, GDPR).
- Supports WordPress 6.4+ and PHP 8.0+.

## Testing

On a throwaway WordPress site with WooCommerce (never a client site). Point it at a LorePort copy with `define( 'LOREPORT_API_URL', 'https://...' );` in `wp-config.php` if needed. Check with the Plugin Check plugin before a release.

The MailPoet reader was also checked, with Stuart's go-ahead, against gracepringle.com's real MailPoet data (2026-11-18): the classes were loaded for one `wp eval-file` run only, read-only, with progress in memory, sending to LorePort dev. Nothing was installed or written on that site.

## Licence

GPL-2.0-or-later. See `LICENSE`.
