=== DevDome Analytics: Visitor Tracker, Site Stats & Bot Detection ===
Contributors: devdome
Tags: visitor tracker, visitor tracking, cookieless analytics, ai referrals, bot detection
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visitor tracking statistics: site stats, visitor stats, pageviews, sources, bot detection and outbound click reports. Free DevDome account required.

== Description ==

DevDome Analytics is a WordPress visitor tracker for real-time website statistics, traffic sources, sessions and outbound clicks. It reports known bots and AI crawlers separately from human visitors, so detected automated traffic does not inflate your visitor stats. A free DevDome account is required to process analytics events and generate reports through the hosted service.

= Site stats and real-time visitors =

Use DevDome as a traffic monitor to see visits as they happen and review website traffic over time. Key metrics appear inside WordPress; detailed web analytics reports in your DevDome dashboard cover periods from the last 24 hours up to 12 months.

The reports include:

* Visitors, unique visitors, live visitors, pageviews and sessions.
* Top pages, traffic sources and referrers.
* Countries, devices, operating systems and browsers.
* Outbound link clicks, bot hits, AI crawlers and AI referral traffic.
* Pages per visit, session duration and bounce rate.

The live visitor count answers how many people are visiting now. These real time visitors are shown as aggregate traffic stats, without identifying WordPress user accounts.

= Page views and blog stats =

Review page views and post views in the Pages report to find popular posts and other frequently visited content. Per-page reports include visits, visitors, pageviews, referrers, outbound clicks, countries, browsers, operating systems and devices.

For private reporting, the visitor counter and page view counter provide different measures: how many visitors arrived and how many pages they viewed. This website counter is part of your analytics reports; the plugin does not provide a public counter widget.

= Bot detection and AI crawler tracking =

Search engines, SEO crawlers, monitoring services and AI bots request WordPress pages. DevDome Analytics detects known crawlers and separates them from human traffic in your visitor analytics.

Detected crawlers include Googlebot, Bingbot, GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot, Google-Extended, CCBot, AhrefsBot, SemrushBot and other known crawlers. Unknown, new or deliberately disguised bots may not always be identifiable.

Known AI bots are also distinguished from search-engine bots, helping you see when services such as ChatGPT, Claude, Perplexity and Google-Extended access your content. The plugin reports detected crawlers. It does not block them.

AI referral tracking identifies visits referred by supported assistants, including ChatGPT and Perplexity, separately from other traffic sources.

= Outbound click tracking and event tracking =

Track clicks on links that leave your WordPress site, including:

* Affiliate and product links.
* Partner websites.
* Social profiles and other external destinations.

The link click counter records outbound clicks in your reports. Clicks can be relayed through your own WordPress server so they can continue to be measured when ordinary third-party analytics requests are blocked.

Event tracking also covers the documented page activity and, while Track Clicks is on, searches made through your site's search form. Search terms are limited to 200 characters. External services below lists the fields sent with each event.

= Cookieless analytics and First-Party Delivery =

General website analytics are cookieless on new installations. Track Returning Visitors is optional and disabled by default.

Outbound click tracking can use random visitor and session identifiers when someone clicks an external link. With returning-visitor tracking off, those identifiers stay in memory for the current page. Outbound tracking can be disabled independently.

First-Party Delivery is available on supported DevDome plans. When enabled, the analytics script is served from your own domain and events are relayed through your WordPress server using randomized paths specific to your site.

This can reduce data loss caused by browser extensions and ad blockers that target known third-party analytics domains. No tracking method guarantees detection of every visit.

= Tracking settings and privacy controls =

You control collection through separate settings:

* Enable Tracking is the master switch.
* Track Returning Visitors controls recognition across days.
* Track Clicks and Track Outbound Links control click collection.
* Track AI Referrals controls AI referral identification.
* Track Bot Visits controls bot and crawler reporting.

Administrators and editors are excluded by default on new installations. You can exclude additional WordPress roles. The browser Do Not Track signal is respected by default.

DevDome Analytics does not collect post content, WordPress user accounts, customer data, order data or activity inside wp-admin. It does not collect visitor form field values except the site's own search terms when tracking and click tracking are enabled. Full transmission and storage details appear below.

= Hosted reports without WordPress analytics tables =

