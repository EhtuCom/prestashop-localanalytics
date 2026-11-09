# Local Analytics for PrestaShop

First-party visit analytics for PrestaShop 8 and 9, stored in the shop's own database.
No cookies, no IP addresses, no third-party services, so no extra cookie-consent category.

## What it shows

Back office → **Sell → Local analytics** (*Vender → Analítica local*):

- **Overview** for today, 7, 30, 90 or 365 days, or any date range:
  - sessions, visitors, page views, pages per session, single-page sessions;
  - product views, add-to-cart, orders and revenue (from the shop's orders), conversion rate;
  - sessions per day (per month for long ranges), with the orders of each day;
  - sales funnel: session → product → cart → checkout → order confirmation;
  - most viewed products (with add-to-cart), categories and pages, internal searches;
  - referring sites, landing pages, `utm_source` / `utm_campaign`;
  - countries, browser language, shop language, devices, browsers, operating systems;
  - visitors right now (last 5 and 30 minutes) and the pages they are on.
- **Sessions per hour** for the last 24 h, 48 h, 72 h or 7 days, with your own **markers**
  (for example "Cloudflare Bot Fight Mode on"). For each marker it compares the average sessions per hour
  since the marker with the same clock hours of the 7 previous days.

## How it counts

- A small script (`views/js/tracker.js`, no dependencies) sends one beacon per page view and one per
  add-to-cart to `/module/localanalytics/collect`. Crawlers that do not run JavaScript are not counted;
  user agents of known bots, headless browsers (`navigator.webdriver`) and excluded IP addresses are dropped.
- Each page carries a token signed with the shop's own secret (`_COOKIE_KEY_`), valid for two hours,
  so the endpoint only accepts hits from pages the shop rendered. Max. 30 hits per visitor per minute.
- **Visitor** = hash of (random salt, IP address, user agent). The salt is replaced every day and the old one
  is not kept, so a visitor cannot be recognised from one day to the next and the IP address cannot be recovered.
  "Visitors" over a period is therefore the sum of daily visitors.
- **Session** = hits of the same visitor less than 30 minutes apart.
- The stored path never includes the query string. The country comes from Cloudflare's `CF-IPCountry` header
  when the shop is behind Cloudflare (no geolocation database needed).
- Hits older than the retention period (default 400 days) are deleted automatically.

Orders and revenue are read from the shop's `orders` table (valid orders), so they are exact even when a
payment is confirmed server to server.

## Install

1. Download `localanalytics.zip` from the [releases](../../releases), or zip the `localanalytics/` folder of this repository.
2. Back office → Modules → Module Manager → **Upload a module**.
3. Configure: add your office and staff IP addresses to the excluded list.

If your shop combines JavaScript files (Advanced Parameters → Performance → CCC) and sits behind a CDN,
clear the cache after installing so the CDN does not keep serving the old combined file.

Requirements: PrestaShop 8.0 or newer (tested on 9.2.1), PHP 7.4 or newer (tested on 8.1 and 8.3).

## Settings

| Setting | Default | |
|---|---|---|
| Record visits | on | |
| Excluded IP addresses | none | one per line; your current address is shown on the page |
| Keep data for (days) | 400 | 30 to 3650 |
| Markers on the hourly chart | none | one per line: `2026-10-16 10:11 \| Bot Fight Mode ON` |

## Privacy note

No cookies or local storage are used and no personal data is kept, which is why the module does not need cookie
consent under the ePrivacy rules in most EU countries. It is your responsibility to check this against your own
privacy policy and local law.

## Translations

English and Spanish are included (`translations/es.php`). Other languages can be added from
the back office (International → Translations → Installed modules).

## License

[MIT](LICENSE)
