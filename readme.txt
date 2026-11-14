=== LorePort ===
Contributors: loreport
Tags: newsletter, email, signup form, mailing list, authors
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Newsletter signup forms for LorePort authors: a signup block and shortcode, and opt-in checkboxes on comments and WooCommerce checkout.

== Description ==

[LorePort](https://loreport.ca) is a newsletter service for fiction authors. This plugin connects your WordPress site to your LorePort account so readers can join your lists from your site.

* **Signup form** as a block ("LorePort signup") or the `[loreport_signup]` shortcode. Choose the list, whether to ask for a name, a heading and the button text. It works without JavaScript and picks up your theme's look.
* **Consent done properly.** Signups are treated exactly like LorePort's own signup forms: if your list asks readers to confirm by email, they get the confirmation email, and LorePort records their consent (the wording, time, IP address and browser).
* **Comment form and WooCommerce checkout checkboxes**, never ticked in advance, so commenters and customers can choose to join a list. Works with both the classic and the block checkout.
* **No lost signups.** If LorePort can't be reached for a moment, signups wait on your site and are retried automatically.
* **Spam protection** without captchas: a hidden field, a time check, and a limit per visitor.

You need a LorePort account on a plan with API access, and an API key with the "Read pen names, lists and tags" and "Send signups" permissions.

== External services ==

This plugin connects to the LorePort API (https://loreport.ca/api/v1), the service that manages your mailing list. It's needed to sign readers up and to show your lists in the settings and block editor.

* **When:** when you save or test your settings or open the block editor (to load your lists), and whenever a reader submits a signup form or ticks a newsletter box when commenting or checking out.
* **What is sent:** your API key with every request; for signups, the reader's email address, their name if given, their IP address, their browser's user agent, the list, the consent wording and where on your site they signed up.
* LorePort's terms: https://loreport.ca/terms. LorePort's API documentation: https://loreport.ca/docs/api.

== Installation ==

1. Install and activate the plugin.
2. In LorePort, go to Settings → API and webhooks and make a key with the "Read pen names, lists and tags" and "Send signups" permissions.
3. In WordPress, go to Settings → LorePort, paste the key, choose a default list, and save. Use "Test the connection" to check it.
4. Add the "LorePort signup" block to a page, or use `[loreport_signup]`.

== Frequently Asked Questions ==

= My site is behind a proxy or CDN and every signup has the same IP address =

The plugin records `REMOTE_ADDR`. Behind a trusted proxy, set the real address with the `loreport_reader_ip` filter, for example from your CDN's client-IP header.

= Can I change the messages readers see? =

Yes, with the `loreport_signup_message` filter (it receives the message and a status: pending, subscribed, already, queued, refused, invalid, limited or error).

= Can I test against a development copy of LorePort? =

Define `LOREPORT_API_URL` in wp-config.php, or use the `loreport_api_url` filter.

== Changelog ==

= 0.1.0 =
* First version: settings, signup block and shortcode, comment and WooCommerce checkboxes, retry queue, privacy-policy text.
