{**
 * Local Analytics dashboard. @license MIT
 *}
{function name=la_bars rows=[] title='' unit='' empty=''}
  <div class="la-card">
    <h3>{$title|escape:'html':'UTF-8'}</h3>
    {if $rows}
      {assign var=la_max value=1}
      {foreach $rows as $r}{if $r.n > $la_max}{assign var=la_max value=$r.n}{/if}{/foreach}
      <table class="la-bars">
        {foreach $rows as $r}
          <tr>
            <td class="la-bars-label" title="{$r.label|escape:'html':'UTF-8'}">{$r.label|escape:'html':'UTF-8'}</td>
            <td class="la-bars-bar"><span style="width:{($r.n / $la_max * 100)|string_format:'%.1f'}%"></span></td>
            <td class="la-bars-n">{$r.n|intval}</td>
          </tr>
        {/foreach}
      </table>
      {if $unit}<p class="la-unit">{$unit|escape:'html':'UTF-8'}</p>{/if}
    {else}
      <p class="la-empty">{if $empty}{$empty|escape:'html':'UTF-8'}{else}{l s='No data in this period.' mod='localanalytics'}{/if}</p>
    {/if}
  </div>
{/function}

<div class="la">
  <div class="la-head">
    <ul class="la-tabs">
      <li class="{if $la_view == 'overview'}active{/if}"><a href="{$la_base|escape:'html':'UTF-8'}">{l s='Overview' mod='localanalytics'}</a></li>
      <li class="{if $la_view == 'hourly'}active{/if}"><a href="{$la_base|escape:'html':'UTF-8'}&amp;view=hourly">{l s='Sessions per hour' mod='localanalytics'}</a></li>
    </ul>
    <div class="la-live" title="{l s='Visitors with activity in the last 5 minutes' mod='localanalytics'}">
      <span class="la-dot"></span> {l s='Now' mod='localanalytics'}: <strong>{$la_realtime.now|intval}</strong>
      &middot; {l s='last 30 min' mod='localanalytics'}: <strong>{$la_realtime.last30|intval}</strong>
    </div>
    <a class="la-settings" href="{$la_config_url|escape:'html':'UTF-8'}"><i class="material-icons">settings</i> {l s='Settings' mod='localanalytics'}</a>
  </div>

  {if !$la_enabled}
    <div class="alert alert-warning">{l s='Recording is switched off in the module settings.' mod='localanalytics'}</div>
  {/if}

{if $la_view == 'hourly'}

  <div class="la-range">
    {foreach $la_hourly_presets as $h}
      <a class="btn {if $h == $la_hours}btn-primary{else}btn-default{/if}" href="{$la_base|escape:'html':'UTF-8'}&amp;view=hourly&amp;hours={$h|intval}">{if $h == 168}7 {l s='days' mod='localanalytics'}{else}{$h|intval} h{/if}</a>
    {/foreach}
  </div>

  <div class="la-card">
    <h3>{l s='Sessions per hour' mod='localanalytics'} <small>{$la_hourly.start|date_format:'%d/%m %H:%M'} &ndash; {$la_hourly.end|date_format:'%d/%m %H:%M'}</small></h3>
    <div class="la-chart la-chart-hourly">
      {foreach $la_hourly.rows as $r}
        <div class="la-col{if $r.at|date_format:'%H' == '00'} la-midnight{/if}" title="{$r.key|escape:'html':'UTF-8'}: {$r.sessions|intval} {l s='sessions' mod='localanalytics'}, {$r.visitors|intval} {l s='visitors' mod='localanalytics'}, {$r.product_views|intval} {l s='product views' mod='localanalytics'}">
          <span class="la-bar" style="height:{($r.sessions / $la_hourly.max * 100)|string_format:'%.1f'}%"></span>
          {foreach $la_marks as $m}
            {if $m.at >= $r.at && $m.at < $r.at + 3600}<span class="la-mark" title="{$m.label|escape:'html':'UTF-8'}"></span>{/if}
          {/foreach}
          {if $r.at|date_format:'%H' == '00'}<span class="la-col-label">{$r.at|date_format:'%d/%m'}</span>{/if}
        </div>
      {/foreach}
    </div>
    <p class="la-unit">{l s='Maximum' mod='localanalytics'}: {$la_hourly.max|intval} {l s='sessions per hour' mod='localanalytics'}. {l s='Red lines are the markers set in the settings.' mod='localanalytics'}</p>
  </div>

  <div class="la-card">
    <h3>{l s='Before and after each marker' mod='localanalytics'}</h3>
    {if $la_hourly.comparisons}
      <p class="la-unit">{l s='Average sessions per hour since the marker, against the same clock hours of the 7 previous days.' mod='localanalytics'}</p>
      <table class="table">
        <thead><tr><th>{l s='Marker' mod='localanalytics'}</th><th>{l s='Hours since' mod='localanalytics'}</th><th>{l s='Before' mod='localanalytics'}</th><th>{l s='After' mod='localanalytics'}</th><th>{l s='Change' mod='localanalytics'}</th></tr></thead>
        <tbody>
          {foreach $la_hourly.comparisons as $c}
            <tr>
              <td>{$c.at|date_format:'%d/%m/%Y %H:%M'} &middot; {$c.label|escape:'html':'UTF-8'}</td>
              <td>{$c.hours|intval}</td>
              <td>{$c.before|escape:'html':'UTF-8'}</td>
              <td>{$c.after|escape:'html':'UTF-8'}</td>
              <td>{if $c.change === null}&ndash;{else}<strong class="{if $c.change < 0}la-down{else}la-up{/if}">{if $c.change > 0}+{/if}{$c.change|intval}%</strong>{/if}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <p class="la-empty">{l s='No markers in the past yet. Add them in the settings, one per line: "YYYY-MM-DD HH:MM | label".' mod='localanalytics'}</p>
    {/if}
  </div>

