=== LorePort ===
Contributors: loreport
Tags: newsletter, email, signup form, mailing list, authors
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Newsletter signup forms for LorePort authors: a signup block and shortcode, and opt-in checkboxes on comments and WooCommerce checkout.

== Description ==

[LorePort](https://loreport.ca) is a newsletter service for fiction authors. This plugin connects your WordPress site to your LorePort account so readers can join your lists from your site.

* **Signup form** as a block ("LorePort signup") or the `[loreport_signup]` shortcode. Choose the list, a heading and description, whether to ask for a name, the button text, a card or plain look, and a stacked or inline layout. It uses your theme's fonts, colours and button style, suits light and dark themes, and works without JavaScript.
* **Consent done properly.** Signups are treated exactly like LorePort's own signup forms: if your list asks readers to confirm by email, they get the confirmation email, and LorePort records their consent (the wording, time, IP address and browser).
* **Comment form and WooCommerce checkout checkboxes**, never ticked in advance, so commenters and customers can choose to join a list. Works with both the classic and the block checkout.
* **No lost signups.** If LorePort can't be reached for a moment, signups wait on your site and are retried automatically.
* **Spam protection** without captchas: a hidden field, a time check, and a limit per visitor.
* **A popup** on every page or just some, opened by any button or menu link to `#loreport-popup`, or on its own after a delay (at most once a month per visitor). It closes with Escape or a click outside and takes its colours from your page.
* **LorePort inside WordPress:** your Subscribers, Lists and Newsletters (writing, sections, layouts, scheduling and results) are LorePort's own pages, shown in the LorePort menu, so they're always the same as in LorePort. Plus a dashboard widget with the newest subscribers.

You need a LorePort account on a plan with API access, and an API key with the "Read pen names, lists and tags" and "Send signups" permissions. To manage subscribers, lists and newsletters from WordPress, also give the key the "Show Subscribers, Lists and Newsletters inside your website" permission and enter your site's address on it.

== External services ==

This plugin connects to the LorePort API (https://loreport.ca/api/v1), the service that manages your mailing list. It's needed to sign readers up and to show your lists in the settings and block editor.

* **When:** when you save or test your settings or open the block editor (to load your lists), whenever a reader submits a signup form or ticks a newsletter box when commenting or checking out, and when an administrator opens the dashboard widget. The LorePort menu's pages are LorePort itself, shown in a frame: WordPress asks LorePort for a one-time sign-in link with your API key, and everything in the frame goes straight between your browser and LorePort.
* **What is sent:** your API key with every request; for signups, the reader's email address, their name if given, their IP address, their browser's user agent, the list, the consent wording and where on your site they signed up.
* LorePort's terms: https://loreport.ca/terms. LorePort's API documentation: https://loreport.ca/docs/api.

== Installation ==

1. Install and activate the plugin.
2. In LorePort, go to Settings → API and webhooks and make a key with the "Read pen names, lists and tags" and "Send signups" permissions.
3. In WordPress, go to LorePort → Settings, paste the key, choose a default list, and save. Use "Test the connection" to check it.
4. Add the "LorePort signup" block to a page, or use `[loreport_signup]`. For a popup, switch it on under LorePort → Settings → Popup, and link any button or menu item to `#loreport-popup`.

== Frequently Asked Questions ==

= My site is behind a proxy or CDN and every signup has the same IP address =

The plugin records `REMOTE_ADDR`. Behind a trusted proxy, set the real address with the `loreport_reader_ip` filter, for example from your CDN's client-IP header.

= Can I change how the form looks? =

The block has Look (card or plain), Layout (stacked or inline), colour and spacing settings. For more, set CSS custom properties on `.loreport-signup`, such as `--loreport-accent`, `--loreport-radius` and `--loreport-field-radius`, or style the `loreport-signup__*` classes; the plugin's rules are deliberately easy to override.

= Can I change the messages readers see? =

Yes, with the `loreport_signup_message` filter (it receives the message and a status: pending, subscribed, already, queued, refused, invalid, limited or error).

= Can I test against a development copy of LorePort? =

Define `LOREPORT_API_URL` in wp-config.php, or use the `loreport_api_url` filter.

== Changelog ==

= 0.4.1 =
* When LorePort's session in the frame ends (after a long idle spell), the page signs in again by itself and reopens the same page.
* The Website page is now called Settings.

= 0.4.0 =
* Subscribers, Lists and Newsletters are now LorePort's own pages, shown inside WordPress (LorePort menu): everything you do in LorePort's back end except pen names, plan, billing and sending setup, always up to date with LorePort. Needs the key's "Show Subscribers, Lists and Newsletters inside your website" permission and this site's address on the key.
* Settings moved to LorePort → Settings.

= 0.3.1 =
* Adding someone in the admin records that they agreed and how (with an optional confirmation email first), instead of treating them as a website signup.

= 0.3.0 =
* A signup popup: switch it on for every page in Settings → LorePort, or place it with [loreport_popup]. Any button or menu link to #loreport-popup opens it, and so does [loreport_popup_button]. It can also open on its own after a delay, at most once a month per visitor.
* A LorePort menu in the admin: an overview (lists with subscriber counts, recent newsletters with opens, clicks, bounces and spam complaints, plan usage) and a Subscribers page (search, filter by list and status, add someone, fix a name or email, tags, unsubscribe).
* A dashboard widget with totals and the newest subscribers.

= 0.2.3 =
* Name and email fields side by side always line up (their labels and boxes share rows), whatever the browser or extensions add.

= 0.2.2 =
* Signup forms have a consent checkbox (unticked, required) with the list's consent statement, or a plain one if the list has none. The form isn't sent without it.

= 0.2.1 =
* The signup form's designed styles (card and plain looks, inline layout, focus rings, spinner, success and error panels) now ship with the plugin; 0.2.0 had the new markup but the old stylesheet.

= 0.2.0 =
* Comment signups wait until the comment is approved; spam never signs anyone up.
* A designed signup form: card or plain look, stacked or inline layout, an optional description, the theme's button style, a success panel and a sending spinner, for light and dark themes.

= 0.1.0 =
* First version: settings, signup block and shortcode, comment and WooCommerce checkboxes, retry queue, privacy-policy text.
