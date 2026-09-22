# DevDome Analytics: Visitor Tracker, Site Stats & Bot Detection

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/devdome-analytics?label=wp.org)](https://wordpress.org/plugins/devdome-analytics/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/devdome-analytics)](https://wordpress.org/plugins/devdome-analytics/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/devdome-analytics)](https://wordpress.org/plugins/devdome-analytics/reviews/)
[![Tested WP](https://img.shields.io/wordpress/plugin/tested/devdome-analytics)](https://wordpress.org/plugins/devdome-analytics/)
[![License GPL-2.0+](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)](LICENSE)

Visitor tracking statistics: site stats, visitor stats, pageviews, sources, bot detection and outbound click reports. Free DevDome account required. This WordPress visitor tracker separates known bots and AI crawlers from human visitors. See key numbers inside wp-admin and detailed reports in your hosted DevDome dashboard.

**The free alternative to Plausible, Fathom, Matomo, MonsterInsights, Google Analytics and Jetpack Stats for WordPress.**

[![DevDome Analytics, free privacy-friendly WordPress analytics plugin](https://ps.w.org/devdome-analytics/assets/banner-1544x500.png)](https://devdome.com)

- **Install from WordPress.org:** https://wordpress.org/plugins/devdome-analytics/
- **Website and dashboard:** https://devdome.com
- **Support:** https://wordpress.org/support/plugin/devdome-analytics/

## Why DevDome Analytics instead of the alternatives

| | DevDome Analytics | Plausible | Fathom | Matomo (self-hosted) | MonsterInsights | Google Analytics 4 |
|---|---|---|---|---|---|---|
| Price | **Free** | from $9 / month | from $15 / month | Free, your own server | Pro from $199.50 / year | Free |
| Cookieless, no consent banner | Yes | Yes | Yes | Configurable | No (GA cookies) | No |
| Bots and AI crawlers counted separately | **Yes** | No | No | Bots filtered, not shown | No | No |
| AI referral traffic (ChatGPT, Perplexity) | Yes | Partial | No | No | No | Manual setup |
| Outbound click tracking | Yes | Yes | Yes | Yes | Yes | Yes |
| Stats inside wp-admin | Yes | Widget | No | No | Yes | Yes |
| Data in your WordPress database | None | None | Your server | Your server | None | None |
| Public Stats API + MCP server | **Yes** | API (paid) | API | API | No | API |
| Site limit | None | Per plan | Per plan | None | Per licence | None |

Prices are the vendors' entry plans as published in September 2026.

For DevDome, cookieless refers to new-install defaults; optional returning-visitor tracking uses device storage and may require consent. “None” refers to analytics event storage: settings and temporary caches still use WordPress storage.

## Ask your analytics a question: Stats API and MCP server

The public Stats API and MCP server let Claude, ChatGPT, Cursor or any MCP client answer questions from your data:

- How many real people visited yesterday?
- Which AI crawlers visited this week?
- What were the top referrers over the last 30 days?

Connection details:

- MCP endpoint: `https://analytics.devdome.com/mcp`
- Registry entry: `com.devdome/analytics` in the official MCP registry
- MCP server repository: https://github.com/DevDomeFamily/devdome-mcp

On WordPress 6.9+, WordPress Abilities also expose connection status, traffic numbers for 1, 7 or 30 days, tracking settings, connection tests, connect links, disconnect and data reset. Compatible agents can use them through an adapter such as the official WordPress MCP Adapter.

Abilities use the same administrator capability as the plugin screen. Disconnect and reset require explicit confirmation; agent output excludes email addresses and the site token.

## Features

### Site stats and real time visitors

Use DevDome as a traffic monitor for live visitors and website traffic over time. Its web analytics dashboard provides website statistics from the last 24 hours up to 12 months.

Traffic stats include:

- Visitors, unique visitors, pageviews and sessions.
- Top pages, traffic sources and referrers.
- Countries, devices, operating systems and browsers.
- Pages per visit, session duration and bounce rate.
- Outbound clicks, bot hits and AI referral visits.

The live count answers “who is online” with aggregate visitor numbers, without identifying WordPress accounts. These traffic analytics help you compare pages and sources over time.

### Page views, post views and blog stats

Find popular posts in the Pages report. Each page has visitor analytics covering visits, visitors, page views, referrers, outbound clicks, countries, browsers, operating systems and devices.

The private visitor counter measures visitors; the page view counter measures pages viewed. For posts, that report serves as a post view counter. This website counter belongs to your reporting dashboard; there is no public counter widget.

### Bot detection and ai referrals

Known bots and AI crawlers are reported separately so detected automated traffic does not inflate human visitor stats. Detection covers GPTBot, ClaudeBot, PerplexityBot and many other known crawlers.

Unknown, new or disguised bots may escape detection. The plugin reports crawlers; it does not block them.

AI referral tracking identifies human visits from supported assistants, including ChatGPT and Perplexity, separately from crawler requests.

### Outbound click tracking and event tracking

Use outbound link tracking for affiliate links, product links, partner websites and social profiles. The link click counter records clicks in your reports.

Outbound clicks can pass through your WordPress server, helping collection when ordinary third-party analytics requests are blocked. Event tracking also covers documented page activity and site searches while Track Clicks is enabled; search terms are limited to 200 characters.

### Cookieless analytics and privacy controls

General tracking is cookieless on new installations. Track Returning Visitors is off by default; outbound-click identifiers stay in memory for the current page.

Enabling returning-visitor tracking uses a cookie, localStorage and sessionStorage. Existing installations retain their earlier setting.

- Control tracking, clicks, outbound links, AI referrals and bot visits separately.
- Exclude administrators and editors by default on new installations, with additional role exclusions available.
- Respect the browser's Do Not Track signal by default.
- Collect no post content, WordPress accounts, customer data, order data or wp-admin activity.

Visitor IP addresses reach the hosted service for location and visitor counts. See the [privacy policy](https://devdome.com/privacy-policy) and [transmission details](readme.txt) for collection and storage information.

### Lightweight hosted analytics

One small asynchronous tracker supplies reports without custom analytics tables or stored analytics events in your WordPress database. No visitor data is sent before connection.

First-Party Delivery, available on supported plans, serves the script from your domain and relays events through your server. This can reduce losses from ad blockers, but cannot guarantee every visit is counted.

## Screenshots

[![DevDome Analytics inside wp-admin: visitors, live visitors, bots, clicks, pageviews, pages per visit, session duration and bounce rate](screenshots/devdome-analytics-wp-admin-overview.png)](https://wordpress.org/plugins/devdome-analytics/)
*Inside WordPress: the plugin's overview tab.*

[![DevDome Analytics plugin settings: cookieless tracking, outbound clicks, AI referrals, bot visits, Do Not Track and admin exclusion](screenshots/devdome-analytics-wp-admin-settings.png)](https://wordpress.org/plugins/devdome-analytics/)
*Plugin settings.*

[![DevDome Analytics dashboard: WordPress visitor statistics for 30 days with visits, unique visitors, pageviews, clicks and bot hits counted separately from humans, plus the pages table](screenshots/devdome-analytics-wordpress-dashboard-visitors-bots.png)](https://devdome.com)
*Dashboard: visits, unique visitors, pageviews, clicks and bot hits, humans and bots side by side, with the pages table.*

[![Countries report: world map and per-country visits, visitors, bot hits, clicks and pageviews for a WordPress site](screenshots/devdome-analytics-countries-map.png)](https://devdome.com)
*Countries: map and per-country numbers.*

[![Pages report with per-page visits, visitors, pageviews, referrers, outbound click tracking, countries, browsers, OS and devices](screenshots/devdome-analytics-pages-report-outbound-clicks.png)](https://devdome.com)
*Pages: everything per page, including outbound clicks.*

[![Referrers report: traffic sources for human visitors, search engines, social networks, AI assistants and other sites](screenshots/devdome-analytics-referrers-traffic-sources.png)](https://wordpress.org/plugins/devdome-analytics/)
*Referrers: where human traffic comes from.*

[![Devices report: desktop, mobile and tablet with pageviews, visitors, clicks, countries, browsers, OS, top pages and referrers](screenshots/devdome-analytics-devices-report.png)](https://devdome.com)
*Devices: desktop, mobile and tablet broken down.*

## Requirements

WordPress 6.0+, PHP 7.4+, a free DevDome account (the plugin is the connector for the DevDome Analytics service).

## Installation

1. In wp-admin go to **Plugins > Add New**, search for **DevDome Analytics**, install and activate.
2. Open **DevDome > Analytics** and connect your free DevDome account.
3. Review tracking settings. Statistics start within minutes, inside wp-admin and on the DevDome dashboard.

## Part of the DevDome plugin family

Free WordPress plugins by [DevDome](https://devdome.com): Analytics, Redirect Manager, Media Cleaner, Link Monitor, Affiliate Manager. Every plugin ships with the DevDome Dashboard inside wp-admin, so the others install in one click.

## Development

This repository mirrors the release published on WordPress.org. Bug reports and feature requests: open an issue here or use the [support forum](https://wordpress.org/support/plugin/devdome-analytics/).

## License

GPL-2.0 or later. See [LICENSE](LICENSE).
