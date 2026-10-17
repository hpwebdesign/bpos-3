/* BPOS Customers page: list, detail drawer, notes, add and edit, start a sale for a customer. */
(function (w, d, B) {
  var root = d.getElementById('cu-root');
  var dataEl = d.getElementById('cu-data');
  if (!root || !dataEl) { return; }

  var P = JSON.parse(dataEl.textContent);
  var $ = function (id) { return d.getElementById(id); };
  var esc = B.esc;
  var t = function (k, f) { return B.t(k, f); };
  var ic = function (n) { return '<i class="bi bi-' + n + '"></i>'; };
  var lang = d.documentElement.lang || undefined;

  var S = { q: '', sort: 'name', dir: 'asc', page: 1, pages: 1, total: 0, rows: [], loaded: false, open: 0 };
  var ctl = null;
  var timer = null;
  var current = null;

  function url(base, qs) { return base + (base.indexOf('?') > -1 ? '&' : '?') + qs; }

  function initials(name) {
    var p = String(name || '?').trim().split(/\s+/);
    return (p[0].charAt(0) + (p[1] ? p[1].charAt(0) : '')).toUpperCase();
  }

  function fmtDate(s, time) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(s || '');
    if (!m) { return ''; }

    try {
      var opt = { day: 'numeric', month: 'short', year: 'numeric' };
      if (time && m[4]) { opt.hour = '2-digit'; opt.minute = '2-digit'; }
      return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)).toLocaleString(lang, opt);
    } catch (e) { return s; }
  }

  /* ---------- list ---------- */
  function skeleton() {
    var out = '';
    for (var i = 0; i < 8; i++) {
      out += '<tr aria-hidden="true">' + [150, 90, 40, 70, 90].map(function (wd, k) { return '<td' + (k === 1 || k === 4 ? ' class="hm"' : (k === 2 ? ' class="hs"' : '')) + '><span class="sk" style="width:' + wd + 'px"></span></td>'; }).join('') + '</tr>';
    }
    $('cu-body').innerHTML = out;
  }

  function load(keepPage) {
    if (ctl) { ctl.abort(); }
    ctl = new AbortController();

    if (!keepPage) { S.page = 1; }

    $('cu-card').classList.add('busy');
    if (!S.loaded) { skeleton(); }

    B.api(url(P.urls.data, 'q=' + encodeURIComponent(S.q) + '&sort=' + S.sort + '&dir=' + S.dir + '&page=' + S.page), { signal: ctl.signal }).then(function (r) {
      S.loaded = true;
      S.rows = r.rows; S.page = r.page; S.pages = r.pages; S.total = r.total;
      $('cu-card').classList.remove('busy');
      render();
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      $('cu-card').classList.remove('busy');
      B.ui.toast(e.message, 'err');
    });
  }

  function render() {
    $('cu-body').innerHTML = S.rows.length ? S.rows.map(function (r) {
      return '<tr data-open="' + r.id + '" tabindex="0">' +
        '<td><div class="mg-who"><span class="av sm">' + esc(initials(r.name)) + '</span><span>' + esc(r.name) + '<small>' + esc(r.email || '') + '</small></span></div></td>' +
        '<td class="hm">' + esc(r.phone || '–') + '</td>' +
        '<td class="num hs">' + r.orders + '</td>' +
        '<td class="num">' + esc(r.spent) + '</td>' +
        '<td class="hm">' + (r.last_date ? esc(fmtDate(r.last_date)) + (r.last_store ? '<small>' + esc(r.last_store) + '</small>' : '') : '<span style="color:var(--mute)">–</span>') + '</td></tr>';
    }).join('') : '<tr><td colspan="5"><div class="mg-empty">' + esc(t('cu_empty', 'No customer found.')) + '</div></td></tr>';

    Array.prototype.forEach.call(root.querySelectorAll('th[data-sort]'), function (th) {
      var on = th.getAttribute('data-sort') === S.sort;
      th.setAttribute('aria-sort', on ? (S.dir === 'asc' ? 'ascending' : 'descending') : 'none');
      th.innerHTML = th.textContent.replace(/\s+$/, '') + (on ? ' ' + ic(S.dir === 'asc' ? 'caret-up-fill' : 'caret-down-fill') : '');
    });

    var from = S.total ? (S.page - 1) * P.size + 1 : 0;
    var to = Math.min(S.total, S.page * P.size);

    $('cu-foot').innerHTML = '<span>' + esc(from + '–' + to + ' / ' + S.total) + '</span>' +
      '<span class="mg-pg"><button type="button" data-pg="-1" aria-label="' + esc(t('od_prev', 'Previous page')) + '"' + (S.page <= 1 ? ' disabled' : '') + '>' + ic('chevron-left') + '</button>' +
      '<span>' + S.page + ' / ' + S.pages + '</span>' +
      '<button type="button" data-pg="1" aria-label="' + esc(t('od_next', 'Next page')) + '"' + (S.page >= S.pages ? ' disabled' : '') + '>' + ic('chevron-right') + '</button></span>';
  }

  /* ---------- drawer ---------- */
  function openDrawer(id) {
    S.open = id;
    $('cu-dt').textContent = '';
    $('cu-db').innerHTML = '<span class="sk" style="width:60%"></span><span class="sk" style="width:90%"></span><span class="sk" style="width:75%"></span>';
    $('cu-df').innerHTML = '';
    $('cu-scrim').hidden = false;
    $('cu-drawer').hidden = false;
    $('cu-dx').focus();

    B.api(url(P.urls.view, 'customer_id=' + id)).then(function (r) {
      if (S.open !== id) { return; }
      current = r.customer;
      renderDrawer();
    }).catch(function (e) {
      B.ui.toast(e.message, 'err');
      closeDrawer();
    });
  }

  function closeDrawer() {
    S.open = 0;
    current = null;
    $('cu-scrim').hidden = true;
    $('cu-drawer').hidden = true;
  }

  function renderNotes(notes) {
    return (notes.length ? notes.map(function (n) {
      return '<li><span>' + esc(n.note) + '</span><small>' + esc((n.author || t('cu_admin', 'Admin')) + ' · ' + fmtDate(n.date, true)) + '</small></li>';
    }).join('') : '<li><small>' + esc(t('cu_no_notes', 'No notes yet.')) + '</small></li>');
  }

  function renderDrawer() {
    var c = current;

    $('cu-dt').innerHTML = '<span class="mg-who"><span class="av">' + esc(initials(c.name)) + '</span><span>' + esc(c.name) + (c.group ? '<small>' + esc(c.group) + (c.active ? '' : ' · ' + esc(t('cu_disabled', 'Disabled'))) + '</small>' : '') + '</span></span>';

    var info = '<dl class="mg-kv"><dt>' + esc(t('cu_email', 'Email')) + '</dt><dd>' + esc(c.email || '–') + '</dd>' +
      '<dt>' + esc(t('sl_phone', 'Phone')) + '</dt><dd>' + esc(c.phone || '–') + '</dd>' +
      '<dt>' + esc(t('cu_address', 'Address')) + '</dt><dd>' + esc(c.address || '–') + '</dd>' +
      '<dt>' + esc(t('cu_since', 'Customer since')) + '</dt><dd>' + esc(fmtDate(c.since) || '–') + '</dd></dl>';

    var stats = '<div class="cu-stats"><div><small>' + esc(t('cu_orders', 'Orders')) + '</small><b>' + c.orders + '</b></div><div><small>' + esc(t('cu_spent', 'Total spent')) + '</small><b>' + esc(c.spent) + '</b></div><div><small>' + esc(t('cu_last', 'Last purchase')) + '</small><b>' + (c.last_date ? esc(fmtDate(c.last_date)) : '–') + '</b>' + (c.last_store ? '<small>' + esc(c.last_store) + '</small>' : '') + '</div></div>';

    var recent = c.recent.length ? '<div><h3 class="mg-sec">' + esc(t('cu_recent', 'Recent orders')) + '</h3>' + c.recent.map(function (o) {
      return '<div class="mg-li"><b>#' + o.id + '</b><span class="num">' + esc(o.total) + '</span><small>' + esc(fmtDate(o.date, true)) + ' · ' + esc(o.status) + '</small></div>';
    }).join('') + '</div>' : '';

    var notes = '<div><h3 class="mg-sec">' + esc(t('cu_notes', 'Notes')) + '</h3>' +
      (P.can.edit ? '<div class="cu-addnote"><label class="mg-f" id="cu-nf"><textarea id="cu-note" maxlength="500" placeholder="' + esc(t('cu_note_ph', 'Internal note, not shown to the customer')) + '" aria-label="' + esc(t('cu_notes', 'Notes')) + '"></textarea><em></em></label><button class="btn" type="button" data-act="note">' + esc(t('cu_add_note', 'Add note')) + '</button></div>' : '') +
      '<ul class="mg-notes" id="cu-notes">' + renderNotes(c.notes) + '</ul></div>';

    $('cu-db').innerHTML = stats + info + recent + notes;

    $('cu-df').innerHTML = (P.can.sell ? '<button class="btn pri" type="button" data-act="sell">' + ic('bag-check') + '<span>' + esc(t('cu_start_sale', 'Start sale')) + '</span></button>' : '') +
      (P.can.edit ? '<button class="btn" type="button" data-act="edit">' + ic('pencil') + '<span>' + esc(t('cu_edit', 'Edit customer')) + '</span></button>' : '');
  }

  // Hands the customer to the Sales page through its saved cart
  function startSale() {
    var key = 'bpos:' + P.cartKey;
    var saved = {};

    try { saved = JSON.parse(B.store.get(key) || '{}') || {}; } catch (e) { saved = {}; }

    if (!Array.isArray(saved.cart)) { saved.cart = []; }

    saved.customer = { id: current.id, name: current.name, phone: current.phone, email: current.email };
    B.store.set(key, JSON.stringify(saved));
    B.router.go(P.urls.sales);
  }

  function addNote() {
    var box = $('cu-note');
    var text = box.value.trim();
    var wrap = $('cu-nf');

    if (!text) { wrap.classList.add('bad'); wrap.querySelector('em').textContent = t('cu_note_empty', 'Write a note first.'); return; }

    wrap.classList.remove('bad');
    wrap.querySelector('em').textContent = '';

    B.api(P.urls.note, { data: { customer_id: current.id, note: text } }).then(function (r) {
      current.notes = r.notes;
      box.value = '';
      $('cu-notes').innerHTML = renderNotes(r.notes);
    }).catch(function (e) { B.ui.toast(e.message, 'err'); });
  }

  /* ---------- add and edit ---------- */
  function form(c) {
    var edit = !!c;
    c = c || { id: 0, name: '', phone: '', email: '', address_1: '' };

    B.ui.modal(function (box, done) {
      function field(id, label, value, type, ph) {
        return '<label class="mg-f" data-f="' + id + '">' + esc(label) + '<input id="cf-' + id + '" type="' + type + '" value="' + esc(value) + '" placeholder="' + esc(ph || '') + '" autocomplete="off"><em></em></label>';
      }

      box.innerHTML = '<h2>' + esc(edit ? t('cu_edit', 'Edit customer') : t('sl_new_customer', 'New customer')) + '</h2>' +
        field('name', t('sl_full_name', 'Full name'), c.name, 'text') +
        field('phone', t('sl_phone', 'Phone'), c.phone, 'tel', t('sl_phone_ph', '')) +
        field('email', t('sl_email_opt', 'Email (optional)'), c.email, 'email') +
        field('address', t('cu_address_opt', 'Address (optional)'), c.address_1, 'text') +
        '<div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn" type="button" data-no>' + esc(B.t('cancel', 'Cancel')) + '</button><button class="btn pri" type="button" data-yes>' + esc(t('cu_save', 'Save customer')) + '</button></div>';

      var saving = false;
      var yes = box.querySelector('[data-yes]');

      function val(id) { return box.querySelector('#cf-' + id).value; }

      function submit() {
        if (saving) { return; }
        saving = true;
        yes.disabled = true;

        Array.prototype.forEach.call(box.querySelectorAll('.mg-f'), function (f) { f.classList.remove('bad'); f.querySelector('em').textContent = ''; });

        B.api(P.urls.save, { data: { id: c.id, name: val('name'), phone: val('phone'), email: val('email'), address: val('address') } }).then(function (r) {
          done(r.customer);
        }).catch(function (e) {
          saving = false;
          yes.disabled = false;

          var shown = false;
          Object.keys(e.fields || {}).forEach(function (k) {
            var f = box.querySelector('[data-f="' + k + '"]');
            if (f) { f.classList.add('bad'); f.querySelector('em').textContent = e.fields[k]; shown = true; }
          });

          if (!shown) { B.ui.toast(e.message, 'err'); }
        });
      }

      box.querySelector('[data-no]').addEventListener('click', function () { done(null); });
      yes.addEventListener('click', submit);
      box.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); submit(); } });
    }, true).then(function (saved) {
      if (!saved) { return; }

      B.ui.toast(t(edit ? 'cu_saved' : 'sl_customer_added', edit ? 'Customer saved' : 'Customer added'));
      load(true);
      current = saved;
      S.open = saved.id;
      $('cu-scrim').hidden = false;
      $('cu-drawer').hidden = false;
      renderDrawer();
    });
  }

  /* ---------- export ---------- */
  function csvCell(v) {
    v = v == null ? '' : String(v);
    if (/^[=+\-@\t\r]/.test(v)) { v = "'" + v; }
    return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }

  function exportCsv() {
    var btn = $('cu-export');
    btn.disabled = true;

    B.api(url(P.urls.export, 'q=' + encodeURIComponent(S.q))).then(function (r) {
      var lines = ['customer_id,name,email,phone,orders,spent,currency,last_purchase,last_store'].concat(r.rows.map(function (x) {
        return [x.id, x.name, x.email, x.phone, x.orders, x.spent, x.currency, x.last_date, x.last_store].map(csvCell).join(',');
      }));

      var a = d.createElement('a');
      a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
      a.download = 'customers.csv';
      d.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); d.body.removeChild(a); }, 500);

      if (r.truncated) { B.ui.toast(t('cu_export_cut', 'Export is limited to 5000 customers.')); }
    }).catch(function (e) { B.ui.toast(e.message, 'err'); }).then(function () { btn.disabled = false; });
  }

  /* ---------- events ---------- */
  function onClick(e) {
    var x;

    if ((x = e.target.closest('[data-pg]'))) { S.page = Math.max(1, Math.min(S.pages, S.page + Number(x.getAttribute('data-pg')))); load(true); return; }

    if ((x = e.target.closest('th[data-sort]'))) {
      var key = x.getAttribute('data-sort');
      S.dir = S.sort === key && S.dir === 'asc' ? 'desc' : 'asc';
      S.sort = key;
      load(false);
      return;
    }

    if ((x = e.target.closest('tr[data-open]'))) { openDrawer(Number(x.getAttribute('data-open'))); return; }
    if (e.target.closest('#cu-dx') || e.target === $('cu-scrim')) { closeDrawer(); return; }

    if ((x = e.target.closest('[data-act]')) && current) {
      var act = x.getAttribute('data-act');
      if (act === 'sell') { startSale(); }
      if (act === 'edit') { form(current); }
      if (act === 'note') { addNote(); }
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

  $('cu-q').addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { S.q = $('cu-q').value.trim(); load(false); }, 250);
  });

  if ($('cu-new')) { $('cu-new').addEventListener('click', function () { form(null); }); }
  if ($('cu-export')) { $('cu-export').addEventListener('click', exportCsv); }

  B.onLeave(function () {
    if (ctl) { ctl.abort(); }
    clearTimeout(timer);
    d.removeEventListener('keydown', onKey);
  });

  load(false);
})(window, document, window.BPOS);