{else}

  <form class="la-range" method="get" action="{$la_base|escape:'html':'UTF-8'}">
    {foreach $la_presets as $d}
      <a class="btn {if $d == $la_days}btn-primary{else}btn-default{/if}" href="{$la_base|escape:'html':'UTF-8'}&amp;days={$d|intval}">{if $d == 1}{l s='Today' mod='localanalytics'}{else}{$d|intval} {l s='days' mod='localanalytics'}{/if}</a>
    {/foreach}
    <span class="la-custom">
      <input type="hidden" name="controller" value="AdminLocalAnalytics">
      <input type="hidden" name="token" value="{$smarty.get.token|escape:'html':'UTF-8'}">
      <input type="date" name="from" value="{$la_from|escape:'html':'UTF-8'}"> &ndash;
      <input type="date" name="to" value="{$la_to|escape:'html':'UTF-8'}">
      <button class="btn btn-default" type="submit">{l s='Apply' mod='localanalytics'}</button>
    </span>
  </form>

  <div class="la-kpis">
    <div class="la-kpi"><span>{l s='Sessions' mod='localanalytics'}</span><strong>{$la_summary.sessions|intval}</strong></div>
    <div class="la-kpi" title="{l s='Distinct visitors per day, added up over the period (no cookies: a visitor cannot be recognised from one day to the next)' mod='localanalytics'}"><span>{l s='Visitors' mod='localanalytics'}</span><strong>{$la_summary.visitors|intval}</strong></div>
    <div class="la-kpi"><span>{l s='Page views' mod='localanalytics'}</span><strong>{$la_summary.pageviews|intval}</strong><small>{$la_summary.pages_per_session|escape:'html':'UTF-8'} {l s='per session' mod='localanalytics'}</small></div>
    <div class="la-kpi"><span>{l s='Single-page sessions' mod='localanalytics'}</span><strong>{$la_summary.bounce_rate|intval}%</strong></div>
    <div class="la-kpi"><span>{l s='Product views' mod='localanalytics'}</span><strong>{$la_summary.product_views|intval}</strong></div>
    <div class="la-kpi"><span>{l s='Added to cart' mod='localanalytics'}</span><strong>{$la_summary.add_to_cart|intval}</strong></div>
    <div class="la-kpi"><span>{l s='Orders' mod='localanalytics'}</span><strong>{$la_summary.orders|intval}</strong><small>{$la_revenue|escape:'html':'UTF-8'}</small></div>
    <div class="la-kpi"><span>{l s='Conversion' mod='localanalytics'}</span><strong>{$la_summary.conversion|escape:'html':'UTF-8'}%</strong><small>{l s='orders / sessions' mod='localanalytics'}</small></div>
  </div>

  <div class="la-card">
    <h3>{if $la_timeline.monthly}{l s='Sessions per month' mod='localanalytics'}{else}{l s='Sessions per day' mod='localanalytics'}{/if}</h3>
    {assign var=la_tmax value=1}
    {foreach $la_timeline.rows as $r}{if $r.sessions > $la_tmax}{assign var=la_tmax value=$r.sessions}{/if}{/foreach}
    <div class="la-chart">
      {foreach $la_timeline.rows as $r}
        <div class="la-col" title="{$r.key|escape:'html':'UTF-8'}: {$r.sessions|intval} {l s='sessions' mod='localanalytics'}, {$r.pageviews|intval} {l s='page views' mod='localanalytics'}, {$r.orders|intval} {l s='orders' mod='localanalytics'}">
          <span class="la-bar" style="height:{($r.sessions / $la_tmax * 100)|string_format:'%.1f'}%">{if $r.orders}<i class="la-order">{$r.orders|intval}</i>{/if}</span>
          {if $r@index % $la_label_step == 0}<span class="la-col-label">{if $la_timeline.monthly}{$r.key|escape:'html':'UTF-8'}{else}{$r.key|date_format:'%d/%m'}{/if}</span>{/if}
        </div>
      {/foreach}
    </div>
    <p class="la-unit">{l s='Maximum' mod='localanalytics'}: {$la_tmax|intval}. {l s='Green labels: orders that day.' mod='localanalytics'}</p>
  </div>

  <div class="la-card">
    <h3>{l s='Sales funnel' mod='localanalytics'} <small>{l s='sessions that reached each step' mod='localanalytics'}</small></h3>
    <table class="la-bars la-funnel">
      {foreach $la_funnel as $step}
        <tr>
          <td class="la-bars-label">{$step.label|escape:'html':'UTF-8'}</td>
          <td class="la-bars-bar"><span style="width:{$step.pct|string_format:'%.1f'}%"></span></td>
          <td class="la-bars-n">{$step.n|intval} <small>({$step.pct|string_format:'%.1f'}%)</small></td>
        </tr>
      {/foreach}
    </table>
  </div>

  <div class="la-grid">
    <div class="la-card la-wide">
      <h3>{l s='Most viewed products' mod='localanalytics'}</h3>
      {if $la_products}
        <table class="table">
          <thead><tr><th>{l s='Product' mod='localanalytics'}</th><th class="text-right">{l s='Views' mod='localanalytics'}</th><th class="text-right">{l s='Sessions' mod='localanalytics'}</th><th class="text-right">{l s='Added to cart' mod='localanalytics'}</th></tr></thead>
          <tbody>
            {foreach $la_products as $p}
              <tr>
                <td>{if $p.url}<a href="{$p.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$p.label|escape:'html':'UTF-8'}</a>{else}{$p.label|escape:'html':'UTF-8'}{/if}</td>
                <td class="text-right">{$p.views|intval}</td>
                <td class="text-right">{$p.sessions|intval}</td>
                <td class="text-right">{$p.carts|intval}</td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      {else}<p class="la-empty">{l s='No data in this period.' mod='localanalytics'}</p>{/if}
    </div>
    {la_bars rows=$la_categories title={l s='Most viewed categories' mod='localanalytics'} unit={l s='page views' mod='localanalytics'}}
    {la_bars rows=$la_searches title={l s='Searches' mod='localanalytics'} unit={l s='times searched' mod='localanalytics'}}
  </div>

  <h2 class="la-section">{l s='Where visitors come from' mod='localanalytics'}</h2>
  <div class="la-grid">
    {la_bars rows=$la_referrers title={l s='Referring sites' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'}}
    {la_bars rows=$la_landing title={l s='Landing pages' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'}}
    {la_bars rows=$la_sources title={l s='Campaign sources (utm_source)' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'} empty={l s='No tagged links (utm_source) in this period.' mod='localanalytics'}}
    {la_bars rows=$la_campaigns title={l s='Campaigns (utm_campaign)' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'} empty={l s='No tagged links (utm_campaign) in this period.' mod='localanalytics'}}
  </div>

  <h2 class="la-section">{l s='Who visits' mod='localanalytics'}</h2>
  <div class="la-grid">
    {la_bars rows=$la_countries title={l s='Countries' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'} empty={l s='The country comes from Cloudflare (CF-IPCountry); none recorded.' mod='localanalytics'}}
    {la_bars rows=$la_languages title={l s='Browser language' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'}}
    {la_bars rows=$la_shop_langs title={l s='Shop language' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'}}
    {la_bars rows=$la_devices title={l s='Devices' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'}}
    {la_bars rows=$la_browsers title={l s='Browsers' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'}}
    {la_bars rows=$la_os title={l s='Operating systems' mod='localanalytics'} unit={l s='sessions' mod='localanalytics'}}
  </div>

  <h2 class="la-section">{l s='Pages' mod='localanalytics'}</h2>
  <div class="la-grid">
    <div class="la-wide">{la_bars rows=$la_pages title={l s='Most viewed pages' mod='localanalytics'} unit={l s='page views' mod='localanalytics'}}</div>
    {la_bars rows=$la_page_types title={l s='Page types' mod='localanalytics'} unit={l s='page views' mod='localanalytics'}}
    {la_bars rows=$la_realtime.pages title={l s='Pages being viewed now' mod='localanalytics'} unit={l s='visitors, last 5 minutes' mod='localanalytics'} empty={l s='Nobody on the site in the last 5 minutes.' mod='localanalytics'}}
  </div>

  <p class="la-foot">{l s='Counted with a small script in the browser, so most bots are not included. No cookies and no IP addresses are stored.' mod='localanalytics'}</p>
{/if}
</div>
