/* BPOS Reports page: KPIs, daily sales chart, payment mix and breakdown tables. All figures come from the server. */
(function (w, d, B) {
  var root = d.getElementById('rp-root');
  var dataEl = d.getElementById('rp-data');
  if (!root || !dataEl) { return; }

  var P = JSON.parse(dataEl.textContent);
  var $ = function (id) { return d.getElementById(id); };
  var esc = B.esc;
  var t = function (k, f) { return B.t(k, f); };
  var lang = d.documentElement.lang || undefined;

  var S = { range: '7', from: '', to: '' };
  var ctl = null;
  var last = null;

  function ymd(date) {
    var m = date.getUTCMonth() + 1;
    var day = date.getUTCDate();
    return date.getUTCFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  function rangeDates() {
    var p = P.today.split('-');
    var end = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    var start = new Date(end.getTime());

    if (S.range === 'custom') { return { from: S.from, to: S.to }; }
    if (S.range === '7') { start.setUTCDate(start.getUTCDate() - 6); }
    if (S.range === '30') { start.setUTCDate(start.getUTCDate() - 29); }
    if (S.range === 'month') { start.setUTCDate(1); }

    return { from: ymd(start), to: ymd(end) };
  }

  function day(s, opts) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    if (!m) { return s || ''; }

    try { return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(lang, opts); } catch (e) { return s; }
  }

  function url(base) {
    var r = rangeDates();
    return base + (base.indexOf('?') > -1 ? '&' : '?') + 'from=' + encodeURIComponent(r.from) + '&to=' + encodeURIComponent(r.to);
  }

  /* ---------- render ---------- */
  function delta(v) {
    if (v == null) { return ''; }

    var cls = v > 0 ? 'up' : (v < 0 ? 'down' : '');
    var arrow = v > 0 ? 'arrow-up-short' : (v < 0 ? 'arrow-down-short' : 'dash');

    return '<span class="rp-dl ' + cls + '"><i class="bi bi-' + arrow + '"></i>' + Math.abs(v).toLocaleString(lang) + '%</span>';
  }

  function kpi(icon, label, value, extra, style) {
    return '<div class="catc static"><span class="ci g-All"><i class="bi bi-' + icon + '"></i></span><span><b' + (style ? ' style="' + style + '"' : '') + '>' + esc(value) + '</b><small>' + esc(label) + (extra || '') + '</small></span></div>';
  }

  function renderKpis(s) {
    var vs = t('rp_vs_prev', 'vs previous period');
    var chip = function (v) { return v == null ? '' : ' ' + delta(v); };

    $('rp-kpis').innerHTML =
      kpi('cash-coin', t('rp_sales', 'Sales'), s.sales.text, chip(s.sales.delta)) +
      kpi('receipt', t('rp_orders', 'Orders'), s.orders.text, chip(s.orders.delta)) +
      kpi('graph-up', t('rp_avg', 'Average order'), s.avg.text, chip(s.avg.delta)) +
      kpi('arrow-counterclockwise', t('rp_refunds', 'Refunds'), s.refunds.text, ' <span class="rp-dl">' + s.refunds.count + ' · ' + s.voids + ' ' + esc(t('rp_voids', 'voided')) + '</span>', 'color:var(--amber)');

    $('rp-kpis').setAttribute('title', vs);
  }

  var chart = null;
  var drawId = 0;

  function disposeChart() {
    drawId++;
    if (chart) { chart.dispose(); chart = null; }
  }

  function cssVar(name, fallback) {
    var v = w.getComputedStyle(d.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  function renderChart(rows) {
    var el = $('rp-chart');
    var total = 0;

    rows.forEach(function (r) { total += r.sales; });
    disposeChart();
    var mine = drawId;

    if (!total) {
      el.className = '';
      el.removeAttribute('role');
      el.innerHTML = '<div class="rp-empty">' + esc(t('rp_no_sales', 'No sales in this period.')) + '</div>';
      return;
    }

    el.innerHTML = '';
    el.className = 'rp-amc';
    el.setAttribute('role', 'img');
    el.setAttribute('aria-label', t('rp_daily', 'Daily sales') + ': ' + rows.map(function (r) { return r.date + ' ' + r.text; }).join(', '));

    var tip = d.createElement('div');
    tip.className = 'rp-tip';
    tip.hidden = true;

    function draw() {
      var W = Math.max(280, el.clientWidth);
      var H = el.clientHeight || 260;
      var m = { l: 46, r: 16, t: 12, b: 28 };
      var iw = W - m.l - m.r;
      var ih = H - m.t - m.b;
      var max = 0;

      rows.forEach(function (r) { max = Math.max(max, r.sales); });

      // Round the top of the axis to 1, 2 or 5 times a power of ten, four steps
      var raw = max / 4;
      var pow = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
      var step = [1, 2, 5, 10].map(function (k) { return k * pow; }).filter(function (v) { return v >= raw; })[0];
      var top = step * 4;

      function X(i) { return m.l + (rows.length > 1 ? iw * i / (rows.length - 1) : iw / 2); }
      function Y(v) { return m.t + ih - ih * v / top; }

      var pts = rows.map(function (r, i) { return [X(i), Y(r.sales)]; });
      var line = 'M' + pts[0][0] + ',' + pts[0][1];

      for (var i = 1; i < pts.length; i++) {
        var mx = (pts[i - 1][0] + pts[i][0]) / 2;
        line += ' C' + mx + ',' + pts[i - 1][1] + ' ' + mx + ',' + pts[i][1] + ' ' + pts[i][0] + ',' + pts[i][1];
      }

      var area = line + ' L' + pts[pts.length - 1][0] + ',' + (m.t + ih) + ' L' + pts[0][0] + ',' + (m.t + ih) + ' Z';
      var g = '';

      for (var k = 0; k <= 4; k++) {
        var v = step * k;
        g += '<line x1="' + m.l + '" x2="' + (W - m.r) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" class="g"/><text x="' + (m.l - 8) + '" y="' + (Y(v) + 4) + '" text-anchor="end">' + (v >= 1000 ? (v / 1000) + 'k' : v) + '</text>';
      }

      var every = Math.max(1, Math.ceil(rows.length / Math.max(1, Math.floor(iw / 64))));

      rows.forEach(function (r, j) {
        g += '<line x1="' + X(j) + '" x2="' + X(j) + '" y1="' + m.t + '" y2="' + (m.t + ih) + '" class="g"/>';
        if (j % every === 0) { g += '<text x="' + X(j) + '" y="' + (H - 8) + '" text-anchor="middle">' + esc(day(r.date, rows.length > 12 ? { day: 'numeric', month: 'short' } : { weekday: 'short', day: 'numeric' })) + '</text>'; }
      });

      el.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" focusable="false"><g class="ax">' + g + '</g>' +
        '<path d="' + area + '" class="ar"/><path d="' + line + '" class="ln"/>' +
        pts.map(function (p) { return '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="3.2" class="dt"/>'; }).join('') + '</svg>';
      el.appendChild(tip);

      var svg = el.querySelector('svg');

      svg.addEventListener('mousemove', function (e) {
        var box = svg.getBoundingClientRect();
        var px = e.clientX - box.left;
        var best = 0;

        pts.forEach(function (p, n) { if (Math.abs(p[0] - px) < Math.abs(pts[best][0] - px)) { best = n; } });

        var r = rows[best];
        tip.innerHTML = '<b>' + esc(r.text) + '</b><span>' + esc(day(r.date, { weekday: 'short', day: 'numeric', month: 'short' })) + ' · ' + esc(String(r.orders)) + '</span>';
        tip.hidden = false;
        tip.style.left = Math.min(W - tip.offsetWidth - 4, Math.max(4, pts[best][0] - tip.offsetWidth / 2)) + 'px';
        tip.style.top = Math.max(0, pts[best][1] - tip.offsetHeight - 12) + 'px';
      });

      svg.addEventListener('mouseleave', function () { tip.hidden = true; });
    }

    var timer = 0;
    function onResize() { clearTimeout(timer); timer = setTimeout(function () { if (el.isConnected && mine === drawId) { draw(); } }, 120); }

    w.addEventListener('resize', onResize);
    chart = { dispose: function () { clearTimeout(timer); w.removeEventListener('resize', onResize); } };
    draw();
  }

  function renderMix(rows) {
    $('rp-pay').innerHTML = rows.length ? '<div class="rp-mix">' + rows.map(function (r) {
      return '<div class="rp-mix-row"><span>' + esc(r.name || r.code) + ' <small style="color:var(--mute)">· ' + r.orders + '</small></span><b>' + esc(r.text) + '</b>' +
        '<div class="rp-track"><i style="width:' + Math.min(100, r.pct) + '%"></i></div></div>';
    }).join('') + '</div>' : '<div class="rp-empty">' + esc(t('rp_no_sales', 'No sales in this period.')) + '</div>';
  }

  function table(el, cols, rows, mapper) {
    el.innerHTML = '<thead><tr>' + cols.map(function (c) { return '<th' + (c.cls ? ' class="' + c.cls + '"' : '') + '>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead>' +
      '<tbody>' + (rows.length ? rows.map(mapper).join('') : '<tr><td colspan="' + cols.length + '"><div class="mg-empty">' + esc(t('rp_no_sales', 'No sales in this period.')) + '</div></td></tr>') + '</tbody>';
  }

  function renderTables(r) {
    table($('rp-cash'), [
      { label: t('rp_cashier', 'Cashier') }, { label: t('rp_orders', 'Orders'), cls: 'num' }, { label: t('rp_sales', 'Sales'), cls: 'num' },
      { label: t('rp_avg', 'Average order'), cls: 'num hm' }, { label: t('rp_refunds', 'Refunds'), cls: 'num hm' }, { label: t('rp_share', 'Share'), cls: 'num hs' }
    ], r.cashier, function (x) {
      return '<tr><td>' + (x.name ? esc(x.name) : '<span style="color:var(--mute)">–</span>') + '</td><td class="num">' + x.orders + '</td><td class="num">' + esc(x.text) + '</td><td class="num hm">' + esc(x.avg_text) + '</td><td class="num hm">' + x.refunds + '</td><td class="num hs">' + x.pct + '%</td></tr>';
    });

    table($('rp-term'), [
      { label: t('rp_terminal', 'Terminal') }, { label: t('rp_orders', 'Orders'), cls: 'num' }, { label: t('rp_sales', 'Sales'), cls: 'num' }, { label: t('rp_share', 'Share'), cls: 'num hs' }
    ], r.terminal, function (x) {
      return '<tr><td>' + (x.name ? esc(x.name) : '<span style="color:var(--mute)">–</span>') + '</td><td class="num">' + x.orders + '</td><td class="num">' + esc(x.text) + '</td><td class="num hs">' + x.pct + '%</td></tr>';
    });
  }

  function render(r) {
    last = r;
    var a = day(r.from, { day: 'numeric', month: 'short', year: 'numeric' });
    var b = day(r.to, { day: 'numeric', month: 'short', year: 'numeric' });

    $('rp-rt').textContent = r.from === r.to ? a : a + ' – ' + b;
    renderKpis(r.summary);
    renderChart(r.daily);
    renderMix(r.payment);
    renderTables(r);
  }

  function skeleton() {
    $('rp-kpis').innerHTML = [1, 2, 3, 4].map(function () { return '<div class="catc static" aria-hidden="true"><span class="ci g-All"></span><span><span class="sk" style="width:90px;height:16px;display:block"></span><span class="sk" style="width:60px;display:block;margin-top:6px"></span></span></div>'; }).join('');
    disposeChart();
    $('rp-chart').className = '';
    $('rp-chart').innerHTML = '<span class="sk" style="width:100%;height:200px;display:block"></span>';
  }

  function load() {
    if (ctl) { ctl.abort(); }
    ctl = new AbortController();

    if (!last) { skeleton(); }
    $('rp-body').classList.add('busy');

    B.api(url(P.urls.data), { signal: ctl.signal }).then(function (r) {
      $('rp-body').classList.remove('busy');
      render(r);
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      $('rp-body').classList.remove('busy');
      B.ui.toast(e.message, 'err');
    });
  }

  function setRange(r) {
    S.range = r;

    Array.prototype.forEach.call($('rp-range').querySelectorAll('button'), function (b) { b.classList.toggle('on', b.getAttribute('data-range') === r); });
    $('rp-dates').hidden = r !== 'custom';

    if (r === 'custom') {
      if (!S.from) { S.from = $('rp-from').value = P.today; }
      if (!S.to) { S.to = $('rp-to').value = P.today; }
    }

    load();
  }

  /* ---------- export ---------- */
  function exportXlsx() {
    var btn = $('rp-export');
    btn.disabled = true;

    B.api(url(P.urls.export)).then(function (r) {
      var s = r.summary;

      B.xlsx('report-' + r.from + '_' + r.to + '.xlsx', [
        { name: 'Summary', rows: [['from', 'to', 'currency'], [r.from, r.to, r.currency], [], ['sales', 'orders', 'average_order', 'refunds', 'refund_count', 'void_count', 'items'], [s.sales.value, s.orders.value, s.avg.value, s.refunds.value, s.refunds.count, s.voids, s.items]] },
        { name: 'Daily', rows: [['date', 'orders', 'sales']].concat(r.daily.map(function (x) { return [x.date, x.orders, x.sales]; })) },
        { name: 'Payment', rows: [['payment', 'orders', 'sales', 'share_pct']].concat(r.payment.map(function (x) { return [x.name || x.code, x.orders, x.sales, x.pct]; })) },
        { name: 'Cashier', rows: [['cashier', 'orders', 'sales', 'average_order', 'refunds', 'share_pct']].concat(r.cashier.map(function (x) { return [x.name, x.orders, x.sales, x.avg, x.refunds, x.pct]; })) },
        { name: 'Terminal', rows: [['terminal', 'orders', 'sales', 'average_order', 'share_pct']].concat(r.terminal.map(function (x) { return [x.name, x.orders, x.sales, x.avg, x.pct]; })) }
      ]);
    }).catch(function (e) {
      B.ui.toast(e.message, 'err');
    }).then(function () { btn.disabled = false; });
  }

  /* ---------- events ---------- */
  $('rp-range').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-range]');
    if (b) { setRange(b.getAttribute('data-range')); }
  });
  $('rp-from').addEventListener('change', function () { S.from = this.value; if (S.to && S.from > S.to) { S.to = $('rp-to').value = S.from; } load(); });
  $('rp-to').addEventListener('change', function () { S.to = this.value; if (S.from && S.to < S.from) { S.from = $('rp-from').value = S.to; } load(); });

  if ($('rp-export')) { $('rp-export').addEventListener('click', exportXlsx); }

  B.onLeave(function () { if (ctl) { ctl.abort(); } disposeChart(); });

  setRange('7');
})(window, document, window.BPOS);
