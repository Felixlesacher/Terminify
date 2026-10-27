=== konstanth – Einrichtungen, Termine & Rundmails ===
Contributors: konstanth
Tags: events, institutions, newsletter, brevo, blocks
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Events, jobs, institutions and news as blocks and shortcodes, with institution-scoped accounts and optional Brevo newsletters.

== Description ==

konstanth supports the collaboration of anthroposophic institutions on an existing WordPress website. It provides content components that you place on pages you design yourself. It does not replace your theme: header, navigation, footer and fonts stay with the theme. The administration interface and public texts are in German.

**Components as blocks and shortcodes**

* Gutenberg blocks in the block category "konstanth", each with a live preview in the editor and settings in the sidebar: Events (with filters, list and month calendar), Jobs, Institutions, News, Donations and Newsletter signup.
* Settings: institution (all or one), items per page (1-100), layout (list or grid) and an optional heading.
* Shortcodes keep working: [konstanth_events], [konstanth_jobs], [konstanth_institutions], [konstanth_news], [konstanth_donations] and [konstanth_newsletter], plus German aliases for events, jobs, institutions and donations.

**Design**

* The pastel design "Farbstimmungen" uses one colour per category, inherits the font of your theme and adapts to the width of its column (on narrow columns the calendar becomes a list of days with events).
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
