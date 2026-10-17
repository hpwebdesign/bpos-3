/* BPOS Products page: list, filters, bulk actions, edit and add drawer, barcodes and label printing. */
(function (w, d, B) {
  var root = d.getElementById('pr-root');
  var dataEl = d.getElementById('pr-data');
  if (!root || !dataEl) { return; }

  var P = JSON.parse(dataEl.textContent);
  var $ = function (id) { return d.getElementById(id); };
  var esc = B.esc;
  var t = function (k, f) { return B.t(k, f); };
  var ic = function (n) { return '<i class="bi bi-' + n + '"></i>'; };

  var S = { q: '', cat: 0, stock: '', bc: '', pos: '', sort: 'name', dir: 'asc', per: P.per[0], page: 1, pages: 1, total: 0, rows: [], stats: { total: 0, shown: 0, attn: 0 }, sel: {}, fopen: false, loaded: false, open: null };
  var ctl = null;
  var timer = null;
  var menu = null;
  var dirty = false;
  var drawer = null;

  function url(base, qs) { return base + (base.indexOf('?') > -1 ? '&' : '?') + qs; }

  function filterQs() {
    return 'q=' + encodeURIComponent(S.q) + '&category_id=' + S.cat + '&stock=' + S.stock + '&barcode=' + S.bc + '&pos=' + S.pos;
  }

  /* ---------- Code 128 (set B) as inline SVG ---------- */
  var C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

  function code128(value, height) {
    value = String(value || '');
    if (!value) { return ''; }

    var codes = [104];
    var sum = 104;

    for (var i = 0; i < value.length; i++) {
      var c = value.charCodeAt(i) - 32;
      if (c < 0 || c > 94) { return ''; }
      codes.push(c);
      sum += c * (i + 1);
    }

    codes.push(sum % 103, 106);

    var x = 10;
    var bars = '';

    codes.forEach(function (code) {
      C128[code].split('').forEach(function (n, k) {
        n = +n;
        if (k % 2 === 0) { bars += '<rect x="' + x + '" y="0" width="' + n + '" height="' + height + '"/>'; }
        x += n;
      });
    });

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + (x + 10) + ' ' + height + '" preserveAspectRatio="none" fill="currentColor" role="img" aria-label="' + esc(value) + '">' + bars + '</svg>';
  }

  /* ---------- list ---------- */
  function skeleton() {
    var out = '';
    for (var i = 0; i < 8; i++) {
      out += '<tr aria-hidden="true"><td class="pr-cb"></td>' + [180, 90, 90, 60, 40, 40, 70].map(function (wd, k) { return '<td' + (k >= 1 && k <= 5 ? ' class="hm"' : '') + '><span class="sk" style="width:' + wd + 'px"></span></td>'; }).join('') + '</tr>';
    }
    $('pr-body').innerHTML = out;
  }

  function load(keepPage) {
    if (ctl) { ctl.abort(); }
    ctl = new AbortController();

    if (!keepPage) { S.page = 1; }

    $('pr-card').classList.add('busy');
    if (!S.loaded) { skeleton(); }

    B.api(url(P.urls.data, filterQs() + '&sort=' + S.sort + '&dir=' + S.dir + '&per=' + S.per + '&page=' + S.page), { signal: ctl.signal }).then(function (r) {
      S.loaded = true;
      S.rows = r.rows; S.page = r.page; S.pages = r.pages; S.total = r.total; S.stats = r.stats;
      $('pr-card').classList.remove('busy');
      render();
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      $('pr-card').classList.remove('busy');
      B.ui.toast(e.message, 'err');
    });
  }

  function stockClass(n) { return n <= 0 ? 'out' : (n <= P.low ? 'low' : 'ok'); }

  function opts(sel, list, cur) {
    sel.innerHTML = list.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(cur) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('');
  }

  function fillFilters() {
    opts($('pr-cat'), [[0, t('pr_all_cats', 'All categories')]].concat(P.cats.map(function (c) { return [c.id, c.name]; })), S.cat);
    opts($('pr-stock'), [['', t('pr_any_stock', 'Any stock')], ['in', t('pr_in_stock', 'In stock')], ['low', t('pr_low_stock', 'Low stock')], ['out', t('pr_out_stock', 'Out of stock')]], S.stock);
    opts($('pr-bc'), [['', t('pr_any_bc', 'Any barcode')], ['has', t('pr_has_bc', 'Has barcode')], ['missing', t('pr_missing', 'Missing')], ['dup', t('pr_dup', 'Duplicate')], ['attn', t('pr_attn', 'Need attention')]], S.bc);
    opts($('pr-per'), P.per.map(function (n) { return [n, n]; }), S.per);
  }

  function catName(id) {
    var c = P.cats.filter(function (x) { return x.id === id; })[0];
    return c ? c.name : '';
  }

  function renderBar() {
    var st = S.stats;

    $('pr-sub').textContent = st.total + ' ' + t('pr_products', 'products') + ' · ' + st.shown + ' ' + t('pr_shown_pos', 'shown in POS');

    function kp(icon, key, n, label, on, warn) {
      return '<button type="button" class="catc' + (on ? ' on' : '') + (warn ? ' warn' : '') + '" data-kp="' + key + '" role="tab" aria-selected="' + (on ? 'true' : 'false') + '"><span class="ci g-All">' + ic(icon) + '</span><span><b>' + n + '</b><small>' + esc(label) + '</small></span></button>';
    }

    $('pr-kpis').innerHTML = kp('grid', 'all', st.total, t('pr_total', 'Total products'), !S.pos && S.bc !== 'attn') +
      kp('display', 'yes', st.shown, t('pr_shown', 'Shown in POS'), S.pos === 'yes') +
      kp('eye-slash', 'no', st.total - st.shown, t('pr_hidden', 'Hidden'), S.pos === 'no') +
      kp('exclamation-triangle', 'attn', st.attn, t('pr_attention', 'Need attention'), S.bc === 'attn', st.attn > 0);

    var chips = [];
    function add(label, key) { chips.push('<span class="pr-chip">' + esc(label) + '<button type="button" data-unchip="' + key + '" aria-label="' + esc(t('pr_remove_filter', 'Remove filter')) + '">' + ic('x-lg') + '</button></span>'); }

    if (S.cat) { add(catName(S.cat), 'cat'); }
    if (S.stock) { add({ 'in': t('pr_in_stock', 'In stock'), low: t('pr_low_stock', 'Low stock'), out: t('pr_out_stock', 'Out of stock') }[S.stock], 'stock'); }
    if (S.bc) { add({ has: t('pr_has_bc', 'Has barcode'), missing: t('pr_missing', 'Missing'), dup: t('pr_dup', 'Duplicate'), attn: t('pr_attn', 'Need attention') }[S.bc] + ' · ' + t('pr_barcode', 'Barcode'), 'bc'); }

    $('pr-chips').innerHTML = chips.join('') + (chips.length ? '<button type="button" class="pr-link" data-unchip="all">' + esc(t('pr_clear_all', 'Clear all')) + '</button>' : '');

    var n = (S.cat ? 1 : 0) + (S.stock ? 1 : 0) + (S.bc ? 1 : 0);
    $('pr-fn').hidden = !n;
    $('pr-fn').textContent = n;
    $('pr-fbtn').classList.toggle('has', n > 0);
    $('pr-fbtn').setAttribute('aria-expanded', S.fopen ? 'true' : 'false');
    $('pr-panel').hidden = !S.fopen;
  }

  function selected() { return Object.keys(S.sel).map(Number); }

  function renderBulk() {
    var ids = selected();
    var box = $('pr-bulk');
    box.hidden = !ids.length;
    if (!ids.length) { box.innerHTML = ''; return; }

    box.innerHTML = '<b>' + ids.length + ' ' + esc(t('pr_selected', 'selected')) + '</b>' +
      (P.can.edit ? '<button class="btn" type="button" data-bulk="show">' + ic('eye') + '<span>' + esc(t('pr_show_pos', 'Show in POS')) + '</span></button>' +
        '<button class="btn" type="button" data-bulk="hide">' + ic('eye-slash') + '<span>' + esc(t('pr_hide_pos', 'Hide from POS')) + '</span></button>' +
        '<button class="btn" type="button" data-bulk="gen">' + ic('upc-scan') + '<span>' + esc(t('pr_gen_barcodes', 'Generate barcodes')) + '</span></button>' : '') +
      '<button class="btn" type="button" data-bulk="print">' + ic('printer') + '<span>' + esc(t('pr_print_labels', 'Print labels')) + '</span></button>' +
      '<button class="btn pr-end" type="button" data-bulk="none">' + esc(t('pr_deselect', 'Deselect')) + '</button>';
  }

  function render() {
    renderBar();

    var rows = S.rows;
    var allOn = rows.length && rows.every(function (r) { return S.sel[r.id]; });
    $('pr-all').checked = !!allOn;

    $('pr-body').innerHTML = rows.length ? rows.map(function (r) {
      var cls = stockClass(r.stock);
      var thumb = r.image ? '<img src="' + esc(r.image) + '" alt="" loading="lazy">' : ic('box-seam');
      var bc;

      if (!r.barcode) {
        bc = P.can.edit ? '<button class="btn sm" type="button" data-act="gen" data-id="' + r.id + '">' + ic('upc-scan') + '<span>' + esc(t('pr_generate', 'Generate')) + '</span></button>' : '<span style="color:var(--mute)">' + esc(t('pr_no_barcode', 'No barcode')) + '</span>';
      } else {
        bc = '<div class="pr-bc">' + (r.dup ? '<span class="pr-st bad" style="width:max-content">' + esc(t('pr_dup', 'Duplicate')) + '</span>' : code128(r.barcode, 40)) + '<span class="mono">' + esc(r.barcode) + '</span></div>';
      }

      return '<tr data-open="' + r.id + '"' + (S.sel[r.id] ? ' class="sel"' : '') + (P.can.edit ? ' tabindex="0"' : '') + '>' +
        '<td class="pr-cb"><input class="pr-check" type="checkbox" data-sel="' + r.id + '"' + (S.sel[r.id] ? ' checked' : '') + ' aria-label="' + esc(t('pr_select', 'Select')) + '"></td>' +
        '<td><div class="pr-name"><span class="pr-pth">' + thumb + '</span><span><b>' + esc(r.name) + '</b><small>' + esc(r.category || '–') + '</small><small class="pr-mo">' + esc(r.model) + ' · ' + esc(r.price) + ' · ' + r.stock + '</small></span></div></td>' +
        '<td class="hm">' + esc(r.model) + '<small>' + esc(r.sku || '–') + '</small></td>' +
        '<td class="hm">' + bc + '</td>' +
        '<td class="num hm">' + esc(r.price) + '</td>' +
        '<td class="hm"><span class="pr-st ' + cls + '">' + r.stock + '</span></td>' +
        '<td class="hm"><button type="button" class="pr-sw" role="switch" aria-checked="' + r.pos + '" data-act="pos" data-id="' + r.id + '" aria-label="' + esc(t('pr_shown', 'Shown in POS')) + '"' + (P.can.edit ? '' : ' disabled') + '></button></td>' +
        '<td class="pr-acts"><div class="pr-acts-in">' + (P.can.edit ? '<button class="btn pri sq" type="button" data-act="edit" data-id="' + r.id + '" aria-label="' + esc(t('pr_edit', 'Edit product')) + '">' + ic('pencil') + '</button>' : '') +
        '<button class="btn" type="button" data-act="menu" data-id="' + r.id + '" aria-haspopup="true">' + ic('sliders') + '<span>' + esc(t('pr_manage', 'Manage')) + '</span>' + ic('chevron-down') + '</button></div></td></tr>';
    }).join('') : '<tr><td colspan="8"><div class="mg-empty">' + esc(t('pr_empty', 'No products match these filters.')) + '</div></td></tr>';

    Array.prototype.forEach.call(root.querySelectorAll('th[data-sort]'), function (th) {
      var on = th.getAttribute('data-sort') === S.sort;
      th.setAttribute('aria-sort', on ? (S.dir === 'asc' ? 'ascending' : 'descending') : 'none');
      th.innerHTML = th.textContent.replace(/\s+$/, '') + (on ? ' ' + ic(S.dir === 'asc' ? 'caret-up-fill' : 'caret-down-fill') : '');
    });

    var from = S.total ? (S.page - 1) * S.per + 1 : 0;
    var to = Math.min(S.total, S.page * S.per);

    $('pr-foot').innerHTML = '<span>' + esc(from + '–' + to + ' / ' + S.total) + '</span>' +
      '<span class="mg-pg"><button type="button" data-pg="-1" aria-label="' + esc(t('od_prev', 'Previous page')) + '"' + (S.page <= 1 ? ' disabled' : '') + '>' + ic('chevron-left') + '</button>' +
      '<span>' + S.page + ' / ' + S.pages + '</span>' +
      '<button type="button" data-pg="1" aria-label="' + esc(t('od_next', 'Next page')) + '"' + (S.page >= S.pages ? ' disabled' : '') + '>' + ic('chevron-right') + '</button></span>';

    renderBulk();
  }

  function rowOf(id) {
    return S.rows.filter(function (r) { return r.id === id; })[0] || null;
  }

  /* ---------- actions ---------- */
  function setPos(ids, on) {
    return B.api(P.urls.toggle, { data: { ids: ids, pos: on } }).then(function () {
      B.ui.toast(t('pr_updated', 'Updated'));
      load(true);
    }).catch(function (e) { B.ui.toast(e.message, 'err'); });
  }

  function generate(ids) {
    return B.api(P.urls.generate, { data: { ids: ids } }).then(function (r) {
      B.ui.toast(r.count ? t('pr_generated', 'Generated') + ' ' + r.count + ' ' + t('pr_barcodes', 'barcodes') : t('pr_nothing_gen', 'Nothing to generate'));
      load(true);
    }).catch(function (e) { B.ui.toast(e.message, 'err'); });
  }

  function printLabels(ids) {
    var win = w.open('', '_blank');

    if (!win) { B.ui.toast(t('pr_popup', 'Allow pop-ups to print labels.'), 'err'); return; }

    B.api(url(P.urls.labels, filterQs() + (ids && ids.length ? '&ids=' + ids.join(',') : ''))).then(function (r) {
      if (!r.rows.length) { win.close(); B.ui.toast(t('pr_no_labels', 'No products with a barcode to print.')); return; }

      var cells = r.rows.map(function (x) {
        return '<div class="l"><div class="n">' + esc(x.name) + '</div>' + code128(x.barcode, 60) + '<div class="c">' + esc(x.barcode) + '</div><div class="p">' + esc(x.price) + '</div></div>';
      }).join('');

      win.document.open();
      win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(t('pr_print_labels', 'Print labels')) + '</title><style>' +
        '@page{size:58mm auto;margin:0}body{margin:0;font-family:Arial,sans-serif;color:#000}.l{width:58mm;box-sizing:border-box;padding:3mm 4mm;page-break-after:always;text-align:center}' +
        '.n{font-size:11px;font-weight:700;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}svg{width:100%;height:14mm;margin:2mm 0 1mm;color:#000}.c{font:11px monospace}.p{font-size:13px;font-weight:700;margin-top:1mm}' +
        '</style></head><body>' + cells + '</body></html>');
      win.document.close();
      win.focus();
      setTimeout(function () { win.print(); }, 250);

      if (r.truncated) { B.ui.toast(t('pr_labels_cut', 'Only the first 500 labels are printed.')); }
    }).catch(function (e) { win.close(); B.ui.toast(e.message, 'err'); });
  }

  /* ---------- manage menu ---------- */
  function closeMenu() {
    if (menu) { menu.parentNode.removeChild(menu); menu = null; }
  }

  function openMenu(btn, id) {
    closeMenu();

    var r = rowOf(id);
    if (!r) { return; }

    function it(act, icon, label, tab) { return '<button type="button" data-m="' + act + '" data-id="' + id + '"' + (tab ? ' data-tab="' + tab + '"' : '') + '>' + ic(icon) + '<span>' + esc(label) + '</span></button>'; }

    menu = d.createElement('div');
    menu.className = 'pr-menu';
    menu.innerHTML = (P.can.edit ? it('edit', 'file-earmark-text', t('pr_general', 'General'), 'general') + it('edit', 'upc', t('pr_barcode_ids', 'Barcode & identifiers'), 'barcode') + '<hr>' : '') +
      (P.can.edit && !r.barcode ? it('gen', 'upc-scan', t('pr_gen_barcode', 'Generate barcode')) : '') +
      (r.barcode ? it('label', 'printer', t('pr_print_label', 'Print label')) : '') +
      (P.can.edit ? it('pos', r.pos ? 'eye-slash' : 'eye', r.pos ? t('pr_hide_pos', 'Hide from POS') : t('pr_show_pos', 'Show in POS')) : '');

    d.body.appendChild(menu);

    var b = btn.getBoundingClientRect();
    var top = b.bottom + 6;
    if (top + menu.offsetHeight > w.innerHeight - 10) { top = Math.max(10, b.top - menu.offsetHeight - 6); }
    menu.style.top = top + 'px';
    menu.style.left = Math.max(10, Math.min(b.right - menu.offsetWidth, w.innerWidth - menu.offsetWidth - 10)) + 'px';
  }

  /* ---------- drawer: edit and add ---------- */
  function markDirty() { dirty = true; }

  function openDrawer(r, tab) {
    drawer = { id: r ? r.id : 0, tab: tab || 'general' };
    dirty = false;
    S.open = drawer.id;

    r = r || { id: 0, name: '', model: '', sku: '', upc: '', barcode: '', category_id: 0, price_value: 0, stock: 0, pos: true };

    function field(id, label, value, extra) {
      return '<label class="mg-f" data-f="' + id + '">' + esc(label) + '<input id="pf-' + id + '" type="text" value="' + esc(value) + '" autocomplete="off"' + (extra || '') + '><em></em></label>';
    }

    var catSel = '<label class="mg-f" data-f="category_id">' + esc(t('pr_category', 'Category')) + '<select id="pf-category_id">' +
      '<option value="0">' + esc(t('pr_no_category', 'No category')) + '</option>' +
      P.cats.map(function (c) { return '<option value="' + c.id + '"' + (c.id === r.category_id ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select><em></em></label>';

    var sw = '<div class="mg-f"><span>' + esc(t('pr_shown', 'Shown in POS')) + '</span><div class="pr-swrow"><button type="button" class="pr-sw" id="pf-pos" role="switch" aria-checked="' + !!r.pos + '"></button><small>' + esc(t('pr_hidden_note', 'Hidden products cannot be sold or scanned in POS.')) + '</small></div></div>';

    var unit = ' (' + P.currency.symbol + ')';

    var general = field('name', t('pr_name', 'Name'), r.name) + '<div class="pr-two">' + catSel + field('model', t('pr_model', 'Model'), r.model) + '</div>' + sw +
      '<div class="pr-two">' + field('price', t('pr_price', 'Price') + unit, r.price_value, ' inputmode="decimal"') + field('quantity', t('pr_quantity', 'Quantity'), r.stock, ' inputmode="numeric"') + '</div>' +
      '<div class="pr-note">' + ic('info-circle') + '<span>' + esc(t('pr_stock_note', 'Negative stock is never allowed. Products at 0 stay disabled in POS.') + ' ' + t('pr_low_note', 'Low stock warning at or below') + ' ' + P.low + '.') + '</span></div>';

    var body;
    var title;

    if (drawer.id) {
      title = '<span class="mg-who"><span class="pr-pth">' + (r.image ? '<img src="' + esc(r.image) + '" alt="">' : ic('box-seam')) + '</span><span>' + esc(r.name) + '<small>' + esc(r.model + (r.sku ? ' · ' + r.sku : '')) + '</small></span></span>';

      var barcode = '<label class="mg-f" data-f="barcode">' + esc(t('pr_barcode_value', 'Barcode value')) + '<span class="pr-row"><input id="pf-barcode" type="text" value="' + esc(r.barcode) + '" autocomplete="off" maxlength="14" style="font-family:var(--f-mono)"><button class="btn" type="button" data-use="sku">' + esc(t('pr_use_sku', 'Use SKU')) + '</button><button class="btn" type="button" data-use="model">' + esc(t('pr_use_model', 'Use Model')) + '</button></span><small style="font-weight:500">' + esc(t('pr_scan_note', 'Scanner reads this value. Must be unique.')) + '</small><em></em></label>' +
        '<div class="mg-f"><span>' + esc(t('pr_label_prev', 'Label preview')) + ' · 58mm</span><div class="pr-prev" id="pf-prev"></div></div>' +
        '<div class="pr-two">' + field('sku', 'SKU', r.sku) + field('upc', 'UPC', r.upc) + '</div>';

      body = '<div class="pr-tabs" role="tablist"><button type="button" data-tab="general" class="on">' + ic('file-earmark-text') + '<span>' + esc(t('pr_general', 'General')) + '</span></button><button type="button" data-tab="barcode">' + ic('upc') + '<span>' + esc(t('pr_barcode', 'Barcode')) + '</span></button></div>' +
        '<div class="pr-sec on" data-s="general">' + general + '</div><div class="pr-sec" data-s="barcode">' + barcode + '</div>';
    } else {
      title = '<span class="mg-who"><span class="pr-pth">' + ic('plus-lg') + '</span><span>' + esc(t('pr_add', 'Add product')) + '<small>' + esc(t('pr_add_note', 'Only the essentials. Everything else can be edited later.')) + '</small></span></span>';
      body = '<div class="pr-sec on">' + general + field('sku', 'SKU', '') +
        '<label class="pr-chk"><input type="checkbox" id="pf-gen" checked><span>' + esc(t(P.source === 'model' ? 'pr_gen_from_model' : 'pr_gen_from_sku', P.source === 'model' ? 'Create barcode from Model when saving' : 'Create barcode from SKU when saving')) + '</span></label></div>';
    }

    $('pr-dt').innerHTML = title;
    $('pr-db').innerHTML = body;
    $('pr-df').innerHTML = '<button class="btn" type="button" data-act="cancel">' + esc(B.t('cancel', 'Cancel')) + '</button>' +
      '<button class="btn pri" type="button" data-act="save">' + ic('check-lg') + '<span>' + esc(drawer.id ? t('pr_save', 'Save') : t('pr_add', 'Add product')) + '</span></button>';

    $('pr-scrim').hidden = false;
    $('pr-drawer').hidden = false;

    if (drawer.id) { preview(); gotoTab(drawer.tab); }

    var first = $('pf-name');
    if (first && !drawer.id) { first.focus(); }
  }

  function gotoTab(k) {
    drawer.tab = k;
    Array.prototype.forEach.call($('pr-db').querySelectorAll('.pr-tabs button'), function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === k); });
    Array.prototype.forEach.call($('pr-db').querySelectorAll('.pr-sec'), function (s) { s.classList.toggle('on', s.getAttribute('data-s') === k); });
  }

  function preview() {
    var el = $('pf-prev');
    var v = $('pf-barcode') ? $('pf-barcode').value.trim() : '';
    if (!el) { return; }

    el.innerHTML = v ? (code128(v, 60) || '') + '<small>' + esc(v) + '</small>' : '<small>' + esc(t('pr_no_barcode', 'No barcode')) + '</small>';
    var svg = el.querySelector('svg');
    if (svg) { svg.style.width = '100%'; svg.style.height = '70px'; }
  }

  function closeDrawer(force) {
    if (!force && dirty) {
      B.ui.confirm(B.t('discard', 'Discard unsaved changes?'), B.t('leave', 'Leave')).then(function (ok) { if (ok) { closeDrawer(true); } });
      return;
    }

    drawer = null;
    dirty = false;
    S.open = null;
    $('pr-scrim').hidden = true;
    $('pr-drawer').hidden = true;
  }

  function val(id) { var e = $('pf-' + id); return e ? e.value : ''; }

  function save() {
    var btn = $('pr-df').querySelector('[data-act="save"]');
    if (btn.disabled) { return; }

    var adding = !drawer.id;
    var sku = val('sku').trim();
    var barcode = adding ? '' : val('barcode').trim();

    if (adding && $('pf-gen') && $('pf-gen').checked) {
      barcode = P.source === 'model' ? val('model').trim() : sku;
    }

    var data = {
      id: drawer.id,
      name: val('name'), model: val('model'), sku: sku, upc: adding ? '' : val('upc'), barcode: barcode,
      category_id: val('category_id'), price: val('price'), quantity: val('quantity'),
      pos: $('pf-pos') ? $('pf-pos').getAttribute('aria-checked') === 'true' : true
    };

    btn.disabled = true;
    Array.prototype.forEach.call($('pr-db').querySelectorAll('.mg-f'), function (f) { f.classList.remove('bad'); var em = f.querySelector('em'); if (em) { em.textContent = ''; } });

    B.api(P.urls.save, { data: data }).then(function () {
      closeDrawer(true);
      B.ui.toast(adding ? t('pr_added', 'Product added') : t('pr_saved', 'Saved'));
      load(true);
    }).catch(function (e) {
      btn.disabled = false;

      var shown = false;
      var tab = '';

      Object.keys(e.fields || {}).forEach(function (k) {
        var f = $('pr-db').querySelector('[data-f="' + k + '"]');
        if (f) {
          f.classList.add('bad');
          f.querySelector('em').textContent = e.fields[k];
          shown = true;
          if (!tab) { tab = (k === 'barcode' || k === 'sku' || k === 'upc') ? 'barcode' : 'general'; }
        }
      });

      if (shown && !adding) { gotoTab(tab); }
      if (!shown || (adding && e.fields && e.fields.barcode)) { B.ui.toast(e.message, 'err'); }
    });
  }

  /* ---------- events ---------- */
  function resetFilters() { S.cat = 0; S.stock = ''; S.bc = ''; }

  function onClick(e) {
    var x;

    if ((x = e.target.closest('[data-kp]'))) {
      var k = x.getAttribute('data-kp');
      S.pos = ''; S.bc = '';
      if (k === 'yes' || k === 'no') { S.pos = k; }
      if (k === 'attn') { S.bc = 'attn'; }
      fillFilters();
      load(false);
      return;
    }

    if ((x = e.target.closest('[data-unchip]'))) {
      var u = x.getAttribute('data-unchip');
      if (u === 'all') { resetFilters(); } else if (u === 'cat') { S.cat = 0; } else if (u === 'stock') { S.stock = ''; } else { S.bc = ''; }
      fillFilters();
      load(false);
      return;
    }

    if (e.target.closest('#pr-fbtn')) { S.fopen = !S.fopen; renderBar(); return; }
    if (e.target.closest('#pr-reset')) { resetFilters(); fillFilters(); load(false); return; }

    if ((x = e.target.closest('[data-pg]'))) { S.page = Math.max(1, Math.min(S.pages, S.page + Number(x.getAttribute('data-pg')))); load(true); return; }

    if ((x = e.target.closest('th[data-sort]'))) {
      var key = x.getAttribute('data-sort');
      S.dir = S.sort === key && S.dir === 'asc' ? 'desc' : 'asc';
      S.sort = key;
      load(false);
      return;
    }

    if (e.target.closest('#pr-all')) {
      var on = $('pr-all').checked;
      S.rows.forEach(function (r) { if (on) { S.sel[r.id] = true; } else { delete S.sel[r.id]; } });
      render();
      return;
    }

    if ((x = e.target.closest('[data-sel]'))) {
      var sid = Number(x.getAttribute('data-sel'));
      if (x.checked) { S.sel[sid] = true; } else { delete S.sel[sid]; }
      render();
      return;
    }

    if ((x = e.target.closest('[data-bulk]'))) {
      var v = x.getAttribute('data-bulk');
      var ids = selected();
      if (v === 'none') { S.sel = {}; render(); }
      else if (v === 'show' || v === 'hide') { setPos(ids, v === 'show'); }
      else if (v === 'gen') { generate(ids); }
      else if (v === 'print') { printLabels(ids); }
      return;
    }

    if (e.target.closest('#pr-dx') || e.target === $('pr-scrim')) { closeDrawer(false); return; }

    if (drawer && (x = e.target.closest('[data-act]'))) {
      var da = x.getAttribute('data-act');
      if (da === 'cancel') { closeDrawer(false); return; }
      if (da === 'save') { save(); return; }
    }

    if (drawer && (x = e.target.closest('.pr-tabs [data-tab]'))) { gotoTab(x.getAttribute('data-tab')); return; }

    if (drawer && (x = e.target.closest('[data-use]'))) {
      $('pf-barcode').value = val(x.getAttribute('data-use') === 'sku' ? 'sku' : 'model').trim();
      markDirty();
      preview();
      return;
    }

    if (drawer && e.target.closest('#pf-pos')) {
      var sw = $('pf-pos');
      sw.setAttribute('aria-checked', sw.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
      markDirty();
      return;
    }

    if ((x = e.target.closest('[data-act]'))) {
      var act = x.getAttribute('data-act');
      var id = Number(x.getAttribute('data-id'));
      var r = rowOf(id);

      if (act === 'menu') { e.stopPropagation(); openMenu(x, id); return; }
      if (act === 'edit' && r) { openDrawer(r, 'general'); return; }
      if (act === 'gen') { generate([id]); return; }
      if (act === 'pos' && r) { setPos([id], !r.pos); return; }
    }

    if ((x = e.target.closest('tr[data-open]')) && P.can.edit && !e.target.closest('button,input,a,label,select')) {
      openDrawer(rowOf(Number(x.getAttribute('data-open'))), 'general');
    }
  }

  function onDocClick(e) {
    if (!menu) { return; }

    var m = e.target.closest ? e.target.closest('[data-m]') : null;

    if (m && menu.contains(m)) {
      var act = m.getAttribute('data-m');
      var id = Number(m.getAttribute('data-id'));
      var r = rowOf(id);
      closeMenu();

      if (act === 'edit' && r) { openDrawer(r, m.getAttribute('data-tab') || 'general'); }
      else if (act === 'gen') { generate([id]); }
      else if (act === 'label') { printLabels([id]); }
      else if (act === 'pos' && r) { setPos([id], !r.pos); }
      return;
    }

    if (!e.target.closest('[data-act="menu"]')) { closeMenu(); }
  }

  function onKey(e) {
    if (e.key === 'Escape') {
      if (menu) { closeMenu(); }
      else if (drawer && !$('bp-ov').children.length) { closeDrawer(false); }
    }

    if ((e.key === 'Enter' || e.key === ' ') && P.can.edit && e.target.matches && e.target.matches('tr[data-open]')) {
      e.preventDefault();
      openDrawer(rowOf(Number(e.target.getAttribute('data-open'))), 'general');
    }
  }

  function onInput(e) {
    if (!drawer || !e.target.id || e.target.id.indexOf('pf-') !== 0) { return; }
    markDirty();
    if (e.target.id === 'pf-barcode') { preview(); }
  }

  root.addEventListener('click', onClick);
  root.addEventListener('input', onInput);
  root.addEventListener('change', onInput);
  d.addEventListener('click', onDocClick);
  d.addEventListener('keydown', onKey);

  $('pr-q').addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { S.q = $('pr-q').value.trim(); load(false); }, 250);
  });

  $('pr-cat').addEventListener('change', function () { S.cat = Number($('pr-cat').value); load(false); });
  $('pr-stock').addEventListener('change', function () { S.stock = $('pr-stock').value; load(false); });
  $('pr-bc').addEventListener('change', function () { S.bc = $('pr-bc').value; load(false); });
  $('pr-per').addEventListener('change', function () { S.per = Number($('pr-per').value); load(false); });

  if ($('pr-new')) { $('pr-new').addEventListener('click', function () { openDrawer(null); }); }
  if ($('pr-gen')) { $('pr-gen').addEventListener('click', function () { generate([]); }); }
  $('pr-print').addEventListener('click', function () { printLabels(selected()); });

  B.setDirty(function () { return !!(drawer && dirty); });

  B.onLeave(function () {
    if (ctl) { ctl.abort(); }
    clearTimeout(timer);
    closeMenu();
    d.removeEventListener('click', onDocClick);
    d.removeEventListener('keydown', onKey);
  });

  fillFilters();
  load(false);
})(window, document, window.BPOS);
