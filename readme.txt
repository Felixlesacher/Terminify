=== konstanth – Einrichtungen, Veranstaltungen & Rundmails ===
Contributors: konstanth
Tags: events, institutions, newsletter, brevo, blocks
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Events, jobs, institutions and news as blocks and shortcodes, with institution-scoped accounts and optional Brevo newsletters.

== Description ==

konstanth supports the collaboration of anthroposophic institutions on an existing WordPress website. It provides content components that you place on pages you design yourself. It does not replace your theme: header, navigation, footer and fonts stay with the theme. The administration interface and public texts are in German.

**Components as blocks and shortcodes**

* Gutenberg blocks in the block category "konstanth", each with a live preview in the editor and settings in the sidebar: Events (with filters, list and month calendar), Upcoming events (the next events side by side for the home page, with a button to all events), Jobs, Institutions, News, Donations and Newsletter signup.
* Settings: institution (all or one), items per page (1-100), layout (list or grid) and an optional heading.
* Shortcodes keep working: [konstanth_events], [konstanth_aktuelle_veranstaltungen], [konstanth_jobs], [konstanth_institutions], [konstanth_news], [konstanth_donations] and [konstanth_newsletter], plus German aliases for events, jobs, institutions and donations. The earlier name [konstanth_termine] still works.

**Design**

* The design "Farbstimmungen" in the "Goldlicht" colours (gold, cream and dark brown for light beige pages) uses one pastel colour per category, inherits the font of your theme and adapts to the width of its column (on narrow columns the calendar becomes a list of days with events).
* Event pages show a wide banner with a card for date and title, picture and flyer on the left, the description on the right, an "In den Kalender" button (ICS file) and a link back to all events. Buttons and backgrounds use the "Goldlicht" colours.
* Colour contrast follows WCAG 2.2 AA. The animation of the selection chips is switched off when the system setting "reduce motion" is active.

**Institution-scoped accounts**

* A dedicated user role "Institution". An administrator assigns each account to one fixed institution. Several accounts can share one institution.
* Institution accounts can only edit the content and profile of their own institution and cannot see the sending settings. Administrators can edit everything.
* On public pages the name of the institution is shown instead of the account name.

**Categories with colours**

* Administrators manage topic categories (for events) and institution types, each with its own colour. Text colours are calculated automatically for a minimum contrast of 4.5:1.
* An event needs one to three topics.

**Events**

* Start and end in the site time zone, location, optional registration link, "cancelled" status, public visitor information and internal notes that are never public (not on the website, not in the calendar export, not in emails).
* One-click duplicate of an event as a draft, calendar download (ICS), schema.org Event data, short permalinks such as /termin/, and noindex for filter pages and past events.

**Newsletter (optional, via Brevo)**

* Readers subscribe by topic (events from all institutions) and/or to everything from chosen institutions, with double opt-in confirmed by a button.
* Institutions send a newsletter by ticking a box on an event, job or news item. The public content and featured image are reused, no second text is needed.
* Protection: administrators can require approval of institution newsletters, each institution may send at most one newsletter per calendar day, and an institution can be blocked.
* After activation the plugin is in test mode: no emails are sent and there is no connection to Brevo.

**Updates (via GitHub)**

* New versions are published as releases in the plugin's public GitHub repository and appear under Plugins like any other update. Content, accounts and settings are kept.
* For this, the website asks GitHub for the latest release about every 12 hours, and more often while an administrator has the Plugins or Updates screen open (see External services).
* Set `define( 'KN_DISABLE_UPDATES', true );` in wp-config.php to switch this off.

== Installation ==

1. Upload the plugin ZIP via Plugins > Add New > Upload Plugin and activate it. From version 1.2.0 on, updates appear under Plugins; before that, upload the new ZIP and choose "Replace current with uploaded".
2. Under Settings > General, choose a city time zone (for example Berlin or Zurich). Under Settings > Permalinks, "Post name" is recommended. Set the site language to German (Settings > General) so that month and weekday names appear in German.
3. Edit a page and add a block from the category "konstanth" (or use a shortcode).
4. Open the menu "konstanth" for the overview, categories and colours, and the sending settings (administrators only).
5. Optional: enter your Brevo details under konstanth > Versand-Einstellungen (sending settings), then switch on newsletters and switch off test mode.

