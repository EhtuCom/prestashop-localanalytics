<?php
/**
 * Beacon endpoint: POST from views/js/tracker.js, answers 204 and never anything else.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class LocalAnalyticsCollectModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    private const TYPES = ['pageview', 'add_to_cart'];

    public function postProcess()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && Configuration::get(LocalAnalytics::CONF_ENABLED)
            && LocalAnalyticsTracker::validToken(Tools::getValue('ts'), Tools::getValue('sig'))
            && in_array(Tools::getValue('type'), self::TYPES, true)
        ) {
            try {
                LocalAnalyticsTracker::record([
                    'type' => Tools::getValue('type'),
                    'page_type' => preg_replace('/[^a-z0-9:_-]/i', '', (string) Tools::getValue('page')),
                    'path' => $this->cleanPath((string) Tools::getValue('path')),
                    'id_object' => (int) Tools::getValue('id'),
                    'quantity' => Tools::getValue('qty', ''),
                    'search' => (string) Tools::getValue('search'),
                    'referrer' => (string) Tools::getValue('ref'),
                    'utm_source' => (string) Tools::getValue('us'),
                    'utm_medium' => (string) Tools::getValue('um'),
                    'utm_campaign' => (string) Tools::getValue('uc'),
                    'language' => (string) Tools::getValue('bl'),
                    'shop_lang' => preg_replace('/[^a-z-]/i', '', (string) Tools::getValue('lang')),
                ]);
            } catch (Exception $e) {
                PrestaShopLogger::addLog('localanalytics: ' . $e->getMessage(), 2);
            }
        }

        header('Cache-Control: no-store');
        http_response_code(204);
        exit;
    }

    /** Path only, without query string (it can carry tokens or e-mail addresses). */
    private function cleanPath($path)
    {
        $path = (string) parse_url($path, PHP_URL_PATH);

        return substr($path, 0, 1) === '/' ? $path : null;
    }
}