DevDome is a lightweight Google Analytics alternative for WordPress. Analytics events are processed by the hosted DevDome Analytics service, with no custom analytics tables or analytics event storage inside your WordPress database.

The WordPress Overview tab shows key metrics. Your DevDome account provides full traffic, referral, click, location, device and crawler reports.

No visitor data is tracked or sent until the site is connected. The DevDome Tools dashboard's plugin catalog request and the connection-status check on the plugin's own screen are described under External services.

= AI and agent support =

On WordPress 6.9 and newer, DevDome Analytics registers WordPress Abilities for:

* Connection status and traffic numbers for the last 1, 7 or 30 days, matching the dashboard.
* Reading and updating every tracking setting.
* Testing the connection and obtaining the one-click connect link.
* Disconnecting and resetting data.

Compatible AI agents and MCP clients can discover and use these abilities when the site exposes them, for example through the official WordPress MCP Adapter.

Every ability runs the same code as the plugin screen under the same administrator capability. Disconnect and data reset require explicit confirmation and are annotated destructive. Agent output never carries email addresses or the site token.

On an unconnected site, only abilities you deliberately invoke contact DevDome: the confirmed connect-link request and the connection test. The pre-connection requests are detailed under External services.

== External services ==

**Error reports (`devdome.com`), only when you press "Report this error" on an error message.** The plugin sends the error text, the plugin, WordPress and PHP versions, the screen you were on, its connection state (flags and timestamps, secrets masked), your site address and your admin e-mail (so support can reply) to `https://devdome.com/api/plugin/error-report`. Nothing is sent unless you press the button. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

**Plugin catalog (`devdome.com`).** The DevDome Dashboard inside wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links, WordPress.org slugs) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours, so the list stays current. Only the bundled core version is sent in the request; no site or visitor data. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

DevDome Analytics is a connector for the DevDome Analytics service. Its analytics and account requests use two hosts, both operated by DevDome. The separate catalog and optional error-report requests to devdome.com are described above.

Terms of service: https://devdome.com/terms-of-service
Privacy policy: https://devdome.com/privacy-policy

= analytics.devdome.com - the analytics service =

**The tracking script, https://analytics.devdome.com/track.js**

Loaded in your visitors' browsers on public pages, once the site is connected and Enable Tracking is on. It is not added to your pages before you connect. With First-Party Delivery on, a copy of this script that ships inside the plugin is placed in your uploads folder and served from your own domain instead; nothing is downloaded from DevDome for it.

**The event ingest, https://analytics.devdome.com/api/event**

This is where analytics events are recorded, and there are four ways it is reached.

