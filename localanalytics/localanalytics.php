<?php
/**
 * Local Analytics - first-party, cookieless visit analytics for PrestaShop 8/9.
 *
 * @license MIT (see LICENSE in the repository root)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/LocalAnalyticsTracker.php';
require_once __DIR__ . '/classes/LocalAnalyticsStats.php';

class LocalAnalytics extends Module
{
    public const TABLE = 'localanalytics_hit';

    public const CONF_ENABLED = 'LOCALANALYTICS_ENABLED';
    public const CONF_EXCLUDED_IPS = 'LOCALANALYTICS_EXCLUDED_IPS';
    public const CONF_RETENTION_DAYS = 'LOCALANALYTICS_RETENTION_DAYS';
    public const CONF_MARKS = 'LOCALANALYTICS_MARKS';
    public const CONF_SALT = 'LOCALANALYTICS_SALT';
    public const CONF_SALT_DAY = 'LOCALANALYTICS_SALT_DAY';

    public $tabs = [
        [
            'name' => ['en' => 'Local analytics', 'es' => 'Analítica local', 'ca' => 'Analítica local', 'fr' => 'Statistiques locales', 'de' => 'Lokale Statistik'],
            'class_name' => 'AdminLocalAnalytics',
            'route_name' => '',
            'parent_class_name' => 'SELL',
            'visible' => true,
            'icon' => 'insights',
        ],
    ];

    public function __construct()
    {
        $this->name = 'localanalytics';
        $this->tab = 'analytics_stats';
        $this->version = '1.0.0';
        $this->author = 'EHTU';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Local analytics');
        $this->description = $this->l('First-party visit analytics without cookies or third parties: sessions, visitors, products, cart, orders, countries, devices and traffic sources.');
    }

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionFrontControllerSetMedia')
            && Configuration::updateValue(self::CONF_ENABLED, 1)
            && Configuration::updateValue(self::CONF_RETENTION_DAYS, 400)
            && Configuration::updateValue(self::CONF_EXCLUDED_IPS, '')
            && Configuration::updateValue(self::CONF_MARKS, '');
    }

    public function uninstall()
    {
        foreach ([self::CONF_ENABLED, self::CONF_EXCLUDED_IPS, self::CONF_RETENTION_DAYS, self::CONF_MARKS,
            self::CONF_SALT, self::CONF_SALT_DAY] as $key) {
            Configuration::deleteByName($key);
        }

        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`')
            && parent::uninstall();
    }

    private function installDb()
    {
        // One row per tracked hit. No IP address and no cookie: visitor_id is a hash of
        // (daily rotating salt, IP, user agent), so it cannot be linked across days.
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_hit` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `visitor_id` CHAR(16) NOT NULL,
            `session_id` CHAR(16) NOT NULL,
            `is_entry` TINYINT(1) NOT NULL DEFAULT 0,
            `type` VARCHAR(16) NOT NULL,
            `page_type` VARCHAR(48) NULL,
            `path` VARCHAR(255) NULL,
            `id_object` INT UNSIGNED NULL,
            `quantity` INT NULL,
            `search` VARCHAR(100) NULL,
            `referrer_host` VARCHAR(100) NULL,
            `utm_source` VARCHAR(64) NULL,
            `utm_medium` VARCHAR(64) NULL,
            `utm_campaign` VARCHAR(64) NULL,
            `country` CHAR(2) NULL,
            `language` VARCHAR(8) NULL,
            `shop_lang` VARCHAR(5) NULL,
            `device` VARCHAR(8) NULL,
            `browser` VARCHAR(20) NULL,
            `os` VARCHAR(20) NULL,
            PRIMARY KEY (`id_hit`),
            KEY `created` (`id_shop`, `created_at`),
            KEY `visitor` (`visitor_id`, `id_hit`),
            KEY `type_created` (`type`, `created_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * Adds the tracker on every front page with the page context and a signed token,
     * so the collect endpoint only accepts hits from pages the shop actually rendered.
     */
    public function hookActionFrontControllerSetMedia()
    {
        if (!Configuration::get(self::CONF_ENABLED)) {
            return;
        }
        $controller = $this->context->controller;
        if ($controller instanceof LocalAnalyticsCollectModuleFrontController) {
            return;
        }

        $pageType = !empty($controller->php_self) ? $controller->php_self : null;
        if (!$pageType && $controller instanceof ModuleFrontController && isset($controller->module->name)) {
            $pageType = 'module:' . $controller->module->name;
        }
        $idObject = 0;
        foreach (['product' => 'id_product', 'category' => 'id_category', 'cms' => 'id_cms',
            'manufacturer' => 'id_manufacturer', 'supplier' => 'id_supplier'] as $page => $param) {
            if ($pageType === $page) {
                $idObject = (int) Tools::getValue($param);
            }
        }

        $ts = time();
        Media::addJsDef(['localanalytics' => [
            'url' => $this->context->link->getModuleLink($this->name, 'collect', [], true),
            'page' => $pageType ?: 'other',
            'id' => $idObject,
            'lang' => $this->context->language->iso_code,
            'ts' => $ts,
            'sig' => LocalAnalyticsTracker::sign($ts),
        ]]);
        $this->context->controller->registerJavascript(
            'module-localanalytics-tracker',
            'modules/' . $this->name . '/views/js/tracker.js',
            ['position' => 'bottom', 'priority' => 900]
        );
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitLocalAnalytics')) {
            $ips = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) Tools::getValue(self::CONF_EXCLUDED_IPS))));
            $ips = array_filter($ips, function ($ip) {
                return (bool) filter_var($ip, FILTER_VALIDATE_IP);
            });
            Configuration::updateValue(self::CONF_ENABLED, (int) Tools::getValue(self::CONF_ENABLED));
            Configuration::updateValue(self::CONF_EXCLUDED_IPS, implode(',', $ips));
            Configuration::updateValue(self::CONF_RETENTION_DAYS, max(30, min(3650, (int) Tools::getValue(self::CONF_RETENTION_DAYS))));
            Configuration::updateValue(self::CONF_MARKS, trim((string) Tools::getValue(self::CONF_MARKS)));
            $output .= $this->displayConfirmation($this->l('Settings saved.'));
        }

        $dashboard = $this->context->link->getAdminLink('AdminLocalAnalytics');
        $output .= '<div class="alert alert-info">' . $this->l('The statistics are in the back office menu') . ' <a href="'
            . htmlspecialchars($dashboard) . '">' . $this->l('Local analytics') . '</a>. '
            . $this->l('Your current IP address is') . ' <code>' . htmlspecialchars(Tools::getRemoteAddr()) . '</code>.</div>';

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitLocalAnalytics';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            self::CONF_ENABLED => (int) Configuration::get(self::CONF_ENABLED),
            self::CONF_EXCLUDED_IPS => str_replace(',', "\n", (string) Configuration::get(self::CONF_EXCLUDED_IPS)),
            self::CONF_RETENTION_DAYS => (int) Configuration::get(self::CONF_RETENTION_DAYS),
            self::CONF_MARKS => (string) Configuration::get(self::CONF_MARKS),
        ];

        return $output . $helper->generateForm([[
            'form' => [
                'legend' => ['title' => $this->l('Settings'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch', 'name' => self::CONF_ENABLED, 'label' => $this->l('Record visits'), 'is_bool' => true,
                        'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]],
                    ],
                    [
                        'type' => 'textarea', 'name' => self::CONF_EXCLUDED_IPS, 'label' => $this->l('Excluded IP addresses'), 'rows' => 4,
                        'desc' => $this->l('One per line. Visits from these addresses (office, staff) are not recorded.'),
                    ],
                    [
                        'type' => 'text', 'name' => self::CONF_RETENTION_DAYS, 'label' => $this->l('Keep data for (days)'), 'class' => 'fixed-width-sm',
                        'desc' => $this->l('Older hits are deleted automatically (30 to 3650).'),
                    ],
                    [
                        'type' => 'textarea', 'name' => self::CONF_MARKS, 'label' => $this->l('Markers on the hourly chart'), 'rows' => 4,
                        'desc' => $this->l('One per line: "YYYY-MM-DD HH:MM | label", e.g. a change of bot protection, to compare traffic before and after.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ]]);
    }

    /**
     * Markers configured for the hourly chart: [['at' => timestamp, 'label' => string], ...].
     */
    public function getMarks()
    {
        $marks = [];
        foreach (preg_split('/\R/', (string) Configuration::get(self::CONF_MARKS)) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && ($ts = strtotime($parts[0])) !== false) {
                $marks[] = ['at' => $ts, 'label' => $parts[1]];
            }
        }
        usort($marks, function ($a, $b) {
            return $a['at'] <=> $b['at'];
        });

        return $marks;
    }
}
