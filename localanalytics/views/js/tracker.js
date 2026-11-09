/**
 * Local Analytics tracker: one beacon per page view and one per add-to-cart.
 * No cookies, no localStorage, no third parties. @license MIT
 */
(function () {
  var cfg = window.localanalytics;
  if (!cfg || !cfg.url || navigator.webdriver) {
    return;
  }

  function send(data) {
    var body = new URLSearchParams();
    data.ts = cfg.ts;
    data.sig = cfg.sig;
    data.page = cfg.page;
    data.lang = cfg.lang;
    data.path = location.pathname;
    data.bl = (navigator.language || '').slice(0, 8);
    Object.keys(data).forEach(function (k) {
      if (data[k] !== undefined && data[k] !== null && data[k] !== '') {
        body.append(k, data[k]);
      }
    });
    if (!(navigator.sendBeacon && navigator.sendBeacon(cfg.url, body))) {
      try {
        fetch(cfg.url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' });
      } catch (e) { /* ignore */ }
    }
  }

  var q = new URLSearchParams(location.search);
  send({
    type: 'pageview',
    id: cfg.id || '',
    ref: document.referrer,
    search: cfg.page === 'search' ? (q.get('s') || q.get('search_query') || '') : '',
    us: q.get('utm_source'),
    um: q.get('utm_medium'),
    uc: q.get('utm_campaign')
  });

  function onAddToCart(event) {
    if (event && event.reason && event.reason.linkAction === 'add-to-cart') {
      var qty = event.resp && event.resp.quantity;
      send({ type: 'add_to_cart', id: event.reason.idProduct || '', qty: qty || '' });
    }
  }

  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updateCart', onAddToCart);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (window.prestashop && typeof window.prestashop.on === 'function') {
        window.prestashop.on('updateCart', onAddToCart);
      }
    });
  }
})();