== External services ==

This plugin can connect to the email service Brevo (https://www.brevo.com/). The connection is made only when an administrator has enabled newsletter delivery and switched off test mode. In test mode the plugin makes no calls to Brevo.

What it is used for: sending confirmation and subscription-management emails, keeping the subscriber lists up to date, and creating and sending email campaigns.

Endpoint: https://api.brevo.com/v3/

Data sent to Brevo: email addresses, subscription scopes (institution and content type, or topic), sender details, and the public campaign content including the URL of the featured image. Internal notes are not sent.

* Brevo website: https://www.brevo.com/
* Privacy policy: https://www.brevo.com/legal/privacypolicy/
* Terms of use: https://www.brevo.com/legal/termsofuse/

The plugin also asks GitHub (https://github.com/) for updates, but only when an update repository is configured and updates are not switched off with `KN_DISABLE_UPDATES`.

What it is used for: finding the latest release of the plugin and downloading its ZIP when an administrator updates.

Endpoints: https://api.github.com/ and https://github.com/ (release downloads, which GitHub redirects to a githubusercontent.com host)

Data sent to GitHub: the request itself, with the server's IP address, the plugin repository name and the WordPress user agent (WordPress version and website address). No content, accounts or visitor data.

* GitHub terms of service: https://docs.github.com/site-policy/github-terms/github-terms-of-service
* GitHub privacy statement: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

== Data retention ==

* Deactivating the plugin keeps all data and stops sending.
* Uninstalling (deleting) the plugin keeps all data, unless the administrator has enabled "Remove all konstanth data on uninstall" in the sending settings (German label: "Beim Löschen des Plugins alle konstanth-Daten entfernen"). In that case events, jobs, news, institutions, categories, subscriptions, settings and the role are removed. Images in the media library, user accounts and data stored at Brevo are not removed.
* Subscriptions are included in the WordPress privacy tools "Export Personal Data" and "Erase Personal Data". Data held by Brevo must be checked in the Brevo account separately.

== Frequently Asked Questions ==

= Does the plugin change my theme, header or navigation? =

No. It provides blocks and shortcodes only. You build the pages, the theme provides header, navigation, footer and fonts.

= Are emails sent right after activation? =

No. After activation the plugin is in test mode and sends no emails. Live sending needs a Brevo account and the settings described in the installation guide. Open test-mode jobs, including those awaiting approval, are closed as test runs when you go live and are not sent. They can only be scheduled again explicitly under "Rundmails" (newsletters).

= Can I create recurring events? =

Not in this version. Use "Duplicate event" to copy an event as a draft and adjust the date.

== Changelog ==

= 1.3.2 =
* Changed: event pages show the flyer next to the description, each taking half the width on larger screens: a PDF in the browser's own PDF viewer, a picture as picture. "Flyer herunterladen" stays below it. Browsers without a PDF viewer show only the button.
* Changed: the card with date and title, the event picture and the flyer on event pages have round corners (10 px).
* Fixed: a single upcoming event in the "Aktuelle Veranstaltungen" block no longer fills the whole row; the cards keep the width set by the number of events to show (one in three by default).

= 1.3.1 =
* Changed: every konstanth button has round corners (10 px), now also on event pages ("Zur Anmeldung", "In den Kalender", "Flyer herunterladen", "Alle Veranstaltungen") and the button of the "Aktuelle Veranstaltungen" block. The preview in the editors matches.
* Fixed: in German, the date in the "Aktuelle Veranstaltungen" block showed a double full stop after the weekday ("Sa.., 7. November").

= 1.3.0 =
* New: "Aktuelle Veranstaltungen" block and shortcode [konstanth_aktuelle_veranstaltungen] for the home page: the next events side by side, with a theme-styled button to all events. Past and cancelled events are left out.
* New: event pages with a wide banner, a card for date and title, picture and flyer on the left, the description on the right, "In den Kalender" and "Alle Veranstaltungen". Administrators set a default banner under konstanth → Darstellung; each event can have its own.
* New: a flyer for every event (PDF or image), offered as "Flyer herunterladen".
* New: all editors (events, jobs, news, institutions) open in a focus view with a toolbar, a live preview and picture format hints. The classic WordPress view stays one click away.
* New: konstanth → Anleitung, a step-by-step guide for institution accounts; administrators also see the platform chapters.
* Changed: the overview shows shortcodes only to administrators; institution accounts get quick actions instead.
* Changed: "Veranstaltung" instead of "Termin" throughout the backend, the website, the blocks and the newsletters. [konstanth_veranstaltungen] and [konstanth_aktuelle_veranstaltungen] join the existing shortcode names, which keep working. Addresses of event pages stay /termin/….
* Changed: the newsletter choice is a three-way switch: Keine, Sofort, Nach 24 h.
* New: addresses of events and institutions in separate fields: street and number, postcode, town, country (Germany, Switzerland, Austria) and the matching state or canton. Events keep an optional name of the place. Institution entries of the form "CH-8280 Kreuzlingen" are split into postcode, town and country once during the update.
* New: admission for events: no information, free, or a price as text (for example "15 €, ermäßigt 10 €"), shown on the event page, in the calendar file and in newsletters.
* New: "Brevo & Versand" (formerly "Versand-Einstellungen") explains the Brevo setup step by step, lists what is still missing and offers "Verbindung prüfen", which only reads account, sender and folder at Brevo and sends nothing.
* Changed: the website components and the newsletter use the fixed "Goldlicht" colours (gold, cream, dark brown) instead of violet; category colours stay. Own colours can still be set with the CSS variables on .kn-portal.
* Changed: duplicating an event also copies address, admission, flyer and banner.
* Changed: the focus view of the editors also works on phones; the toolbar grows with its buttons instead of covering the form.
* Fixed: institution accounts could not open "Neu hinzufügen" for events, jobs and news, because WordPress checked the page against "Posts → Add New".

= 1.2.1 =
* Fixed: deleting an event, job, news item, institution or account now removes all of its data, also when WP-Cron empties the trash, when WP-CLI deletes it or when an institution account deletes its own entry. Before, some internal fields could remain in the database. Data of other entries stays protected as before.

= 1.2.0 =
* New: one-click updates. New versions are published as releases in the public GitHub repository and appear under Plugins like any other update. Content, accounts and settings are kept.
* Bundles Plugin Update Checker 5.7 by Jānis Elsts (MIT license).

= 1.1.0 =
* New: Gutenberg blocks for events, jobs, institutions, news, donations and newsletter signup, with live preview and sidebar settings. Shortcodes still work.
* New: categories and colours (topics and institution types) managed by administrators.
* New: topic subscriptions in addition to institution subscriptions.
* New: approval switch for newsletters from institutions, a limit of one newsletter per institution and calendar day, and a block switch per institution.
* New: sender name in the form "Institution name über konstanth".
* New: newsletter steps (confirmation, management, messages) are shown inside the theme on the page with the signup block.
* New: subscriptions are confirmed with a button instead of automatically.
* New: "cancelled" status and registration link for events.
* New: duplicate events.
* New: short permalinks such as /termin/, /stelle/, /institution/ and /nachricht/.
* New: schema.org Event data, and noindex for filter pages and past events.
* New: design "Farbstimmungen".
* Fixed and hardened: draft preview, test jobs are never sent when going live, rate limits for signups, protection against sending from copied staging sites, WebP images are provided as JPEG in emails, upgrade routine for tables, schedule and permalinks.

= 1.0.0 =
* Initial release.
