/* BPOS label print page: layout settings, live preview and printing. Settings live in this browser. */
(function (w, d, B) {
  var root = d.getElementById('bc-root');
  var dataEl = d.getElementById('bc-data');
  if (!root || !dataEl) { return; }

  var P = JSON.parse(dataEl.textContent);
  var $ = function (id) { return d.getElementById(id); };
  var esc = B.esc;
  var t = function (k, f) { return B.t(k, f); };
  var KEY = 'bpos:labels';
  var MAX_LABELS = 5000;
  var NUM = ['pw', 'ph', 'cols', 'copies', 'gx', 'gy', 'lh', 'mt', 'mr', 'mb', 'ml', 'bw', 'bh'];

  var DEF = { paper: '58', pw: 58, ph: 0, cols: 1, copies: 1, gx: 2, gy: 2, lh: 30, mt: 2, mr: 2, mb: 2, ml: 2, bw: 40, bh: 12, fit: true, lock: true, name: true, code: true, price: true };
  var PRESETS = {
    r58: { paper: '58', cols: 1, lh: 30, gx: 0, gy: 2, m: 2 },
    r58b: { paper: '58', cols: 2, lh: 22, gx: 2, gy: 2, m: 2 },
    r80: { paper: '80', cols: 3, lh: 25, gx: 2, gy: 2, m: 2 },
    a4: { paper: 'a4', cols: 3, lh: 33.9, gx: 2.5, gy: 0, m: 8 }
  };

  var S = load();
  var page = 0;
  var rows = [];
  var iframe = null;

  function load() {
    var s = JSON.parse(JSON.stringify(DEF));

    try {
      var saved = JSON.parse(w.localStorage.getItem(KEY) || 'null');

      if (saved && typeof saved === 'object') {
        Object.keys(DEF).forEach(function (k) {
          if (typeof saved[k] === typeof DEF[k]) { s[k] = saved[k]; }
        });
      }
    } catch (e) { /* storage unavailable */ }

    return s;
  }

  function save() {
    try { w.localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* storage unavailable */ }
  }

  function fixedPaper() {
    if (S.paper === '58') { S.pw = 58; S.ph = 0; }
    if (S.paper === '80') { S.pw = 80; S.ph = 0; }
    if (S.paper === 'a4') { S.pw = 210; S.ph = 297; }
    if (S.paper === 'custom' && !S.ph) { S.ph = 100; }
  }

  function num(id) { var v = parseFloat($('bc-' + id).value); return isFinite(v) && v >= 0 ? v : 0; }

  function read() {
    NUM.forEach(function (k) { S[k] = num(k); });
    S.cols = Math.max(1, Math.min(8, Math.round(S.cols)));
    S.copies = Math.max(1, Math.min(99, Math.round(S.copies)));
  }

  function write() {
    NUM.forEach(function (k) {
      var el = $('bc-' + k);
      if (d.activeElement !== el) { el.value = k === 'ph' && !S.ph ? '' : S[k]; }
    });
  }

  function isRoll() { return S.paper === '58' || S.paper === '80'; }

  /* ---------- geometry (mm), shared by preview and print ---------- */
  function geometry() {
    var roll = isRoll();
    var lw = (S.pw - S.ml - S.mr - S.gx * (S.cols - 1)) / S.cols;
    var total = rows.length * S.copies;
    var capped = Math.min(total, MAX_LABELS);
    var rowsNeeded = Math.ceil(capped / S.cols);
    var fit = roll ? rowsNeeded : Math.max(0, Math.floor((S.ph - S.mt - S.mb + S.gy) / (S.lh + S.gy)));
    var pages = roll ? rowsNeeded : (fit ? Math.ceil(rowsNeeded / fit) : 0);
    // On a roll every row is its own page, so the bottom gap becomes part of the page height
    var pageH = roll ? S.mt + S.lh + S.gy + S.mb : S.ph;
    var bw = S.fit ? Math.max(0, lw - 4) : S.bw;
    var bcw = Math.max(0, Math.min(bw, lw - 1));
    var bch = Math.max(2, Math.min(S.bh, S.lh * 0.55));

    return { roll: roll, lw: lw, total: total, capped: capped, rowsNeeded: rowsNeeded, fit: fit, pages: pages, pageH: pageH, bcw: bcw, bch: bch, fs: Math.max(1.8, Math.min(3.6, S.lh / 7)) };
  }

  function labelAt(g, index) {
    return rows[Math.floor(index / S.copies) % rows.length];
  }

  // unit: pixels per mm for the preview, or 'mm' for print
  function labelHtml(g, row, x, y, unit) {
    var u = function (v) { return unit === 'mm' ? v.toFixed(2) + 'mm' : (v * unit).toFixed(2) + 'px'; };
    var svg = B.code128(row.barcode, 40);

    if (svg) { svg = svg.replace('<svg ', '<svg style="width:' + u(g.bcw) + ';height:' + u(g.bch) + '" '); }

    return '<div class="bc-lb" style="left:' + u(x) + ';top:' + u(y) + ';width:' + u(g.lw) + ';height:' + u(S.lh) + ';font-size:' + u(g.fs) + ';gap:' + u(g.fs * 0.25) + '">' +
      (S.name ? '<div class="n">' + esc(row.name) + '</div>' : '') + svg +
      (S.code ? '<div class="c" style="font-size:' + u(g.fs * 0.85) + '">' + esc(row.barcode) + '</div>' : '') +
      (S.price ? '<div class="p" style="font-size:' + u(g.fs * 1.1) + '">' + esc(row.price) + '</div>' : '') + '</div>';
  }

  // Labels of one page, as { x, y, index }
  function slots(g, pageIndex) {
    var out = [];
    var perPage = g.roll ? g.cols0 : g.fit * S.cols;
    var first = g.roll ? pageIndex * S.cols : pageIndex * perPage;
    var count = g.roll ? S.cols : perPage;

    for (var i = 0; i < count; i++) {
      var index = first + i;
      if (index >= g.capped) { break; }

      var c = i % S.cols;
      var r = Math.floor(i / S.cols);
      out.push({ x: S.ml + c * (g.lw + S.gx), y: S.mt + r * (S.lh + S.gy), index: index });
    }

    return out;
  }

  function warnings(g) {
    var out = [];
    var modules = rows.length ? (String(rows[0].barcode).length + 2) * 11 + 13 + 20 : 0;

    if (g.lw < 12) { out.push(t('bc_w_narrow', 'Label too narrow')); }
    if (!g.roll && !g.fit) { out.push(t('bc_w_tall', 'Label taller than the page')); }
    if (!S.fit && S.bw > g.lw) { out.push(t('bc_w_wide', 'Barcode wider than label')); }
    if (S.bh + (S.name ? 4 : 0) + (S.code ? 3.5 : 0) + (S.price ? 4 : 0) > S.lh) { out.push(t('bc_w_content', 'Content taller than label')); }
    if (modules && g.bcw > 0 && g.bcw / modules < 0.19) { out.push(t('bc_w_dense', 'Barcode may be too dense to scan')); }
    if (g.total > MAX_LABELS) { out.push(t('bc_w_cap', 'Only the first') + ' ' + MAX_LABELS + ' ' + t('bc_labels', 'labels')); }

    return out;
  }

  /* ---------- render ---------- */
  function render() {
    fixedPaper();

    var g = geometry();
    g.cols0 = S.cols;

    var roll = g.roll;
    var stage = $('bc-stage');
    var sheet = $('bc-sheet');

    $('bc-lw').value = g.lw > 0 ? g.lw.toFixed(1) : '0';
    $('bc-bw').disabled = S.fit;
    if (S.fit) { $('bc-bw').value = g.bcw > 0 ? g.bcw.toFixed(1) : '0'; }
    $('bc-pw').disabled = S.paper !== 'custom';
    $('bc-ph').disabled = S.paper !== 'custom';
    $('bc-ph').value = roll ? '' : S.ph;
    $('bc-ph').placeholder = roll ? t('bc_auto', 'Auto') : '';
    $('bc-rollhint').hidden = !roll;
    $('bc-papertxt').textContent = roll ? S.pw + ' mm × ' + t('bc_auto', 'auto').toLowerCase() : S.pw + ' × ' + S.ph + ' mm';

    Array.prototype.forEach.call($('bc-paper').children, function (b) { b.classList.toggle('on', b.getAttribute('data-p') === S.paper); });
    Array.prototype.forEach.call($('bc-show').children, function (b) { b.classList.toggle('on', !!S[b.getAttribute('data-k')]); });
    [['bc-lock', S.lock], ['bc-fit', S.fit]].forEach(function (x) {
      $(x[0]).setAttribute('aria-checked', x[1] ? 'true' : 'false');
      $(x[0]).firstChild.classList.toggle('on', !!x[1]);
    });

    $('bc-seln').textContent = rows.length + ' ' + t('bc_products', 'products');
    $('bc-sub').textContent = rows.length + ' ' + t('bc_products', 'products') + ' · ' + g.total + ' ' + t('bc_labels', 'labels');
    $('bc-pbtn').textContent = t('bc_print', 'Print') + ' ' + Math.min(g.total, MAX_LABELS) + ' ' + t('bc_labels', 'labels');

    var warn = warnings(g);
    var blocked = !rows.length || g.lw <= 0 || (!roll && !g.fit);
    $('bc-print').disabled = blocked;

    if (!rows.length) { return; }

    // Pager: a roll previews its first rows, a sheet previews page by page
    var pc = roll ? 1 : Math.max(1, g.pages);
    page = Math.max(0, Math.min(page, pc - 1));

    var showRows;

    if (roll) {
      showRows = Math.min(g.rowsNeeded, 6);
    } else {
      showRows = Math.max(1, Math.min(g.rowsNeeded - page * g.fit, g.fit || 1));
    }

    var pitch = roll ? g.pageH : S.lh + S.gy;
    var sheetH = roll ? showRows * g.pageH : S.ph;
    var sc = Math.min((stage.clientWidth - 36) / S.pw, (stage.clientHeight - 36) / sheetH);

    if (!(sc > 0)) { sc = 3; }

    var html = '';
    var firstRow = roll ? 0 : page * g.fit;

    for (var r = 0; r < showRows; r++) {
      for (var c = 0; c < S.cols; c++) {
        var index = (firstRow + r) * S.cols + c;
        if (index >= g.capped) { break; }

        var x = S.ml + c * (g.lw + S.gx);
        var y = roll ? r * g.pageH + S.mt : S.mt + r * pitch;
        html += labelHtml(g, labelAt(g, index), x, y, sc);
      }

      if (roll && r < showRows - 1) { html += '<div class="m" style="left:0;right:0;top:' + ((r + 1) * g.pageH * sc - 1) + 'px;height:0;border-width:1px 0 0"></div>'; }
    }

    if (!roll) { html += '<div class="m" style="left:' + S.ml * sc + 'px;top:' + S.mt * sc + 'px;right:' + S.mr * sc + 'px;bottom:' + S.mb * sc + 'px"></div>'; }

    sheet.style.width = S.pw * sc + 'px';
    sheet.style.height = sheetH * sc + 'px';
    sheet.innerHTML = html;

    $('bc-ptxt').textContent = roll ? (g.rowsNeeded > 6 ? t('bc_first_rows', 'First 6 of') + ' ' + g.rowsNeeded + ' ' + t('bc_rows', 'rows') : g.rowsNeeded + ' ' + t('bc_rows', 'rows')) : t('bc_page', 'Page') + ' ' + (page + 1) + ' / ' + pc;
    $('bc-prev').disabled = roll || page <= 0;
    $('bc-next').disabled = roll || page >= pc - 1;

    $('bc-meta').innerHTML = '<span class="bc-chip">' + esc(t('bc_label', 'Label')) + ' <b>' + (g.lw > 0 ? g.lw.toFixed(1) : '0') + ' × ' + S.lh + ' mm</b></span>' +
      '<span class="bc-chip"><b>' + S.cols + '</b> ' + esc(t('bc_per_row', 'per row')) + '</span>' +
      '<span class="bc-chip"><b>' + g.pages + '</b> ' + esc(roll ? t('bc_rows', 'rows') : (g.pages === 1 ? t('bc_page_l', 'page') : t('bc_pages', 'pages'))) + '</span>' +
      (roll ? '' : '<span class="bc-chip"><b>' + g.fit * S.cols + '</b> ' + esc(t('bc_per_page', 'labels/page')) + '</span>') +
      warn.map(function (x) { return '<span class="bc-chip warn"><b><i class="bi bi-exclamation-triangle"></i> ' + esc(x) + '</b></span>'; }).join('');
  }

  function changed() { read(); save(); render(); }

  /* ---------- print ---------- */
  function printNow() {
    var g = geometry();
    g.cols0 = S.cols;
    if (!rows.length || g.lw <= 0 || (!g.roll && !g.fit)) { return; }

    var pagesHtml = '';

    for (var p = 0; p < g.pages; p++) {
      pagesHtml += '<div class="pg" style="width:' + S.pw + 'mm;height:' + g.pageH + 'mm">' +
        slots(g, p).map(function (s) { return labelHtml(g, labelAt(g, s.index), s.x, s.y, 'mm'); }).join('') + '</div>';
    }

    var css = '@page{size:' + S.pw + 'mm ' + g.pageH + 'mm;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff}' +
      '.pg{position:relative;overflow:hidden;page-break-after:always;break-after:page}.pg:last-child{page-break-after:auto;break-after:auto}' +
      '.bc-lb{position:absolute;display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;color:#000;text-align:center;font-family:Arial,Helvetica,sans-serif}' +
      '.bc-lb .n{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}.bc-lb .c{font-family:ui-monospace,Menlo,Consolas,monospace}.bc-lb .p{font-weight:700}.bc-lb svg{display:block;color:#000}';

    cleanup();
    iframe = d.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    d.body.appendChild(iframe);

    var doc = iframe.contentWindow.document;
    doc.open();
    doc.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(t('bc_title', 'Labels')) + '</title><style>' + css + '</style></head><body>' + pagesHtml + '</body></html>');
    doc.close();

    setTimeout(function () {
      if (!iframe) { return; }
      iframe.contentWindow.focus();
      iframe.contentWindow.print();
    }, 250);
  }

  function cleanup() {
    if (iframe && iframe.parentNode) { iframe.parentNode.removeChild(iframe); }
    iframe = null;
  }

  /* ---------- events ---------- */
  function onResize() { render(); }

  $('bc-set').addEventListener('input', function (e) {
    var el = e.target;
    if (el.tagName !== 'INPUT') { return; }

    if (el.hasAttribute('data-m') && S.lock) {
      ['mt', 'mr', 'mb', 'ml'].forEach(function (k) { if ('bc-' + k !== el.id) { $('bc-' + k).value = el.value; } });
    }

    changed();
  });

  $('bc-set').addEventListener('click', function (e) {
    var x;

    if ((x = e.target.closest('[data-s]'))) {
      var id = 'bc-' + x.getAttribute('data-s');
      $(id).value = Math.max(1, num(x.getAttribute('data-s')) + Number(x.getAttribute('data-d')));
      changed();
    } else if ((x = e.target.closest('#bc-paper button'))) {
      read();
      S.paper = x.getAttribute('data-p');

      if (S.paper === 'a4') { S.cols = 3; S.lh = 33.9; S.gx = 2.5; S.gy = 0; S.mt = S.mr = S.mb = S.ml = 8; }

      fixedPaper(); write(); save(); render();
    } else if ((x = e.target.closest('#bc-show button'))) {
      var k = x.getAttribute('data-k');
      S[k] = !S[k];
      save(); render();
    } else if ((x = e.target.closest('#bc-pre button'))) {
      var pr = PRESETS[x.getAttribute('data-pr')];
      S.paper = pr.paper; S.cols = pr.cols; S.lh = pr.lh; S.gx = pr.gx; S.gy = pr.gy; S.mt = S.mr = S.mb = S.ml = pr.m;
      fixedPaper(); write(); save(); render();
    } else if (e.target.closest('#bc-lock')) {
      S.lock = !S.lock;

      if (S.lock) { ['mr', 'mb', 'ml'].forEach(function (m) { $('bc-' + m).value = $('bc-mt').value; }); changed(); } else { save(); render(); }
    } else if (e.target.closest('#bc-fit')) {
      read();
      S.fit = !S.fit;

      if (!S.fit) { S.bw = Math.max(10, Math.round(geometry().bcw)); }

      write(); save(); render();
    } else if (e.target.closest('#bc-change')) {
      B.router.go(P.urls.products);
    }
  });

  $('bc-back').addEventListener('click', function () { B.router.go(P.urls.products); });
  $('bc-prev').addEventListener('click', function () { page--; render(); });
  $('bc-next').addEventListener('click', function () { page++; render(); });
  $('bc-print').addEventListener('click', printNow);
  $('bc-reset').addEventListener('click', function () { S = JSON.parse(JSON.stringify(DEF)); fixedPaper(); write(); save(); render(); });

  w.addEventListener('resize', onResize);
  B.onLeave(function () { w.removeEventListener('resize', onResize); cleanup(); });

  /* ---------- boot ---------- */
  function empty(msg) {
    $('bc-sheet').style.cssText = '';
    $('bc-sheet').innerHTML = '';
    $('bc-stage').innerHTML = '<div class="bc-empty"><span>' + esc(msg) + '</span><button class="btn" type="button" id="bc-empty-back">' + esc(t('bc_back', 'Back to products')) + '</button></div>';
    $('bc-empty-back').addEventListener('click', function () { B.router.go(P.urls.products); });
    $('bc-print').disabled = true;
  }

  fixedPaper();
  write();
  render();

  // Only the selection travels on to the labels endpoint. The page URL also carries route=..., which would override the endpoint route.
  var qp = new w.URLSearchParams(w.location.search);
  var keep = new w.URLSearchParams();

  ['q', 'category_id', 'stock', 'barcode', 'pos', 'ids'].forEach(function (k) { if (qp.has(k)) { keep.set(k, qp.get(k)); } });

  var qs = keep.toString();

  B.api(P.urls.labels + (qs ? (P.urls.labels.indexOf('?') > -1 ? '&' : '?') + qs : '')).then(function (r) {
    rows = r.rows || [];

    if (!rows.length) { empty(t('bc_none', 'No products with a barcode to print.')); return; }

    render();
    if (r.truncated) { B.ui.toast(t('bc_cut', 'Only the first') + ' ' + P.max + ' ' + t('bc_products', 'products')); }
  }).catch(function (e) { empty(e.message); });
})(window, document, window.BPOS);