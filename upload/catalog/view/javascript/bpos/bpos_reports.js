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

    B.load(P.urls.am).then(function () {
      // A newer render may have run while the library was loading
      if (mine !== drawId || !el.isConnected) { return; }

      var am4core = w.am4core;
      var am4charts = w.am4charts;
      var ink = cssVar('--mute', '#6b7280');
      var line = cssVar('--line', '#e5e7eb');
      var accent = cssVar('--accent', '#3b5bdb');

      am4core.useTheme(w.am4themes_animated);
      chart = am4core.create(el, am4charts.XYChart);
      chart.fontFamily = cssVar('--f-body', 'inherit');
      chart.fontSize = 11;
      chart.paddingLeft = 0;
      chart.paddingRight = 6;
      chart.numberFormatter.numberFormat = '#.#a';

      chart.data = rows.map(function (r) {
        return { label: day(r.date, rows.length > 12 ? { day: 'numeric', month: 'short' } : { weekday: 'short', day: 'numeric' }), full: day(r.date, { weekday: 'short', day: 'numeric', month: 'short' }), sales: r.sales, text: r.text, orders: r.orders };
      });

      var x = chart.xAxes.push(new am4charts.CategoryAxis());
      x.dataFields.category = 'label';
      x.renderer.grid.template.disabled = true;
      x.renderer.minGridDistance = 36;
      x.renderer.labels.template.fill = am4core.color(ink);
      x.renderer.line.stroke = am4core.color(line);

      var y = chart.yAxes.push(new am4charts.ValueAxis());
      y.min = 0;
      y.renderer.grid.template.stroke = am4core.color(line);
      y.renderer.grid.template.strokeDasharray = '3,3';
      y.renderer.labels.template.fill = am4core.color(ink);
      y.renderer.line.disabled = true;

      var s = chart.series.push(new am4charts.ColumnSeries());
      s.dataFields.categoryX = 'label';
      s.dataFields.valueY = 'sales';
      s.columns.template.fill = am4core.color(accent);
      s.columns.template.stroke = am4core.color(accent);
      s.columns.template.column.cornerRadiusTopLeft = 8;
      s.columns.template.column.cornerRadiusTopRight = 8;
      s.columns.template.width = am4core.percent(70);
      s.columns.template.tooltipText = '[bold]{text}[/]\n{full} · {orders}';
      s.tooltip.getFillFromObject = false;
      s.tooltip.background.fill = am4core.color(cssVar('--navy', '#0f1b3d'));
      s.tooltip.background.stroke = am4core.color(cssVar('--navy', '#0f1b3d'));
      s.tooltip.label.fill = am4core.color('#ffffff');

      chart.cursor = new am4charts.XYCursor();
      chart.cursor.lineX.disabled = true;
      chart.cursor.lineY.disabled = true;
    }).catch(function (e) {
      el.innerHTML = '<div class="rp-empty">' + esc(e.message) + '</div>';
    });
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
  function csvCell(v) {
    v = v == null ? '' : String(v);

    if (/^[=+\-@\t\r]/.test(v)) { v = "'" + v; }

    return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }

  function exportCsv() {
    var btn = $('rp-export');
    btn.disabled = true;

    B.api(url(P.urls.export)).then(function (r) {
      var line = function (a) { return a.map(csvCell).join(','); };
      var s = r.summary;
      var out = [
        line(['from', 'to', 'currency']), line([r.from, r.to, r.currency]), '',
        line(['sales', 'orders', 'average_order', 'refunds', 'refund_count', 'void_count', 'items']),
        line([s.sales.value, s.orders.value, s.avg.value, s.refunds.value, s.refunds.count, s.voids, s.items]), '',
        line(['date', 'orders', 'sales'])
      ].concat(r.daily.map(function (x) { return line([x.date, x.orders, x.sales]); }));

      out.push('', line(['payment', 'orders', 'sales', 'share_pct']));
      r.payment.forEach(function (x) { out.push(line([x.name || x.code, x.orders, x.sales, x.pct])); });
      out.push('', line(['cashier', 'orders', 'sales', 'average_order', 'refunds', 'share_pct']));
      r.cashier.forEach(function (x) { out.push(line([x.name, x.orders, x.sales, x.avg, x.refunds, x.pct])); });
      out.push('', line(['terminal', 'orders', 'sales', 'average_order', 'share_pct']));
      r.terminal.forEach(function (x) { out.push(line([x.name, x.orders, x.sales, x.avg, x.pct])); });

      var blob = new Blob(['﻿' + out.join('\r\n')], { type: 'text/csv;charset=utf-8' });
      var a = d.createElement('a');

      a.href = URL.createObjectURL(blob);
      a.download = 'report-' + r.from + '_' + r.to + '.csv';
      d.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); d.body.removeChild(a); }, 500);
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

  if ($('rp-export')) { $('rp-export').addEventListener('click', exportCsv); }

  B.onLeave(function () { if (ctl) { ctl.abort(); } disposeChart(); });

  setRange('7');
})(window, document, window.BPOS);
