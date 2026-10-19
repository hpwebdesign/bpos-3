/* BPOS Reports page: KPIs, daily sales chart, payment mix and breakdown tables. All figures come from the server. */
(function (w, d, B) {
  var root = d.getElementById('rp-root');
  var dataEl = d.getElementById('rp-data');
  if (!root || !dataEl) { return; }

  var P = JSON.parse(dataEl.textContent);
  var $ = function (id) { return d.getElementById(id); };
  var esc = B.esc;
  var t = function (k, f) { return B.t(k, f); };
  var ic = function (n) { return '<i class="bi bi-' + n + '"></i>'; };
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

  // Period shown on the tiles, follows the selected range
  function period(r) {
    var rg = r.range || S.range;
    var names = { today: t('rp_per_today', 'Today'), '7': t('rp_per_7', 'Last 7 days'), '30': t('rp_per_30', 'Last 30 days'), month: t('rp_per_month', 'This month') };

    if (names[rg]) { return names[rg]; }

    var o = { day: 'numeric', month: 'short' };

    return r.from === r.to ? day(r.from, o) : day(r.from, o) + ' – ' + day(r.to, o);
  }

  function renderKpis(s, r) {
    var vs = t('rp_vs_prev', 'vs previous period');
    var per = ' · ' + period(r);
    var chip = function (v) { return v == null ? '' : ' ' + delta(v); };

    $('rp-kpis').innerHTML =
      kpi('cash-coin', t('rp_net_sales', 'Net sales') + per, s.sales.text, chip(s.sales.delta)) +
      kpi('receipt', t('rp_transactions', 'Transactions') + per, s.orders.text, chip(s.orders.delta)) +
      kpi('graph-up', t('rp_avg', 'Average order') + per, s.avg.text, chip(s.avg.delta)) +
      kpi('bag-check', t('rp_items', 'Items sold') + per, s.items.text) +
      kpi('percent', t('rp_discounts', 'Discounts') + per, s.discounts.text) +
      kpi('arrow-counterclockwise', t('rp_refunded_voided', 'Refunded + Voided') + ' · ' + s.rv.count, s.rv.text, '', s.rv.count ? 'color:var(--amber)' : '');

    $('rp-kpis').setAttribute('title', vs);
  }

  var chart = null;
  var donuts = [];
  var drawId = 0;
  var amLoad = null;
  var amThemed = false;

  function disposeChart() {
    drawId++;
    if (chart) { chart.dispose(); chart = null; }
    donuts.forEach(function (c) { c.dispose(); });
    donuts = [];
  }

  // The amCharts bundle is local and loaded once, on first use
  function loadAm() {
    if (!(w.am4core && w.am4charts)) {
      if (!amLoad) {
        amLoad = new Promise(function (ok, fail) {
          var s = d.createElement('script');
          s.src = P.urls.am;
          s.onload = ok;
          s.onerror = function () { amLoad = null; fail(new Error('amCharts')); };
          d.head.appendChild(s);
        });
      }
    }

    return (amLoad || Promise.resolve()).then(function () {
      if (!amThemed) { w.am4core.useTheme(w.am4themes_animated); amThemed = true; }
    });
  }

  function amStyle(axis, ink, line) {
    axis.renderer.grid.template.stroke = w.am4core.color(line);
    axis.renderer.grid.template.strokeOpacity = 1;
    axis.renderer.labels.template.fill = w.am4core.color(ink);
    axis.renderer.line.disabled = true;
  }

  function amTip(ser) {
    var am = w.am4core;

    ser.tooltip.getFillFromObject = false;
    ser.tooltip.background.fill = am.color(cssVar('--navy', '#101F45'));
    ser.tooltip.background.strokeOpacity = 0;
    ser.tooltip.label.fill = am.color('#FFFFFF');
  }

  function drawAm(el, rows) {
    var am = w.am4core;
    var ac = w.am4charts;
    var ink = cssVar('--mute', '#66728F');
    var line = cssVar('--line', '#E4E9F5');
    var accent = cssVar('--accent', '#3558D6');
    var c = am.create(el, ac.XYChart);
    var fmt = rows.length > 12 ? 'd MMM' : 'EEE d';

    c.fontSize = 11;
    c.paddingRight = 12;
    c.data = rows.map(function (r) {
      var p = r.date.split('-');

      return { date: new Date(+p[0], +p[1] - 1, +p[2]), sales: r.sales, text: r.text, orders: r.orders };
    });

    var x = c.xAxes.push(new ac.DateAxis());
    x.baseInterval = { timeUnit: 'day', count: 1 };
    x.renderer.minGridDistance = 56;
    x.renderer.grid.template.disabled = true;
    x.renderer.labels.template.fill = am.color(ink);
    x.renderer.line.stroke = am.color(line);
    x.dateFormats.setKey('day', fmt);
    x.periodChangeDateFormats.setKey('day', fmt);

    var y = c.yAxes.push(new ac.ValueAxis());
    y.min = 0;
    y.numberFormatter.numberFormat = '#.#a';
    amStyle(y, ink, line);

    var ser = c.series.push(new ac.LineSeries());
    ser.dataFields.valueY = 'sales';
    ser.dataFields.dateX = 'date';
    ser.strokeWidth = 2;
    ser.stroke = am.color(accent);
    ser.fill = am.color(accent);
    ser.fillOpacity = 0.12;
    ser.tensionX = 0.8;
    ser.tooltipText = '[bold]{text}[/]\n{orders}';
    amTip(ser);

    var dot = ser.bullets.push(new ac.CircleBullet());
    dot.circle.radius = 3.5;
    dot.circle.fill = am.color(accent);
    dot.circle.stroke = am.color(cssVar('--surface', '#FFFFFF'));
    dot.circle.strokeWidth = 2;
    dot.defaultState.properties.visible = rows.length <= 31;
    dot.states.create('hover').properties.scale = 1.4;

    c.cursor = new ac.XYCursor();
    c.cursor.lineY.disabled = true;
    c.cursor.lineX.stroke = am.color(line);

    chart = c;
  }

  var PALETTE = ['#3558D6', '#12B76A', '#F59E0B', '#E5484D', '#8E4EC6', '#0EA5B7', '#94A3B8'];

  function colorOf(i, other) { return other ? '#94A3B8' : PALETTE[i % 6]; }

  function drawDonutAm(el, rows) {
    var am = w.am4core;
    var ac = w.am4charts;
    var c = am.create(el, ac.PieChart);

    c.fontSize = 11;
    c.innerRadius = am.percent(55);
    c.data = rows.map(function (r) { return { category: r.label, value: r.value, text: r.text, pct: r.pct, color: am.color(r.color) }; });

    var ser = c.series.push(new ac.PieSeries());
    ser.dataFields.value = 'value';
    ser.dataFields.category = 'category';
    ser.slices.template.propertyFields.fill = 'color';
    ser.slices.template.stroke = am.color(cssVar('--surface', '#FFFFFF'));
    ser.slices.template.strokeWidth = 2;
    ser.slices.template.strokeOpacity = 1;
    ser.slices.template.tooltipText = '{category}: {pct}% · {text}';
    ser.labels.template.text = '{category}';
    ser.labels.template.fill = am.color(cssVar('--ink', '#14203D'));
    ser.labels.template.maxWidth = 120;
    ser.labels.template.wrap = true;
    ser.ticks.template.stroke = am.color(cssVar('--ink', '#14203D'));
    ser.ticks.template.strokeOpacity = 1;
    ser.ticks.template.strokeWidth = 1.5;
    amTip(ser);

    donuts.push(c);
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

    function drawSvg() {
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

    loadAm().then(function () {
      if (mine === drawId && el.isConnected) { drawAm(el, rows); }
    }, function () {
      if (mine === drawId && el.isConnected) { drawSvg(); }
    });
  }

  // Donut with a legend list below: rows are { label, sub, value, text, pct, color }
  function renderDonut(chartEl, listEl, rows) {
    var mine = drawId;

    if (!rows.length) {
      chartEl.className = '';
      chartEl.style.height = '';
      chartEl.innerHTML = '<div class="rp-empty">' + esc(t('rp_no_sales', 'No sales in this period.')) + '</div>';
      listEl.innerHTML = '';
      return;
    }

    chartEl.innerHTML = '';
    chartEl.className = 'rp-donut';
    chartEl.setAttribute('role', 'img');
    chartEl.setAttribute('aria-label', rows.map(function (r) { return r.label + ' ' + r.text; }).join(', '));

    listEl.innerHTML = rows.map(function (r) {
      return '<div class="rp-li"><span class="rp-dot" style="background:' + r.color + '"></span><span class="rp-ln"><b>' + esc(r.label) + '</b>' + (r.sub ? '<small>' + esc(r.sub) + '</small>' : '') + '</span><span class="rp-lv">' + esc(r.text) + '</span><span class="rp-pill">' + r.pct + '%</span></div>';
    }).join('');

    loadAm().then(function () {
      if (mine === drawId && chartEl.isConnected) { drawDonutAm(chartEl, rows); }
    }, function () {
      if (mine === drawId) { chartEl.className = ''; chartEl.innerHTML = ''; }
    });
  }

  function renderPayments(r) {
    var rows = r.payment.map(function (x, i) {
      return { label: x.name || x.code, sub: x.orders + ' ' + t('rp_orders_l', 'orders'), value: x.sales, text: x.text, pct: x.pct, color: colorOf(i, false) };
    });

    renderDonut($('rp-pay'), $('rp-pay-list'), rows);

    var dw = r.drawer;

    $('rp-drawer').innerHTML = '<div class="kv"><span>' + esc(t('rp_cash_sales', 'Cash sales')) + '</span><span class="num">' + esc(dw.sales.text) + '</span></div>' +
      '<div class="kv"><span>' + esc(t('rp_cash_refunds', 'Cash refunds')) + '</span><span class="num">' + esc(dw.refunds.text) + '</span></div>' +
      '<div class="kv kvt"><span>' + esc(t('rp_cash_expected', 'Expected cash in drawer')) + '</span><span class="num">' + esc(dw.expected.text) + '</span></div>' +
      '<small>' + esc(t('rp_cash_note', 'Opening float is not included.')) + '</small>';
  }

  function renderCategory(r) {
    var rows = r.category.map(function (x, i) {
      return { label: x.other ? t('rp_other', 'Other') : (x.name || t('rp_uncat', 'Uncategorized')), sub: x.qty + ' ' + t('rp_units', 'sold'), value: x.sales, text: x.text, pct: x.pct, color: colorOf(i, x.other) };
    });

    renderDonut($('rp-cat'), $('rp-cat-list'), rows);
  }

  function table(el, cols, rows, mapper) {
    el.innerHTML = '<thead><tr>' + cols.map(function (c) { return '<th' + (c.cls ? ' class="' + c.cls + '"' : '') + '>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead>' +
      '<tbody>' + (rows.length ? rows.map(function (x, i) { return mapper(x, i); }).join('') : '<tr><td colspan="' + cols.length + '"><div class="mg-empty">' + esc(t('rp_no_sales', 'No sales in this period.')) + '</div></td></tr>') + '</tbody>';
  }

  function renderSummary(r) {
    var b = r.breakdown;
    var kv = function (label, v, cls) { return '<div class="kv' + (cls ? ' ' + cls : '') + '"><span>' + esc(label) + '</span><span class="num">' + esc(v) + '</span></div>'; };

    $('rp-sum').innerHTML = kv(t('rp_subtotal', 'Items subtotal'), b.subtotal.text) +
      kv(t('rp_discounts', 'Discounts'), b.discounts.text) +
      kv(t('rp_coupons', 'Coupons'), b.coupons.text) +
      kv(t('rp_shipping', 'Shipping fees'), b.shipping.text) +
      (b.charge.value ? kv(t('rp_charge', 'Extra charges'), b.charge.text) : '') +
      kv(t('rp_tax', 'Tax'), b.tax.text) +
      kv(t('rp_net_sales', 'Net sales'), b.net.text, 'kvt') +
      kv(t('rp_refunded', 'Refunded') + ' · ' + b.refunded.count, b.refunded.text, 'mut') +
      kv(t('rp_voided', 'Voided') + ' · ' + b.voided.count, b.voided.text, 'mut');
  }

  function renderTrendInfo(r) {
    var bz = r.busiest;

    $('rp-busy').textContent = bz ? t('rp_busiest', 'Busiest') + ': ' + day(bz.date, { weekday: 'short', month: 'short', day: 'numeric' }) + ' · ' + bz.text : '';
    $('rp-trend-sum').innerHTML = '<b>' + esc(r.summary.sales.text) + '</b> ' + esc(t('rp_net_sales_l', 'net sales')) + ' · ' + r.summary.orders.text + ' ' + esc(t('rp_orders_l', 'orders')) + ' ' + esc(t('rp_in_period', 'in this period'));
  }

  function renderProducts(r) {
    table($('rp-prod'), [
      { label: '#', cls: 'hs' }, { label: t('rp_product', 'Product') }, { label: t('rp_category', 'Category'), cls: 'hm' },
      { label: t('rp_qty', 'Qty'), cls: 'num' }, { label: t('rp_revenue', 'Revenue'), cls: 'num' }
    ], r.products, function (x, i) {
      return '<tr><td class="hs">' + (i + 1) + '</td><td>' + esc(x.name) + '</td><td class="hm">' + (x.category ? esc(x.category) : '<span style="color:var(--mute)">–</span>') + '</td><td class="num">' + x.qty + '</td><td class="num">' + esc(x.text) + '</td></tr>';
    });
  }

  function renderLow(r) {
    var lw = r.low;

    $('rp-low-n').textContent = lw.total ? String(lw.total) : '';
    $('rp-low').innerHTML = (lw.rows.length ? lw.rows.map(function (x) {
      var out = x.qty <= 0;

      return '<div class="rp-li"><span class="rp-ln"><b>' + esc(x.name) + '</b>' + (x.category ? '<small>' + esc(x.category) + '</small>' : '') + '</span><span class="rp-st ' + (out ? 'out' : 'low') + '">' + esc(out ? t('rp_out', 'Out of stock') : x.qty + ' ' + t('rp_left', 'left')) + '</span></div>';
    }).join('') : '<div class="rp-empty">' + esc(t('rp_low_none', 'No product is low on stock.')) + '</div>') +
      (P.urls.products ? '<a class="btn rp-lnk" data-route="products" href="' + esc(P.urls.products) + '">' + ic('box-seam') + esc(t('rp_manage_products', 'Manage products')) + '</a>' : '');
  }

  function renderTables(r) {
    table($('rp-cash'), [
      { label: t('rp_cashier', 'Cashier') }, { label: t('rp_store', 'Store'), cls: 'hm' }, { label: t('rp_orders', 'Orders'), cls: 'num' }, { label: t('rp_net_sales', 'Net sales'), cls: 'num' },
      { label: t('rp_avg', 'Average order'), cls: 'num hm' }, { label: t('rp_discounts', 'Discounts'), cls: 'num hm' }, { label: t('rp_refunds', 'Refunds'), cls: 'num hs' }, { label: t('rp_voids_l', 'Voids'), cls: 'num hs' }
    ], r.cashier, function (x) {
      return '<tr><td>' + (x.name ? esc(x.name) : '<span style="color:var(--mute)">–</span>') + (x.review ? ' <span class="rp-st low" title="' + esc(t('rp_review_tip', 'Refunds and voids are unusually high.')) + '">' + esc(t('rp_review', 'Review')) + '</span>' : '') + '</td><td class="hm">' + esc(x.store || '') + '</td><td class="num">' + x.orders + '</td><td class="num">' + esc(x.text) + '</td><td class="num hm">' + esc(x.avg_text) + '</td><td class="num hm">' + esc(x.disc_text) + '</td><td class="num hs">' + x.refunds + '</td><td class="num hs">' + x.voids + '</td></tr>';
    });

    table($('rp-term'), [
      { label: t('rp_terminal', 'Terminal') }, { label: t('rp_orders', 'Orders'), cls: 'num' }, { label: t('rp_net_sales', 'Net sales'), cls: 'num' }, { label: t('rp_share', 'Share'), cls: 'num hs' }
    ], r.terminal, function (x) {
      return '<tr><td>' + (x.name ? esc(x.name) : '<span style="color:var(--mute)">–</span>') + '</td><td class="num">' + x.orders + '</td><td class="num">' + esc(x.text) + '</td><td class="num hs">' + x.pct + '%</td></tr>';
    });

    $('rp-stores-card').hidden = !r.stores.length;

    if (r.stores.length) {
      table($('rp-stores'), [
        { label: t('rp_store', 'Store') }, { label: t('rp_orders', 'Orders'), cls: 'num' }, { label: t('rp_net_sales', 'Net sales'), cls: 'num' }, { label: t('rp_avg', 'Average order'), cls: 'num hm' }, { label: t('rp_share', 'Share'), cls: 'num hs' }
      ], r.stores, function (x) {
        return '<tr><td>' + esc(x.name) + '</td><td class="num">' + x.orders + '</td><td class="num">' + esc(x.text) + '</td><td class="num hm">' + esc(x.avg_text) + '</td><td class="num hs">' + x.pct + '%</td></tr>';
      });
    }
  }

  function render(r) {
    last = r;
    var a = day(r.from, { day: 'numeric', month: 'short', year: 'numeric' });
    var b = day(r.to, { day: 'numeric', month: 'short', year: 'numeric' });

    $('rp-rt').textContent = r.from === r.to ? a : a + ' – ' + b;
    renderKpis(r.summary, r);
    renderChart(r.daily);
    renderTrendInfo(r);
    renderSummary(r);
    renderPayments(r);
    renderCategory(r);
    renderProducts(r);
    renderLow(r);
    renderTables(r);
  }

  function skeleton() {
    $('rp-kpis').innerHTML = [1, 2, 3, 4, 5, 6].map(function () { return '<div class="catc static" aria-hidden="true"><span class="ci g-All"></span><span><span class="sk" style="width:90px;height:16px;display:block"></span><span class="sk" style="width:60px;display:block;margin-top:6px"></span></span></div>'; }).join('');
    disposeChart();
    $('rp-chart').className = '';
    $('rp-chart').innerHTML = '<span class="sk" style="width:100%;height:200px;display:block"></span>';
  }

  function load() {
    if (ctl) { ctl.abort(); }
    ctl = new AbortController();

    var rng = S.range;

    if (!last) { skeleton(); }
    $('rp-body').classList.add('busy');
    $('rp-kpis').classList.add('busy');

    B.api(url(P.urls.data), { signal: ctl.signal }).then(function (r) {
      $('rp-body').classList.remove('busy');
      $('rp-kpis').classList.remove('busy');
      r.range = rng;
      render(r);
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      $('rp-body').classList.remove('busy');
      $('rp-kpis').classList.remove('busy');
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
      var b = r.breakdown;

      B.xlsx('report-' + r.from + '_' + r.to + '.xlsx', [
        { name: 'Summary', rows: [['from', 'to', 'currency'], [r.from, r.to, r.currency], [],
          ['net_sales', 'orders', 'average_order', 'items', 'discounts', 'refunded_voided', 'refunded_voided_count'],
          [s.sales.value, s.orders.value, s.avg.value, s.items.value, s.discounts.value, s.rv.value, s.rv.count], [],
          ['items_subtotal', 'discounts', 'coupons', 'shipping', 'extra_charges', 'tax', 'net_sales', 'refunded', 'refunded_count', 'voided', 'voided_count'],
          [b.subtotal.value, b.discounts.value, b.coupons.value, b.shipping.value, b.charge.value, b.tax.value, b.net.value, b.refunded.value, b.refunded.count, b.voided.value, b.voided.count]] },
        { name: 'Daily', rows: [['date', 'orders', 'sales']].concat(r.daily.map(function (x) { return [x.date, x.orders, x.sales]; })) },
        { name: 'Payment', rows: [['payment', 'orders', 'sales', 'share_pct']].concat(r.payment.map(function (x) { return [x.name || x.code, x.orders, x.sales, x.pct]; })) },
        { name: 'Category', rows: [['category', 'qty', 'sales', 'share_pct']].concat(r.category.map(function (x) { return [x.other ? 'Other' : x.name, x.qty, x.sales, x.pct]; })) },
        { name: 'Products', rows: [['product', 'category', 'qty', 'sales']].concat(r.products.map(function (x) { return [x.name, x.category, x.qty, x.sales]; })) },
        { name: 'Cashier', rows: [['cashier', 'store', 'orders', 'sales', 'average_order', 'discounts', 'refunds', 'voids', 'share_pct']].concat(r.cashier.map(function (x) { return [x.name, x.store, x.orders, x.sales, x.avg, x.discounts, x.refunds, x.voids, x.pct]; })) },
        { name: 'Terminal', rows: [['terminal', 'orders', 'sales', 'average_order', 'share_pct']].concat(r.terminal.map(function (x) { return [x.name, x.orders, x.sales, x.avg, x.pct]; })) }
      ].concat(r.stores.length ? [{ name: 'Stores', rows: [['store', 'orders', 'sales', 'average_order', 'share_pct']].concat(r.stores.map(function (x) { return [x.name, x.orders, x.sales, x.avg, x.pct]; })) }] : []));
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
