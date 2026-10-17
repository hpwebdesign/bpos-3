/* BPOS Orders page: filters, list, detail drawer, reprint, refund and void. All data comes from the server. */
(function (w, d, B) {
  var root = d.getElementById('od-root');
  var dataEl = d.getElementById('od-data');
  if (!root || !dataEl) { return; }

  var P = JSON.parse(dataEl.textContent);
  var $ = function (id) { return d.getElementById(id); };
  var esc = B.esc;
  var t = function (k, f) { return B.t(k, f); };
  var ic = function (n) { return '<i class="bi bi-' + n + '"></i>'; };
  var lang = d.documentElement.lang || undefined;

  var S = { range: '7', from: '', to: '', q: '', pay: '', cashier: '', status: 'all', sort: 'date', dir: 'desc', page: 1, pages: 1, total: 0, counts: null, rows: [], loaded: false, open: 0 };
  var ctl = null;
  var timer = null;
  var current = null;

  /* ---------- dates ---------- */
  function ymd(date) {
    var m = date.getUTCMonth() + 1;
    var day = date.getUTCDate();
    return date.getUTCFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  // Ranges are counted from the server's today, so they match the order dates
  function rangeDates() {
    var p = P.today.split('-');
    var end = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    var start = new Date(end.getTime());

    if (S.range === 'custom') { return { from: S.from, to: S.to }; }
    if (S.range === '7') { start.setUTCDate(start.getUTCDate() - 6); }
    if (S.range === '30') { start.setUTCDate(start.getUTCDate() - 29); }

    return { from: ymd(start), to: ymd(end) };
  }

  function fmtDate(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(s || '');
    if (!m) { return s || ''; }

    try {
      return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]).toLocaleString(lang, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    } catch (e) { return s; }
  }

  /* ---------- query ---------- */
  function params(extra) {
    var r = rangeDates();
    var q = [];
    var all = { from: r.from, to: r.to, q: S.q, pay: S.pay, cashier: S.cashier, status: S.status };

    Object.keys(extra || {}).forEach(function (k) { all[k] = extra[k]; });

    Object.keys(all).forEach(function (k) {
      if (all[k] !== '' && all[k] != null) { q.push(k + '=' + encodeURIComponent(all[k])); }
    });

    return q.join('&');
  }

  function url(base, qs) { return base + (base.indexOf('?') > -1 ? '&' : '?') + qs; }

  /* ---------- list ---------- */
  function skeleton() {
    var out = '';

    for (var i = 0; i < 8; i++) {
      out += '<tr aria-hidden="true">' + [60, 90, 80, 110, 70, 70, 70].map(function (wd, k) {
        return '<td' + (k === 2 || k === 4 ? ' class="hm"' : (k === 3 ? ' class="hs"' : '')) + '><span class="sk" style="width:' + wd + 'px"></span></td>';
      }).join('') + '</tr>';
    }

    $('od-body').innerHTML = out;
  }

  function load(keepPage) {
    if (ctl) { ctl.abort(); }
    ctl = new AbortController();

    if (!keepPage) { S.page = 1; }

    $('od-card').classList.add('busy');
    if (!S.loaded) { skeleton(); }

    B.api(url(P.urls.data, params({ page: S.page, sort: S.sort, dir: S.dir })), { signal: ctl.signal }).then(function (r) {
      S.loaded = true;
      S.rows = r.rows;
      S.page = r.page;
      S.pages = r.pages;
      S.total = r.total;
      S.counts = r.counts;
      S.kpis = r.kpis;
      $('od-card').classList.remove('busy');
      render();
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      $('od-card').classList.remove('busy');
      B.ui.toast(e.message, 'err');
    });
  }

  function who(name) {
    if (!name) { return '<span style="color:var(--mute)">–</span>'; }
    return esc(name);
  }

  function render() {
    renderTabs();
    renderKpis();

    var rows = S.rows;

    $('od-body').innerHTML = rows.length ? rows.map(function (r) {
      return '<tr data-open="' + r.id + '" tabindex="0">' +
        '<td class="id">#' + r.id + '</td>' +
        '<td>' + esc(fmtDate(r.date)) + '</td>' +
        '<td class="hm">' + who(r.cashier) + (r.terminal ? '<small>' + esc(r.terminal) + '</small>' : '') + '</td>' +
        '<td class="hs">' + who(r.customer) + '<small>' + r.items + ' ' + esc(t(r.items === 1 ? 'sl_item' : 'sl_items', r.items === 1 ? 'item' : 'items')) + '</small></td>' +
        '<td class="hm">' + who(r.pay) + '</td>' +
        '<td class="num">' + esc(r.total) + '</td>' +
        '<td><span class="st ' + esc(r.status.key) + '">' + esc(r.status.label || t('od_status_other', 'Other')) + '</span></td></tr>';
    }).join('') : '<tr><td colspan="7"><div class="mg-empty">' + esc(t('od_empty', 'No orders match these filters.')) + '</div></td></tr>';

    Array.prototype.forEach.call(root.querySelectorAll('th[data-sort]'), function (th) {
      var on = th.getAttribute('data-sort') === S.sort;
      th.setAttribute('aria-sort', on ? (S.dir === 'asc' ? 'ascending' : 'descending') : 'none');
      th.innerHTML = th.textContent.replace(/\s+$/, '') + (on ? ' ' + ic(S.dir === 'asc' ? 'caret-up-fill' : 'caret-down-fill') : '');
    });

    var from = S.total ? (S.page - 1) * P.size + 1 : 0;
    var to = Math.min(S.total, S.page * P.size);

    $('od-foot').innerHTML = '<span>' + esc(from + '–' + to + ' / ' + S.total) + '</span>' +
      '<span class="mg-pg"><button type="button" data-pg="-1" aria-label="' + esc(t('od_prev', 'Previous page')) + '"' + (S.page <= 1 ? ' disabled' : '') + '>' + ic('chevron-left') + '</button>' +
      '<span>' + S.page + ' / ' + S.pages + '</span>' +
      '<button type="button" data-pg="1" aria-label="' + esc(t('od_next', 'Next page')) + '"' + (S.page >= S.pages ? ' disabled' : '') + '>' + ic('chevron-right') + '</button></span>';
  }

  function tile(icon, value, label, style) {
    return '<div class="catc static"><span class="ci g-All"><i class="bi bi-' + icon + '"></i></span><span><b' + (style ? ' style="' + style + '"' : '') + '>' + esc(value) + '</b><small>' + esc(label) + '</small></span></div>';
  }

  function renderKpis() {
    var k = S.kpis;

    $('od-kpis').innerHTML = k ? tile('cash-coin', k.sales, t('od_kpi_sales', 'Sales total')) +
      tile('receipt', String(k.orders), t('od_kpi_orders', 'Orders')) +
      tile('graph-up', k.avg, t('od_kpi_avg', 'Average order')) +
      tile('arrow-counterclockwise', k.refunded, t('od_kpi_refunded', 'Refunded'), 'color:var(--amber)') : '';
  }

  function renderTabs() {
    var c = S.counts || { all: 0, done: 0, refund: 0, void: 0, other: 0 };
    var tabs = [['all', 'od_tab_all', 'All'], ['done', 'od_tab_done', 'Completed'], ['refund', 'od_tab_refund', 'Refunded'], ['void', 'od_tab_void', 'Voided']];

    if (c.other) { tabs.push(['other', 'od_tab_other', 'Other']); }

    $('od-tabs').innerHTML = tabs.map(function (x) {
      return '<button type="button" role="tab" data-status="' + x[0] + '" aria-selected="' + (S.status === x[0]) + '" class="' + (S.status === x[0] ? 'on' : '') + '">' + esc(t(x[1], x[2])) + ' <b>' + (c[x[0]] || 0) + '</b></button>';
    }).join('');
  }

  /* ---------- filters ---------- */
  function fillSelects() {
    $('od-pay').innerHTML = '<option value="">' + esc(t('od_all_pay', 'All payments')) + '</option>' + P.pay.map(function (p) { return '<option value="' + esc(p.code) + '">' + esc(p.name) + '</option>'; }).join('');
    $('od-cashier').innerHTML = '<option value="">' + esc(t('od_all_cashiers', 'All cashiers')) + '</option>' + P.cashiers.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');
  }

  function setRange(r) {
    S.range = r;

    Array.prototype.forEach.call($('od-range').querySelectorAll('button'), function (b) { b.classList.toggle('on', b.getAttribute('data-range') === r); });
    $('od-dates').hidden = r !== 'custom';

    if (r === 'custom') {
      if (!S.from) { S.from = $('od-from').value = P.today; }
      if (!S.to) { S.to = $('od-to').value = P.today; }
    }

    load(false);
  }

  /* ---------- drawer ---------- */
  function openDrawer(id) {
    S.open = id;
    $('od-dt').textContent = '#' + id;
    $('od-db').innerHTML = '<span class="sk" style="width:60%"></span><span class="sk" style="width:90%"></span><span class="sk" style="width:75%"></span>';
    $('od-df').innerHTML = '';
    $('od-scrim').hidden = false;
    $('od-drawer').hidden = false;
    $('od-dx').focus();

    B.api(url(P.urls.view, 'order_id=' + id)).then(function (r) {
      if (S.open !== id) { return; }
      current = r.order;
      renderDrawer();
    }).catch(function (e) {
      B.ui.toast(e.message, 'err');
      closeDrawer();
    });
  }

  function closeDrawer() {
    S.open = 0;
    current = null;
    $('od-scrim').hidden = true;
    $('od-drawer').hidden = true;
  }

  function renderDrawer() {
    var o = current;

    $('od-dt').innerHTML = '#' + o.id + ' <span class="st ' + esc(o.status.key) + '" style="vertical-align:middle;margin-left:6px">' + esc(o.status.label) + '</span>';

    var info = '<dl class="mg-kv"><dt>' + esc(t('od_when', 'When')) + '</dt><dd>' + esc(fmtDate(o.date)) + '</dd>' +
      '<dt>' + esc(t('od_cashier', 'Cashier')) + '</dt><dd>' + esc(o.cashier || '–') + (o.terminal ? ' · ' + esc(o.terminal) : '') + '</dd>' +
      '<dt>' + esc(t('od_customer', 'Customer')) + '</dt><dd>' + esc(o.customer.name || '–') + (o.customer.phone ? '<br>' + esc(o.customer.phone) : '') + (o.customer.email ? '<br>' + esc(o.customer.email) : '') + '</dd>' +
      '<dt>' + esc(t('od_payment', 'Payment')) + '</dt><dd>' + esc(o.pay || '–') + '</dd>' +
      (o.cash ? '<dt>' + esc(t('od_received', 'Received')) + '</dt><dd>' + esc(o.tendered) + ' · ' + esc(t('od_change', 'Change')) + ' ' + esc(o.change) + '</dd>' : '') +
      (o.coupon ? '<dt>' + esc(t('od_coupon', 'Coupon')) + '</dt><dd>' + esc(o.coupon) + '</dd>' : '') + '</dl>';

    var lines = '<div><h3 class="mg-sec">' + esc(t('od_items', 'Items')) + '</h3>' + o.lines.map(function (l) {
      return '<div class="mg-li"><b>' + esc(l.name) + '</b><span class="num">' + esc(l.total) + '</span><small>' + (l.options ? esc(l.options) + ' · ' : '') + l.qty + ' × ' + esc(l.price) + '</small></div>';
    }).join('') + '</div>';

    var tot = '<div class="mg-tot">' + o.totals.map(function (x) {
      return '<span' + (x.big ? ' class="big"' : '') + '>' + esc(x.title) + '</span><span' + (x.big ? ' class="big"' : '') + '>' + esc(x.value) + '</span>';
    }).join('') + '</div>';

    var hist = o.history.length ? '<div><h3 class="mg-sec">' + esc(t('od_history', 'History')) + '</h3><ul class="mg-hist">' + o.history.map(function (h) {
      return '<li><b>' + esc(h.status) + '</b> <small>' + esc(fmtDate(h.date)) + '</small>' + (h.comment ? '<small>' + esc(h.comment) + '</small>' : '') + '</li>';
    }).join('') + '</ul></div>' : '';

    $('od-db').innerHTML = info + lines + tot + hist;

    $('od-df').innerHTML = '<button class="btn" type="button" data-act="print">' + ic('printer') + '<span>' + esc(t('od_reprint', 'Reprint receipt')) + '</span></button>' +
      (o.can_void ? '<button class="btn dng" type="button" data-act="void">' + ic('x-octagon') + '<span>' + esc(t('od_void', 'Void order')) + '</span></button>' : '') +
      (o.can_refund ? '<button class="btn dng" type="button" data-act="refund">' + ic('arrow-counterclockwise') + '<span>' + esc(t('od_refund', 'Refund order')) + '</span></button>' : '');
  }

  function printReceipt(id) {
    var link = url(P.urls.receipt, 'order_id=' + id);

    if (!B.print) { printDialog(link); return; }

    B.print.receipt(link).then(function (done) { if (!done) { printDialog(link); } });
  }

  function printDialog(link) {
    var old = d.querySelector('iframe.od-print');
    if (old) { old.parentNode.removeChild(old); }

    var f = d.createElement('iframe');
    f.className = 'od-print';
    f.setAttribute('aria-hidden', 'true');
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
    f.onload = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { B.ui.toast(t('sl_print_fail', 'Could not open the print dialog.'), 'err'); }
    };
    f.src = link;
    d.body.appendChild(f);
  }

  // action: 'void' or 'refund'
  function close(action) {
    var o = current;
    if (!o) { return; }

    B.ui.modal(function (box, done) {
      box.innerHTML = '<h2>' + esc(t(action === 'void' ? 'od_void_title' : 'od_refund_title', action === 'void' ? 'Void this order?' : 'Refund this order?')) + '</h2>' +
        '<p>' + esc(t(action === 'void' ? 'od_void_note' : 'od_refund_note', 'The full order is cancelled and stock is returned.')) + '</p>' +
        '<label class="mg-f">' + esc(t('od_reason', 'Reason (optional)')) + '<textarea id="od-reason" maxlength="200"></textarea></label>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn" type="button" data-no>' + esc(B.t('cancel', 'Cancel')) + '</button><button class="btn pri" type="button" data-yes>' + esc(t(action === 'void' ? 'od_void' : 'od_refund', action === 'void' ? 'Void order' : 'Refund order')) + '</button></div>';

      box.querySelector('[data-no]').addEventListener('click', function () { done(null); });
      box.querySelector('[data-yes]').addEventListener('click', function () { done(box.querySelector('#od-reason').value); });
    }, true).then(function (reason) {
      if (reason === null) { return; }

      B.ui.busy(true);

      B.api(P.urls[action], { data: { order_id: o.id, reason: reason } }).then(function (r) {
        B.ui.busy(false);
        B.ui.toast(t(action === 'void' ? 'od_voided' : 'od_refunded', action === 'void' ? 'Order voided' : 'Order refunded'));

        if (S.open === o.id) { current = r.order; renderDrawer(); }
        load(true);
      }).catch(function (e) {
        B.ui.busy(false);
        B.ui.toast(e.message, 'err');
      });
    });
  }

  /* ---------- export ---------- */
  function exportXlsx() {
    var btn = $('od-export');
    btn.disabled = true;

    B.api(url(P.urls.export, params({}))).then(function (r) {
      var rows = [['order_id', 'date', 'cashier', 'terminal', 'customer', 'payment', 'items', 'currency', 'total', 'status']].concat(r.rows.map(function (x) {
        return [x.id, x.date, x.cashier, x.terminal, x.customer, x.payment, x.items, x.currency, x.total, x.status];
      }));
      var rg = rangeDates();

      B.xlsx('orders-' + (rg.from || 'all') + '_' + (rg.to || 'all') + '.xlsx', [{ name: 'Orders', rows: rows }]);

      if (r.truncated) { B.ui.toast(t('od_export_cut', 'Export is limited to the newest 5000 orders.')); }
    }).catch(function (e) {
      B.ui.toast(e.message, 'err');
    }).then(function () { btn.disabled = false; });
  }

  /* ---------- events ---------- */
  function onClick(e) {
    var x;

    if ((x = e.target.closest('[data-range]'))) { setRange(x.getAttribute('data-range')); return; }
    if ((x = e.target.closest('[data-status]'))) { S.status = x.getAttribute('data-status'); load(false); return; }
    if ((x = e.target.closest('[data-pg]'))) { S.page = Math.max(1, Math.min(S.pages, S.page + Number(x.getAttribute('data-pg')))); load(true); return; }

    if ((x = e.target.closest('th[data-sort]'))) {
      var key = x.getAttribute('data-sort');
      S.dir = S.sort === key && S.dir === 'desc' ? 'asc' : 'desc';
      S.sort = key;
      load(false);
      return;
    }

    if ((x = e.target.closest('tr[data-open]'))) { openDrawer(Number(x.getAttribute('data-open'))); return; }

    if (e.target.closest('#od-dx') || e.target === $('od-scrim')) { closeDrawer(); return; }

    if ((x = e.target.closest('[data-act]')) && current) {
      var act = x.getAttribute('data-act');
      if (act === 'print') { printReceipt(current.id); } else { close(act); }
    }
  }

  function onKey(e) {
    if (e.key === 'Escape' && S.open && !$('bp-ov').children.length) { closeDrawer(); }

    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('tr[data-open]')) {
      e.preventDefault();
      openDrawer(Number(e.target.getAttribute('data-open')));
    }
  }

  root.addEventListener('click', onClick);
  d.addEventListener('keydown', onKey);

  $('od-q').addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { S.q = $('od-q').value.trim(); load(false); }, 250);
  });
  $('od-pay').addEventListener('change', function () { S.pay = this.value; load(false); });
  $('od-cashier').addEventListener('change', function () { S.cashier = this.value; load(false); });
  $('od-from').addEventListener('change', function () { S.from = this.value; if (S.to && S.from > S.to) { S.to = $('od-to').value = S.from; } load(false); });
  $('od-to').addEventListener('change', function () { S.to = this.value; if (S.from && S.to < S.from) { S.from = $('od-from').value = S.to; } load(false); });

  if ($('od-export')) { $('od-export').addEventListener('click', exportXlsx); }

  B.onLeave(function () {
    if (ctl) { ctl.abort(); }
    clearTimeout(timer);
    d.removeEventListener('keydown', onKey);

    var f = d.querySelector('iframe.od-print');
    if (f && f.parentNode) { f.parentNode.removeChild(f); }
  });

  fillSelects();
  renderTabs();
  setRange('7');
})(window, document, window.BPOS);
