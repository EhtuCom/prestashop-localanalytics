<?php
/**
 * Back-office dashboard: Sell > Local analytics.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'localanalytics/localanalytics.php';

class AdminLocalAnalyticsController extends ModuleAdminController
{
    private const PRESETS = [1, 7, 30, 90, 365];
    private const HOURLY_PRESETS = [24, 48, 72, 168];

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS(_MODULE_DIR_ . 'localanalytics/views/css/admin.css');
    }

    public function initContent()
    {
        parent::initContent();

        $view = Tools::getValue('view') === 'hourly' ? 'hourly' : 'overview';
        $stats = new LocalAnalyticsStats($this->context->shop->id, $this->context->language->id);
        $base = $this->context->link->getAdminLink('AdminLocalAnalytics');

        $vars = [
            'la_view' => $view,
            'la_base' => $base,
            'la_realtime' => $stats->realtime(),
            'la_config_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'localanalytics']),
            'la_enabled' => (bool) Configuration::get(LocalAnalytics::CONF_ENABLED),
        ];

        if ($view === 'hourly') {
            $hours = (int) Tools::getValue('hours', 72);
            $hours = in_array($hours, self::HOURLY_PRESETS, true) ? $hours : 72;
            $vars += [
                'la_hours' => $hours,
                'la_hourly_presets' => self::HOURLY_PRESETS,
                'la_hourly' => $stats->hourly($hours, $this->module->getMarks()),
                'la_marks' => $this->module->getMarks(),
            ];
        } else {
            list($since, $until, $days, $from, $to) = $this->range();
            $summary = $stats->summary($since, $until);
            $vars += [
                'la_revenue' => $this->context->getCurrentLocale()->formatPrice($summary['revenue'], $this->context->currency->iso_code),
                'la_funnel' => $this->funnel($summary),
                'la_presets' => self::PRESETS,
                'la_days' => $days,
                'la_from' => $from,
                'la_to' => $to,
                'la_summary' => $summary,
                'la_timeline' => $timeline = $stats->timeline($since, $until),
                'la_label_step' => max(1, (int) ceil(count($timeline['rows']) / 12)),
                'la_products' => $stats->topProducts($since, $until),
                'la_categories' => $stats->topCategories($since, $until),
                'la_pages' => $stats->top($since, $until, 'path', 15, 'hit'),
                'la_landing' => $stats->top($since, $until, 'path', 10, 'session'),
                'la_page_types' => $stats->top($since, $until, 'page_type', 12, 'hit'),
                'la_searches' => $stats->top($since, $until, 'search', 15, 'hit', 'h.`page_type` = \'search\''),
                'la_referrers' => $stats->top($since, $until, 'referrer_host', 12, 'session', '', $this->module->l('(direct)', 'adminlocalanalyticscontroller')),
                'la_campaigns' => $stats->top($since, $until, 'utm_campaign', 10, 'session'),
                'la_sources' => $stats->top($since, $until, 'utm_source', 10, 'session'),
                'la_countries' => $this->withCountryNames($stats->top($since, $until, 'country', 15, 'session')),
                'la_languages' => $stats->top($since, $until, 'language', 10, 'session'),
                'la_shop_langs' => $stats->top($since, $until, 'shop_lang', 10, 'session'),
                'la_devices' => $this->withDeviceNames($stats->top($since, $until, 'device', 5, 'session')),
                'la_browsers' => $stats->top($since, $until, 'browser', 8, 'session'),
                'la_os' => $stats->top($since, $until, 'os', 8, 'session'),
            ];
        }

        $this->context->smarty->assign($vars);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'localanalytics/views/templates/admin/dashboard.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * ?days=N (preset, default 30) or ?from=YYYY-MM-DD&to=YYYY-MM-DD (custom, inclusive; wins over days).
     */
    private function range()
    {
        $from = $this->date(Tools::getValue('from'));
        $to = $this->date(Tools::getValue('to'));
        if ($from || $to) {
            $to = $to ?: date('Y-m-d');
            $from = $from ?: $to;
            if ($from > $to) {
                list($from, $to) = [$to, $from];
            }

            return [$from . ' 00:00:00', $to . ' 23:59:59', null, $from, $to];
        }
        $days = (int) Tools::getValue('days', 30);
        $days = in_array($days, self::PRESETS, true) ? $days : 30;
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        return [$from . ' 00:00:00', date('Y-m-d') . ' 23:59:59', $days, $from, date('Y-m-d')];
    }

    private function funnel(array $s)
    {
        $total = max(1, $s['sessions']);
        $steps = [
            [$this->module->l('All sessions', 'adminlocalanalyticscontroller'), $s['sessions']],
            [$this->module->l('Viewed a product', 'adminlocalanalyticscontroller'), $s['product_sessions']],
            [$this->module->l('Added to cart', 'adminlocalanalyticscontroller'), $s['cart_sessions']],
            [$this->module->l('Reached checkout', 'adminlocalanalyticscontroller'), $s['checkout_sessions']],
            [$this->module->l('Order confirmation page', 'adminlocalanalyticscontroller'), $s['order_sessions']],
        ];

        return array_map(function ($step) use ($total) {
            return ['label' => $step[0], 'n' => $step[1], 'pct' => $step[1] / $total * 100];
        }, $steps);
    }

    private function date($value)
    {
        $value = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }

    private function withDeviceNames(array $rows)
    {
        $names = [
            'desktop' => $this->module->l('Computer', 'adminlocalanalyticscontroller'),
            'mobile' => $this->module->l('Mobile', 'adminlocalanalyticscontroller'),
            'tablet' => $this->module->l('Tablet', 'adminlocalanalyticscontroller'),
        ];
        foreach ($rows as &$r) {
            $r['label'] = $names[$r['label']] ?? $r['label'];
        }

        return $rows;
    }

    private function withCountryNames(array $rows)
    {
        foreach ($rows as &$r) {
            $id = (int) Country::getByIso($r['label']);
            $r['label'] = $id ? Country::getNameById($this->context->language->id, $id) . ' (' . $r['label'] . ')' : $r['label'];
        }

        return $rows;
    }
}
