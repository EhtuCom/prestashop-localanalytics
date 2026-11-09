<?php
/**
 * Read side: every figure of the back-office dashboard.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class LocalAnalyticsStats
{
    /** @var Db */
    private $db;
    private $table;
    private $idShop;
    private $idLang;

    public function __construct($idShop, $idLang)
    {
        $this->db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $this->table = _DB_PREFIX_ . LocalAnalytics::TABLE;
        $this->idShop = (int) $idShop;
        $this->idLang = (int) $idLang;
    }

    private function where($since, $until, $extra = '')
    {
        return ' WHERE h.`id_shop` = ' . $this->idShop
            . ' AND h.`created_at` >= \'' . pSQL($since) . '\' AND h.`created_at` <= \'' . pSQL($until) . '\''
            . ($extra !== '' ? ' AND ' . $extra : '');
    }

    public function summary($since, $until)
    {
        $w = $this->where($since, $until);
        $row = $this->db->getRow('SELECT
                COUNT(DISTINCT h.`session_id`) AS sessions,
                COUNT(DISTINCT h.`visitor_id`) AS visitors,
                SUM(h.`type` = \'pageview\') AS pageviews,
                SUM(h.`type` = \'pageview\' AND h.`page_type` = \'product\') AS product_views,
                SUM(h.`type` = \'add_to_cart\') AS add_to_cart,
                COUNT(DISTINCT IF(h.`type` = \'add_to_cart\', h.`session_id`, NULL)) AS cart_sessions,
                COUNT(DISTINCT IF(h.`page_type` = \'product\', h.`session_id`, NULL)) AS product_sessions,
                COUNT(DISTINCT IF(h.`page_type` = \'order\', h.`session_id`, NULL)) AS checkout_sessions,
                COUNT(DISTINCT IF(h.`page_type` = \'order-confirmation\', h.`session_id`, NULL)) AS order_sessions
            FROM `' . $this->table . '` h' . $w, false) ?: [];

        $single = (int) $this->db->getValue('SELECT COUNT(*) FROM (SELECT h.`session_id` FROM `' . $this->table . '` h' . $w
            . ' AND h.`type` = \'pageview\' GROUP BY h.`session_id` HAVING COUNT(*) = 1) s', false);

        $orders = $this->db->getRow('SELECT COUNT(*) AS n, COALESCE(SUM(o.`total_paid_tax_incl` / o.`conversion_rate`), 0) AS revenue
            FROM `' . _DB_PREFIX_ . 'orders` o
            WHERE o.`valid` = 1 AND o.`id_shop` = ' . $this->idShop . '
              AND o.`date_add` >= \'' . pSQL($since) . '\' AND o.`date_add` <= \'' . pSQL($until) . '\'', false) ?: [];

        $s = array_map('intval', $row);
        $s['bounce_rate'] = $s['sessions'] ? round($single / $s['sessions'] * 100) : 0;
        $s['pages_per_session'] = $s['sessions'] ? round($s['pageviews'] / $s['sessions'], 1) : 0;
        $s['orders'] = (int) ($orders['n'] ?? 0);
        $s['revenue'] = (float) ($orders['revenue'] ?? 0);
        $s['conversion'] = $s['sessions'] ? round($s['orders'] / $s['sessions'] * 100, 2) : 0;

        return $s;
    }

    /** Sessions, page views and orders per day (per month when the period is longer than 120 days). */
    public function timeline($since, $until)
    {
        $days = (strtotime($until) - strtotime($since)) / 86400;
        $monthly = $days > 120;
        $fmt = $monthly ? '%Y-%m' : '%Y-%m-%d';

        $hits = $this->db->executeS('SELECT DATE_FORMAT(h.`created_at`, \'' . $fmt . '\') AS k,
                COUNT(DISTINCT h.`session_id`) AS sessions, SUM(h.`type` = \'pageview\') AS pageviews
            FROM `' . $this->table . '` h' . $this->where($since, $until) . ' GROUP BY k', true, false) ?: [];
        $orders = $this->db->executeS('SELECT DATE_FORMAT(o.`date_add`, \'' . $fmt . '\') AS k, COUNT(*) AS n
            FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`valid` = 1 AND o.`id_shop` = ' . $this->idShop . '
              AND o.`date_add` >= \'' . pSQL($since) . '\' AND o.`date_add` <= \'' . pSQL($until) . '\' GROUP BY k', true, false) ?: [];
        $byKey = [];
        foreach ($hits as $r) {
            $byKey[$r['k']] = ['sessions' => (int) $r['sessions'], 'pageviews' => (int) $r['pageviews'], 'orders' => 0];
        }
        foreach ($orders as $r) {
            $byKey[$r['k']]['orders'] = (int) $r['n'];
        }

        $rows = [];
        $cursor = strtotime(date($monthly ? 'Y-m-01' : 'Y-m-d', strtotime($since)));
        $end = strtotime($until);
        while ($cursor <= $end) {
            $k = date($monthly ? 'Y-m' : 'Y-m-d', $cursor);
            $rows[] = ['key' => $k, 'sessions' => $byKey[$k]['sessions'] ?? 0,
                'pageviews' => $byKey[$k]['pageviews'] ?? 0, 'orders' => $byKey[$k]['orders'] ?? 0];
            $cursor = strtotime($monthly ? '+1 month' : '+1 day', $cursor);
        }

        return ['monthly' => $monthly, 'rows' => $rows];
    }

    /**
     * Sessions per hour for the last $hours hours, plus, for every marker, the average sessions per hour since
     * the marker against the same clock hours of the 7 previous days (bots follow the clock, a flat average misleads).
     */
    public function hourly($hours, array $marks)
    {
        $end = strtotime(date('Y-m-d H:00:00')) + 3600;
        $start = $end - $hours * 3600;
        $since = date('Y-m-d H:i:s', $start);
        $until = date('Y-m-d H:i:s', $end - 1);

        $data = $this->db->executeS('SELECT DATE_FORMAT(h.`created_at`, \'%Y-%m-%d %H:00\') AS k,
                COUNT(DISTINCT h.`session_id`) AS sessions, COUNT(DISTINCT h.`visitor_id`) AS visitors,
                SUM(h.`type` = \'pageview\' AND h.`page_type` = \'product\') AS product_views
            FROM `' . $this->table . '` h' . $this->where($since, $until) . ' GROUP BY k', true, false) ?: [];
        $byKey = [];
        foreach ($data as $r) {
            $byKey[$r['k']] = $r;
        }
        $rows = [];
        for ($t = $start; $t < $end; $t += 3600) {
            $k = date('Y-m-d H:00', $t);
            $rows[] = ['at' => $t, 'key' => $k, 'sessions' => (int) ($byKey[$k]['sessions'] ?? 0),
                'visitors' => (int) ($byKey[$k]['visitors'] ?? 0), 'product_views' => (int) ($byKey[$k]['product_views'] ?? 0)];
        }

        $now = time();
        $comparisons = [];
        foreach ($marks as $mark) {
            $afterHours = ($now - $mark['at']) / 3600;
            if ($mark['at'] > $now || $afterHours < 1) {
                continue;
            }
            $after = (int) $this->sessionsBetween($mark['at'], $now);
            $before = 0;
            for ($d = 1; $d <= 7; ++$d) {
                $before += (int) $this->sessionsBetween($mark['at'] - $d * 86400, $now - $d * 86400);
            }
            $b = $before / ($afterHours * 7);
            $a = $after / $afterHours;
            $comparisons[] = ['label' => $mark['label'], 'at' => $mark['at'], 'hours' => (int) floor($afterHours),
                'before' => round($b, 1), 'after' => round($a, 1), 'change' => $b > 0 ? (int) round(($a - $b) / $b * 100) : null];
        }

        return ['rows' => $rows, 'start' => $start, 'end' => $end, 'comparisons' => $comparisons,
            'max' => max(1, max(array_column($rows, 'sessions') ?: [0]))];
    }

    private function sessionsBetween($from, $to)
    {
        return $this->db->getValue('SELECT COUNT(DISTINCT h.`session_id`) FROM `' . $this->table . '` h'
            . $this->where(date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)), false);
    }

    /** Visitors on the site now: last 5 and 30 minutes, and the pages they are on. */
    public function realtime()
    {
        $w5 = $this->where(date('Y-m-d H:i:s', time() - 300), date('Y-m-d H:i:s'));
        $w30 = $this->where(date('Y-m-d H:i:s', time() - 1800), date('Y-m-d H:i:s'));

        return [
            'now' => (int) $this->db->getValue('SELECT COUNT(DISTINCT h.`visitor_id`) FROM `' . $this->table . '` h' . $w5, false),
            'last30' => (int) $this->db->getValue('SELECT COUNT(DISTINCT h.`visitor_id`) FROM `' . $this->table . '` h' . $w30, false),
            'pages' => $this->db->executeS('SELECT h.`path` AS label, COUNT(DISTINCT h.`visitor_id`) AS n FROM `' . $this->table . '` h'
                . $w5 . ' AND h.`type` = \'pageview\' GROUP BY h.`path` ORDER BY n DESC LIMIT 5', true, false) ?: [],
        ];
    }

    public function topProducts($since, $until, $limit = 15)
    {
        $rows = $this->db->executeS('SELECT h.`id_object` AS id,
                SUM(h.`type` = \'pageview\') AS views, COUNT(DISTINCT IF(h.`type` = \'pageview\', h.`session_id`, NULL)) AS sessions,
                SUM(h.`type` = \'add_to_cart\') AS carts, pl.`name`
            FROM `' . $this->table . '` h
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = h.`id_object` AND pl.`id_lang` = ' . $this->idLang . ' AND pl.`id_shop` = ' . $this->idShop
            . $this->where($since, $until, 'h.`id_object` IS NOT NULL AND (h.`type` = \'add_to_cart\' OR h.`page_type` = \'product\')')
            . ' GROUP BY h.`id_object`, pl.`name` ORDER BY views DESC, carts DESC LIMIT ' . (int) $limit, true, false) ?: [];
        foreach ($rows as &$r) {
            $r['label'] = $r['name'] ?: ('#' . $r['id']);
            $r['url'] = $r['name'] ? Context::getContext()->link->getProductLink((int) $r['id']) : null;
        }

        return $rows;
    }

    public function topCategories($since, $until, $limit = 10)
    {
        return $this->db->executeS('SELECT h.`id_object` AS id, COALESCE(cl.`name`, CONCAT(\'#\', h.`id_object`)) AS label,
                COUNT(*) AS n
            FROM `' . $this->table . '` h
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = h.`id_object` AND cl.`id_lang` = ' . $this->idLang . ' AND cl.`id_shop` = ' . $this->idShop
            . $this->where($since, $until, 'h.`type` = \'pageview\' AND h.`page_type` = \'category\' AND h.`id_object` IS NOT NULL')
            . ' GROUP BY h.`id_object`, cl.`name` ORDER BY n DESC LIMIT ' . (int) $limit, true, false) ?: [];
    }

    /**
     * Generic "top N" of one column. $per = 'session' counts entry hits (one per session),
     * 'hit' counts page views.
     */
    public function top($since, $until, $column, $limit = 10, $per = 'session', $extra = '', $emptyLabel = null)
    {
        $allowed = ['path', 'page_type', 'search', 'referrer_host', 'utm_source', 'utm_campaign', 'country',
            'language', 'shop_lang', 'device', 'browser', 'os'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }
        $cond = $per === 'session' ? 'h.`is_entry` = 1' : 'h.`type` = \'pageview\'';
        if ($extra !== '') {
            $cond .= ' AND ' . $extra;
        }
        if ($emptyLabel === null) {
            $cond .= ' AND h.`' . $column . '` IS NOT NULL';
        }
        $rows = $this->db->executeS('SELECT h.`' . $column . '` AS label, COUNT(*) AS n
            FROM `' . $this->table . '` h' . $this->where($since, $until, $cond)
            . ' GROUP BY h.`' . $column . '` ORDER BY n DESC LIMIT ' . (int) $limit, true, false) ?: [];
        foreach ($rows as &$r) {
            if ($r['label'] === null) {
                $r['label'] = $emptyLabel;
            }
        }

        return $rows;
    }
}