1. From the visitor's browser, by the tracking script above. While Track Clicks is on, a site search also sends the search words typed into your site's search form (up to 200 characters). Each event carries: your Site ID (this site's domain), your DevDome Account ID, the page URL and path, the page title, the referring URL, browser, operating system, device type, user agent, browser language, screen size, time zone, country, the target URL of a click (for a link on your own site, without its query string), whether the browser reports itself as automated, the bundled bot detector's verdict, whether the referrer was an AI assistant (only while Track AI Referrals is on), and a visitor ID and session ID only when the browser is storing them (see the FAQ on what is stored). The browser contacts the service directly, so its IP address is visible to it, as with any web server.
2. From your server, when it forwards an outbound-link click. The visitor's browser sends the click to the `/dd-e` path on your own domain and your server relays it. Your server adds two fields to that relayed event: the visitor's country code and **the visitor's IP address**, so location and per-visitor counts stay correct when the event arrives from your server instead of from the browser.
3. From your server, when First-Party Delivery is on: the visitor's browser sends every tracking event (the same fields as item 1) to a randomized path on your own domain and your server relays it, authenticated with this site's secret token. The relay adds the same two fields as item 2, the visitor's country code and **the visitor's IP address**, and forwards nothing else: each event is rebuilt from an allowlist and the site and account identity always come from the plugin's own settings.
4. From your server, when a known crawler requests a page and Track Bot Visits is on. That event carries the crawler's user agent, the bot name and type, the requested URL and path, your Site ID and a timestamp. No human visitor data is in it.

**The plan check, https://analytics.devdome.com/api/plugin/entitlements**

Asks whether this site's DevDome plan includes First-Party Delivery. Sent only on a connected site: when you turn the switch on, once a day by the refresh job while it is on, and while the Analytics screen is open at most once every two minutes so a plan change shows quickly. It carries your Site ID and this site's secret token. No visitor data.

**The connection handshake, https://analytics.devdome.com/api/plugin/status**

Sent when you connect the site and when the connection is re-verified. Contains your Site ID, this site's secret token, your Account ID, the site URL, the site name, **the site administrator's email address**, the WordPress version, the PHP version, the plugin version, the active theme name, the timezone, the site language and whether this is a multisite install. No visitor data.

A shorter form (Site ID and secret token only) also runs when you open the plugin's screen, at most once per 15 minutes: a site already connected on devdome.com shows as connected here without a second connect step. No visitor data, nothing on public pages.

**The one-click connect handshake, https://analytics.devdome.com/api/plugin/connect/start and /api/plugin/connect/claim**

`connect/start` runs only when you press the "Connect Via DevDome Account" button, never on its own (opening the plugin's screen makes only the connection-status check described above). It sends this site's domain, its secret token and the wp-admin address to return to, and receives a short-lived connect link. `connect/claim` runs when your browser returns from devdome.com and exchanges that link for your Account ID.

**The stats read, https://analytics.devdome.com/api/plugin/stats**

Sends your Site ID, this site's secret token (so only your own site can read its numbers) and the selected day range. Used to fill the Overview tiles in wp-admin, and the bot-visit figure shared with DevDome Bot Protection when that plugin is installed.

**Deleting your data, https://analytics.devdome.com/api/plugin/purge**

Sends your Site ID and this site's secret token, and only when you press Reset Analytics, or tick "Also delete my data on DevDome" while disconnecting.

= api.devdome.com - DevDome account services =

These two are made by the shared DevDome library bundled with every plugin in the suite.

**The account check, https://api.devdome.com/plugin/account**

A POST carrying this site's domain and its secret token, answered with the Account ID and account email address that the token belongs to, so the DevDome screen can show which account this site is linked to. It runs when a DevDome admin screen is displayed and its cached answer has expired: a good answer is kept fifteen minutes (so a plan change shows quickly), a refusal one hour, an outage ten minutes. Never before you have acted: until you press a Connect button, save an Account ID or complete a connection, this check is not made at all.

When you connect from the DevDome Tools dashboard, whose Connect card states this before you press the button, those account checks also carry the slug and version of each active DevDome plugin on the site plus the bundled DevDome library, WordPress and PHP versions, so your DevDome account can show your sites and their DevDome plugins for support and update notices. Nothing about other plugins, users, email addresses, content or visitors is included. Sites connected before this was introduced, and sites connected from a button that does not show that text, do not send the list. Disconnecting stops the plugin list.

**Disconnecting, https://api.devdome.com/plugin/disconnect**

A POST carrying this site's domain and its secret token, sent only when you press Disconnect, to unlink the site from the account.

= Not contacted on this WordPress.org build =

The bundled shared library also references endpoints this build never calls: the `https://api.devdome.com/bot-protection/` signature feeds (used by other DevDome plugins; never fetched here, no cron scheduled) and `https://api.devdome.com/plugin-updates/` (self-hosted updates, disabled here; updates come from WordPress.org).

= devdome.com =

`https://devdome.com/connect/` is a link you click, not a request the plugin makes. Your browser goes there to sign in and approve the connection, and comes back. The only server-side requests to devdome.com are the two listed above: the plugin catalog (at most every twelve hours) and an error report you send by pressing the button.

= Never sent, in any request =

* Passwords and password hashes.
* Form field values submitted by visitors. The one exception is the text typed into the site's own search box, recorded as the site-search term of that visit (only while tracking and click tracking are on).
* Post, page, comment or any other WordPress content.
* User accounts, user lists, or the email addresses of your registered users. The exceptions are the site's administration email address, sent with the connection handshake and with every Test connection request described above, and the error report you send yourself with "Report this error", which carries it so support can reply.
* Customer, order or payment data.
* Anything at all about what happens inside wp-admin.

== Installation ==

1. Install and activate the plugin from **Plugins > Add New**.
2. Open **DevDome > Analytics**.
3. Select **Connect Via DevDome Account** and approve the link on devdome.com.
4. Review the Settings tab and enable only the tracking features you want.

== Frequently Asked Questions ==

= Do I need a DevDome account? =

Yes. DevDome Analytics connects WordPress to the hosted DevDome Analytics service, where analytics events are processed and full reports are displayed. A free plan is available. No visitor data is collected until you connect the site.

= Does it remove all bot traffic from visitor reports? =

It detects known bots and AI crawlers and reports them separately from human visitors. Unknown, new or deliberately disguised bots may not always be identifiable.

= Is anything sent before I connect? =

No visitor data is sent. Before the site is connected, the tracking script is not added and no analytics events are sent.

Opening the plugin's own screen can send a connection-status check containing only this site's domain and secret token. This lets a site already connected on devdome.com appear connected here without another connect step.

The DevDome Dashboard also requests the plugin catalog, sending only the bundled core version. An error report is sent only if you press "Report this error". External services lists these requests and the connection requests you deliberately initiate.

= Does visitor tracking set cookies? =

General traffic tracking is cookieless on new installations. Returning-visitor tracking is optional and disabled by default.

Outbound-link tracking keeps its random IDs in memory only while Track Returning Visitors is off. With it on, they are stored like the page tracker's IDs. See "What is stored on my site and on a visitor's device?" below for details.

= Can I exclude myself and my team? =

Yes. Logged-in administrators are excluded by default through Do Not Track Admins, and new installations also exclude the Editor role. You can exclude any additional WordPress role.

= Will it slow down my site? =

The tracking script loads asynchronously and does not block page rendering. The plugin does not write analytics events to your WordPress database.

= Does it work with caching plugins? =

Yes. The tracking snippet is the same for every visitor, so it works with full-page caching. Connecting or disconnecting also clears common page caches so the change is applied.

= Which crawlers can it report? =

The current detection list includes GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot, Google-Extended, Bytespider, CCBot, Googlebot, bingbot, Slurp, DuckDuckBot, Baiduspider, YandexBot, AhrefsBot, SemrushBot, MJ12bot, DotBot and Screaming Frog.

The plugin reports detected crawlers; it does not block them.

= What happens when I disconnect? =

Tracking stops immediately. You can also delete the site's hosted analytics data when disconnecting or by selecting Reset Analytics. Data that remains inactive is deleted automatically after 90 days.

= Does it support WordPress multisite? =

Yes. Settings and the connection are per site: each site is connected from its own Analytics screen.

On a subdomain or mapped-domain network, each site has its own Site ID and reports. On a subdirectory network, every site shares the main site's domain and therefore one Site ID, one secret token and one report. Connecting or disconnecting on a subdirectory network requires a network administrator.

= Where do I see site stats and who is online? =

The WordPress Overview tab shows key metrics, including live visitors. This shows how many visitors are active, without identifying their WordPress accounts.

Full traffic, referral, click, location, device and crawler reports are available in your DevDome account.

= What is stored on my site and on a visitor's device? =

**What is stored on your site.**

Roughly thirty option rows hold the tracking switches, service addresses, this site's ID and secret token, your Account ID and account email, and the connection timestamp. For First-Party Delivery, they also hold the switch and the randomized path and file names generated for this site. On a site DevDome set up itself, they also hold the relay credential DevDome issued to it.

When First-Party Delivery is on, two JavaScript files are placed under your uploads folder: the tracking script and bundled bot detector, both copied from the plugin's own package. Both are removed at uninstall using the names the plugin stored.

One more row can appear after a plugin update on a host where the plugin folder belonged to another system user. The shared DevDome core copies the folder so WordPress can update it, keeps the old folder hidden under a dot name in wp-content/plugins because the web server cannot delete it, and records its fingerprint in one option row so DevDome Malware Scanner recognises it.

That hidden folder loads nothing; your server administrator can remove it. The fingerprint option row is not removed at uninstall in this version.

There are no custom tables, post meta, user meta or stored analytics events. Short-lived transients hold:

* A connect handle for 10 minutes.
* The cached bot-visit figure for 1 hour.
* The cached First-Party Delivery plan answer for a day.
* Flood counters for `/dd-e` and First-Party relay endpoints for 2 minutes, keyed by an MD5 hash of the visitor's IP address.

**Public paths the plugin adds.**

There are up to four:

* `/dd-e`, only while connected, accepts the outbound-click beacon described in External services. It answers empty to everything else, requires the browser's own same-site Origin header, ignores requests from excluded roles, is rate limited per IP address and stores nothing.
* The First-Party Delivery relay, only while that switch is on, uses a randomized path unique to your site. It accepts the tracking events described in External services under the same rules and stores nothing. Its own per-IP limit runs when the site has a persistent object cache; without one, the DevDome service's per-site limit applies.
* `/.well-known/devdome-analytics.txt`, only while connected, returns one short line of fixed text so DevDome can confirm the plugin is installed on the connected domain.
* `/.well-known/devdome-connect-proof.txt` returns a one-way SHA-256 fingerprint of this site's secret token, never the token itself, so DevDome can confirm during connection that the request came from this site.

**What is stored on a visitor's device.**

Two independent settings decide this.

**Track Returning Visitors** is off on new installations. While off, the DevDome tracking script writes no cookie, localStorage or sessionStorage.

Unique visitors are still counted using an identifier DevDome derives on its server from the site, date, IP address and user agent, combined with a secret key. It changes daily, differs per site and cannot be reversed to identify a person. A visitor who returns tomorrow counts as new.

Turning the setting on stores a random visitor ID in a first-party cookie and localStorage, plus a session ID in sessionStorage. This recognises returning visitors across days and ties a click back to its visit.

With the setting on, events the browser could not deliver because of a network interruption are kept in localStorage, up to fifty, and sent on the next page load. With it off, nothing is queued.

These are random values with nothing personal in them, but they use storage on a visitor's device, so **you may need visitor consent for it**. The setting explains this where you switch it on.

**Track Outbound Links** is on by default. The built-in click detector counts clicks on links that leave your site. With Track Returning Visitors off, its two random IDs stay in memory for the current page and nothing is stored on the device. With Track Returning Visitors on, they are stored like the page tracker's IDs so the outbound click can be tied to the same visit.

**Sites upgrading from an earlier version** keep returning-visitor tracking on, exactly as before. Nothing changes on a live site until you decide otherwise.

**IP addresses.**

The plugin never stores a visitor's IP address on your site in readable form. The address reaches DevDome in two ways: the tracking script connects from the visitor's browser, as with any web request; and relayed outbound-click and First-Party Delivery events deliberately carry the visitor's real address so visits are not all attributed to your server. DevDome uses it for geolocation and per-visitor counts.

**How to turn things off.**

Enable Tracking is the master switch. Turning it off stops all collection. Track Clicks, Track Outbound Links, Track AI Referrals and Track Bot Visits can each be switched off independently.

Do Not Track Admins is on by default. Excluded roles lets you name any role that must never be tracked; new installations start with Administrator and Editor. Respect Do Not Track is on by default and honours the browser signal.

**How to remove your data.**

Disconnect stops everything immediately: the tracking script is no longer added to pages, `/dd-e` stops relaying, and the domain-verification file is no longer served.

To delete data already collected by DevDome, press Reset Analytics or tick "Also delete my data on DevDome" while disconnecting. Otherwise, DevDome deletes it automatically after 90 days of inactivity.

On your own site, there is no analytics data to clean up beyond the option rows listed above. The plugin creates no tables and stores no analytics data locally.

== Source code ==

All of this plugin's own PHP and JavaScript ships unminified and human-readable. The one bundled third-party file, `assets/botd.js` (FingerprintJS BotD 2.0.0, MIT), ships as published by its authors; its source is at https://github.com/fingerprintjs/BotD and it is used only in First-Party Delivery mode, where a copy is served from your own domain (otherwise the same file loads from analytics.devdome.com).

One file is generated: `assets/devdome-tools-tw.css`, the admin screen's stylesheet. It is a Tailwind CSS v3 utility bundle built from `src/tw.css` and `tailwind.config.cjs` with:

`npx tailwindcss -c tailwind.config.cjs -i src/tw.css -o assets/devdome-tools-tw.css --minify`

Those two build inputs are not included in the distributed package. Ask for them at https://devdome.com/contact and we will send them.

== Screenshots ==

1. DevDome Analytics inside wp-admin: visitors, live visitors, bots, clicks, pageviews, pages per visit, session duration and bounce rate.
2. Plugin settings: cookieless tracking, outbound clicks, AI referrals, bot visits, Do Not Track and admin exclusion.
3. DevDome Analytics dashboard: WordPress visitor statistics for 30 days with visits, unique visitors, pageviews, clicks and bot hits counted separately from humans, plus the pages table.
4. Countries: world map and per-country visits, visitors, bot hits, clicks and pageviews.
5. Pages report: per-page visits, visitors, pageviews, referrers, outbound click tracking, countries, browsers, OS and devices.
6. Referrers report: traffic sources for human visitors, search engines, social networks, AI assistants and other sites.
7. Devices report: desktop, mobile and tablet with pageviews, visitors, clicks, countries, browsers, OS, top pages and referrers.

== Changelog ==

= 1.1.2 =
* Bundled DevDome library 1.7.6: if you connect a DevDome account from the DevDome Tools dashboard, the Connect card now says exactly what is shared, including the list of active DevDome plugins and their versions. Sites that were already connected, and sites that never connect, send nothing new. See External services.
* Listing text rewritten: new title, short description, tags and a restructured description. No change to how the plugin works.

= 1.1.1 =

* Updates now work when the plugin folder belongs to another system user (shared DevDome core 1.7.4): a folder installed from a root shell or by an AI agent used to fail every update; the plugin now repairs the folder ownership itself when it can, and says exactly what to run when it cannot.

= 1.1.0 =

* Fixed: the tracker's inline configuration (Do Not Track, cookieless, click and outbound switches) never reached the tracking script on WordPress sites; the script tag filter dropped it. Every switch now applies.
* AI referrals: visits arriving from ChatGPT, Perplexity, Claude, Gemini, Copilot and similar assistants are labelled as AI referrals on the dashboard while "Track AI Referrals" is on.
* First-Party Delivery now serves the bot detector from your own domain too (bundled FingerprintJS BotD, MIT); Reset Analytics works (the service side answers it).
* Privacy: the redirect tag carries the path only (never a query string), Global Privacy Control is honoured everywhere Do Not Track is, the outbound-click detector stores nothing on the device in cookieless mode, secrets in error text are redacted before any cut.
* "Report this error" on every error banner sends the error text and context to DevDome support with one click.
* Database guard: every failed query is recorded, no write follows a failed read, every option write is read back; settings saves and the connect handshake report exactly what landed.
* Connect: the handshake fails closed, keeps its retry link until the connection is stored, and the shared library (1.7.1) never reports "not connected" after a failed database read.
* Abilities: start-connect and settings changes that collect more data need confirm: true; read abilities never call home; every ability answers a database error when a query failed.
* Multisite: uninstall cleans every site of a network; fresh subsites get the privacy defaults.

= 1.0.8 =

* WordPress Abilities API (WordPress 6.9 and newer): 8 abilities for AI agents and MCP clients through the official WordPress MCP Adapter: get-status, get-stats (1, 7 or 30 days), get-settings, test-connection, update-settings (read back before reported), start-connect (returns the approve link), disconnect and reset-data (both require confirm: true and are annotated destructive). Every write goes through the same handler as the screen. Agent output never carries e-mail addresses or the site token.
* Disconnect now checks the account server's answer and says when the site could not be unlinked remotely.
* Security review fixes: a disconnect stays a disconnect even when the account server could not be told (no silent re-link); the master tracking switch and the Do Not Track signal are enforced at every sending point, including beacons from pages cached earlier and bot reporting; the click switches are honoured by the first-party tracker copy; settings saves report what was actually stored; an agent must confirm before a change that collects more visitor data; the generated first-party script is never written or deleted through a symbolic link; the relay credential is removed on uninstall when Delete data on uninstall is enabled; the site-search term disclosure was added to the readme.
* The stats endpoint accepts 1 to 365 days only; agent error messages pass through the same redaction as results; the install notes no longer mention a manual Account ID step.

= 1.0.7 =

* Settings: every switch now shows a one line hint with an info icon holding the full explanation of what is sent and what turning it off changes, the same layout as DevDome Malware Scanner.
* DevDome Dashboard: installing another DevDome plugin from the dashboard no longer activates it, you activate it yourself from its card. Output escaping tightened.

= 1.0.6 =

* DevDome Dashboard: plugin list, descriptions, logos and versions now come from devdome.com, one-click install of DevDome plugins from WordPress.org, Docs link and Fix buttons, Activate stays on the dashboard.

= 1.0.5 =

* First-Party Delivery is honest about why it is unavailable: while the site is not connected the switch is disabled with a Connect button above it, and on the Free plan it shows "Included in the Pro plan and above" instead of switching itself back off. Plan changes now reach the screen within minutes.
* The manual Account ID connect option was removed. Connecting is always the one-click, signed-in flow.
* Disconnecting from the plugin now also unlinks the site on DevDome servers and clears every cached account detail, so every DevDome plugin on the site agrees immediately.
* The DevDome Dashboard (suite hub) was redesigned: cleaner cards, your account email and plan on the overview, and update buttons shown only when an update really exists.
* One button system across the suite: the same Connect button and the same Save Settings button in every DevDome plugin.
* New bug-report button in the page header.

= 1.0.4 =

* New: First-Party Delivery, an optional ad-block-resistant mode (off by default, included in the DevDome Pro plan and above). The tracking script is served from your own domain and events relay through your own site server-side with randomized per-site names, so ordinary blockers of third-party analytics domains cannot drop them. Bypasses most, not all, blockers. Every relayed field is validated and sanitized, identity always comes from the plugin's own settings, and the relay accepts only same-site browser requests.
* Connecting works again for fresh installs: the plugin serves a one-way fingerprint of its site token at `/.well-known/devdome-connect-proof.txt`, which the DevDome service verifies against your site before accepting the connection. No token, secret, or visitor data is exposed by it.
* One connection, both sides: a site connected through the devdome.com dashboard now shows as connected in wp-admin by itself, and if the site is removed or its token rotated on the dashboard, the plugin flips to disconnected with a clear notice instead of staying green while calls fail. A local Disconnect always sticks.
* Fixed: the Overview tiles never loaded on sites using plain permalinks (the WordPress default). They now show the same numbers as the DevDome dashboard everywhere, and an unavailable answer leaves the placeholders instead of showing zeros.
* First-Party Delivery hardening: forwarded visitor IP and country headers are trusted only when the connection they arrived on vouches for them; events relay correctly on subdirectory and subdirectory-multisite installs; relayed forwards no longer block the visitor's request; in-app (social webview) visits keep their traffic source; the served script copy is refreshed only when its content really changed; and the `/dd-e` click endpoint requires the browser's own same-site Origin header too.
* The tracking script used for First-Party Delivery ships inside the plugin package and is copied, never downloaded, into your uploads folder.
* Turning First-Party Delivery on requires a real answer from the plan check; an unreachable service or a rejected credential no longer enables it, and a rejected credential switches it off with a notice instead of silently losing events.
* Toggling First-Party Delivery clears common page caches so cached pages stop beaconing to a stale endpoint.
* The daily refresh event is removed on plugin deactivation, and uninstalling always cleans up the cron event and the generated script file.
* The plan check sends the site token in a request header instead of the URL.

= 1.0.3 =

* New optional First-Party Delivery mode (off by default, included in the DevDome Pro plan and above): the tracking script is served from your own domain and events are relayed through your site server-side, using randomized names unique to your site, so ordinary ad blockers that block third-party analytics domains cannot drop them. Bypasses most, not all, blockers. Every relayed field is validated and sanitized before forwarding, identity always comes from the plugin's own settings, and the forward authenticates with your site token. All other tracking is unchanged on every plan.

= 1.0.2 =

* Unified DevDome suite icons and updated the suite hub with one-click installs for WordPress.org plugins.

= 1.0.1 =

* No request is made to DevDome before you act: the one-click connect link is now requested only when you press "Connect Via DevDome Account", and the account check no longer runs until a connection has been started or completed. Off by default, opt-in by a button press.
* The connect screen now says exactly what connecting sends before you press the button.
* Every field of the relayed outbound-click event is individually validated and sanitized before it is forwarded, and the site and account identifiers in it now always come from the plugin's own settings, never from the request body.
* Request paths and IP addresses read from server variables are sanitized where they are read.

= 1.0.0 =

* First release on WordPress.org.
* The admin screen's styles and behaviour now load as enqueued files instead of inline blocks.
* The tracking snippet is enqueued too: the plugin no longer writes script tags into the page markup. Outbound-click counting is unchanged, including on sites where a blocker stops the tracker file from loading.
* Internal rename: every function, class, constant, option and transient the plugin owns now carries its own identifier prefix. Existing settings and your DevDome connection are carried across automatically.
* Fixed a fatal error that could break wp-admin on sites running several DevDome plugins when this one loaded first.
