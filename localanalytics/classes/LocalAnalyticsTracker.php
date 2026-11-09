<?php
/**
 * Records one hit. Privacy model:
 *  - no cookie and no IP address is stored;
 *  - visitor_id = hash(daily random salt, IP, user agent): the same browser is one visitor within a day,
 *    and the salt is replaced every day, so visitors cannot be followed from one day to the next;
 *  - session_id groups the hits of a visitor separated by less than 30 minutes.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class LocalAnalyticsTracker
{
    public const SESSION_GAP = 1800;
    public const TOKEN_TTL = 7200;
    public const MAX_HITS_PER_MINUTE = 30;

    private const BOT_UA = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|phantom|selenium|puppeteer|playwright|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|scanner|audit|python|curl|wget|httpclient|okhttp|java\/|go-http|axios|node-fetch|libwww|scrapy/i';

    /** HMAC of the page render time, keyed with the shop's own secret (_COOKIE_KEY_, never in the code). */
    public static function sign($ts)
    {
        return substr(hash_hmac('sha256', 'localanalytics|' . (int) $ts, _COOKIE_KEY_), 0, 24);
    }

    public static function validToken($ts, $sig)
    {
        $ts = (int) $ts;

        return $ts > 0
            && abs(time() - $ts) <= self::TOKEN_TTL
            && is_string($sig)
            && hash_equals(self::sign($ts), $sig);
    }

    public static function isBot($userAgent)
    {
        return $userAgent === '' || strlen($userAgent) < 20 || preg_match(self::BOT_UA, $userAgent);
    }

    /**
     * @param array $hit type, page_type, path, id_object, quantity, search, referrer, utm_*, language, shop_lang
     *
     * @return bool true when stored
     */
    public static function record(array $hit)
    {
        $ip = (string) Tools::getRemoteAddr();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        if (self::isBot($ua) || self::isExcluded($ip)) {
            return false;
        }

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . LocalAnalytics::TABLE;
        $now = time();
        $visitor = substr(hash('sha256', self::dailySalt() . '|' . $ip . '|' . $ua), 0, 16);

        $last = $db->getRow('SELECT `session_id`, UNIX_TIMESTAMP(`created_at`) AS ts,
                (SELECT COUNT(*) FROM `' . $table . '` WHERE `visitor_id` = \'' . pSQL($visitor) . '\'
                   AND `created_at` > \'' . pSQL(date('Y-m-d H:i:s', $now - 60)) . '\') AS last_minute
            FROM `' . $table . '` WHERE `visitor_id` = \'' . pSQL($visitor) . '\' ORDER BY `id_hit` DESC', false);
        if ($last && (int) $last['last_minute'] >= self::MAX_HITS_PER_MINUTE) {
            return false;
        }
        $isEntry = !$last || ($now - (int) $last['ts']) > self::SESSION_GAP;
        $session = $isEntry ? substr(hash('sha256', $visitor . '|' . $now . '|' . mt_rand()), 0, 16) : $last['session_id'];

        $client = self::parseUserAgent($ua);
        $referrerHost = null;
        if ($isEntry && !empty($hit['referrer'])) {
            $host = strtolower((string) parse_url((string) $hit['referrer'], PHP_URL_HOST));
            $own = strtolower((string) Tools::getHttpHost(false, false, true));
            $host = preg_replace('/^www\./', '', $host);
            if ($host !== '' && $host !== preg_replace('/^www\./', '', $own)) {
                $referrerHost = $host;
            }
        }

        $row = [
            'id_shop' => (int) Context::getContext()->shop->id,
            'created_at' => date('Y-m-d H:i:s', $now),
            'visitor_id' => $visitor,
            'session_id' => $session,
            'is_entry' => $isEntry ? 1 : 0,
            'type' => $hit['type'],
            'page_type' => self::clip($hit['page_type'] ?? null, 48),
            'path' => self::clip($hit['path'] ?? null, 255),
            'id_object' => !empty($hit['id_object']) ? (int) $hit['id_object'] : null,
            'quantity' => isset($hit['quantity']) && $hit['quantity'] !== '' ? (int) $hit['quantity'] : null,
            'search' => self::clip(isset($hit['search']) ? Tools::strtolower(trim($hit['search'])) : null, 100),
            'referrer_host' => self::clip($referrerHost, 100),
            'utm_source' => $isEntry ? self::clip($hit['utm_source'] ?? null, 64) : null,
            'utm_medium' => $isEntry ? self::clip($hit['utm_medium'] ?? null, 64) : null,
            'utm_campaign' => $isEntry ? self::clip($hit['utm_campaign'] ?? null, 64) : null,
            'country' => self::country(),
            'language' => self::clip(self::language($hit['language'] ?? ''), 8),
            'shop_lang' => self::clip($hit['shop_lang'] ?? null, 5),
            'device' => $client['device'],
            'browser' => $client['browser'],
            'os' => $client['os'],
        ];
        $row = array_map(function ($v) {
            return $v === null ? null : (is_int($v) ? $v : pSQL((string) $v));
        }, $row);

        $ok = $db->insert(LocalAnalytics::TABLE, $row, true);

        if (mt_rand(1, 500) === 1) {
            self::purge();
        }

        return (bool) $ok;
    }

    public static function purge()
    {
        $days = max(30, (int) Configuration::get(LocalAnalytics::CONF_RETENTION_DAYS) ?: 400);
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . LocalAnalytics::TABLE . '`
            WHERE `created_at` < \'' . pSQL(date('Y-m-d H:i:s', time() - $days * 86400)) . '\' LIMIT 20000');
    }

    /** Random salt replaced every day; yesterday's salt is not kept anywhere. */
    private static function dailySalt()
    {
        $today = date('Y-m-d');
        if (Configuration::getGlobalValue(LocalAnalytics::CONF_SALT_DAY) !== $today) {
            Configuration::updateGlobalValue(LocalAnalytics::CONF_SALT, bin2hex(random_bytes(16)));
            Configuration::updateGlobalValue(LocalAnalytics::CONF_SALT_DAY, $today);
        }

        return (string) Configuration::getGlobalValue(LocalAnalytics::CONF_SALT);
    }

    private static function isExcluded($ip)
    {
        $list = (string) Configuration::get(LocalAnalytics::CONF_EXCLUDED_IPS);

        return $list !== '' && in_array($ip, explode(',', $list), true);
    }

    /** Country from Cloudflare's CF-IPCountry header when the shop is behind Cloudflare. */
    private static function country()
    {
        $code = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));

        return preg_match('/^[A-Z]{2}$/', $code) && !in_array($code, ['XX', 'T1'], true) ? $code : null;
    }

    private static function language($fromBrowser)
    {
        $value = $fromBrowser !== '' ? $fromBrowser : (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if (preg_match('/^\s*([a-zA-Z]{2,3})/', $value, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    public static function parseUserAgent($ua)
    {
        $device = 'desktop';
        if (preg_match('/iPad|Tablet|Nexus (7|9|10)|SM-T|Kindle|Silk|Android(?!.*Mobile)/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua)) {
            $device = 'mobile';
        }

        $browser = 'Other';
        foreach ([
            'Edge' => '/Edg(e|A|iOS)?\//', 'Opera' => '/OPR\/|Opera/', 'Samsung Internet' => '/SamsungBrowser/',
            'Firefox' => '/Firefox\/|FxiOS/', 'Chrome' => '/Chrome\/|CriOS/', 'Safari' => '/Safari\//',
        ] as $name => $re) {
            if (preg_match($re, $ua)) {
                $browser = $name;
                break;
            }
        }

        $os = 'Other';
        foreach ([
            'iOS' => '/iPhone|iPad|iPod/', 'Android' => '/Android/', 'Windows' => '/Windows/',
            'macOS' => '/Mac OS X|Macintosh/', 'ChromeOS' => '/CrOS/', 'Linux' => '/Linux/',
        ] as $name => $re) {
            if (preg_match($re, $ua)) {
                $os = $name;
                break;
            }
        }

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }

    private static function clip($value, $length)
    {
        if ($value === null) {
            return null;
        }
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : Tools::substr($value, 0, $length);
    }
}
