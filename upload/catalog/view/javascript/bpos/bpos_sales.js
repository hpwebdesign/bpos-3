/* BPOS Sales page: catalog, cart, option picker, checkout. Prices always come from the server (quote). */
(function (w, d, B) {
  var root = d.getElementById('sl-root');
  var dataEl = d.getElementById('sl-data');
  if (!root || !dataEl) { return; }

  B.mountTop(root);

  var P = JSON.parse(dataEl.textContent);
  var $ = function (id) { return d.getElementById(id); };
  var esc = B.esc;
  var t = function (k, f) { return B.t(k, f); };
  var ic = function (n) { return '<i class="bi bi-' + n + '"></i>'; };

  var KEY = 'bpos:' + P.cartKey;
  var LAYOUT_KEY = 'bpos:sl-layout';
  var nf = null;

  try {
    nf = new Intl.NumberFormat(d.documentElement.lang || undefined, { style: 'currency', currency: P.currency.code, minimumFractionDigits: P.currency.decimals, maximumFractionDigits: P.currency.decimals });
  } catch (e) { nf = null; }

  function money(n) {
    n = Number(n) || 0;
    if (nf) { return nf.format(n); }
    return P.currency.code + ' ' + n.toFixed(P.currency.decimals);
  }

  function plural(n) { return n + ' ' + t(n === 1 ? 'sl_item' : 'sl_items', n === 1 ? 'item' : 'items'); }

  /* ---------- state ---------- */
  var S = {
    cat: 0, q: '', page: 1, pages: 1, total: 0, allTotal: null, layout: B.store.get(LAYOUT_KEY) === 'gallery' ? 'gallery' : 'compact',
    products: [], byId: {},
    cart: [], customer: null, discount: null, charge: 0, coupon: '', shipping: '', pay: 'cash', tender: 0,
    open: null, view: null, pending: false, paying: false, rid: '', couponErr: '', newc: null, cq: '', clist: null, draft: { type: '%', val: '' }
  };

  try {
    var saved = JSON.parse(B.store.get(KEY) || 'null');
    if (saved && Array.isArray(saved.cart)) {
      S.cart = saved.cart.filter(function (c) { return c && c.pid && c.qty > 0; });
      S.customer = saved.customer || null;
      S.discount = saved.discount || null;
      S.charge = Number(saved.charge) || 0;
      S.coupon = saved.coupon || '';
      S.shipping = saved.shipping || '';
      S.pay = saved.pay || 'cash';
      S.rid = saved.rid || '';
    }
  } catch (e) { /* ignore a broken saved cart */ }

  function persist() {
    B.store.set(KEY, JSON.stringify({ cart: S.cart, customer: S.customer, discount: S.discount, charge: S.charge, coupon: S.coupon, shipping: S.shipping, pay: S.pay, rid: S.rid }));
  }

  function uid() {
    var a = new Uint8Array(12);
    (w.crypto || w.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }

  function lineKey(pid, opt) {
    var keys = Object.keys(opt || {}).sort();
    return pid + '|' + keys.map(function (k) {
      var v = opt[k];
      return k + '=' + (Array.isArray(v) ? v.slice().sort().join('+') : v);
    }).join(',');
  }

  function inCart(pid) {
    return S.cart.reduce(function (n, c) { return n + (c.pid === pid ? c.qty : 0); }, 0);
  }

  /* ---------- catalog ---------- */
  var catCtl = null;

  var skTimer = null;

  function stopLoading() {
    clearTimeout(skTimer);
    $('sl-grid').classList.remove('ld');
    $('sl-grid').removeAttribute('aria-busy');
  }

  // Dim the old results at once, show skeleton cards only when the request is slow
  function startLoading() {
    var grid = $('sl-grid');
    var n = S.layout === 'compact' ? 6 : 10;
    var sk = '';

    for (var i = 0; i < n; i++) {
      sk += '<div class="card skc" aria-hidden="true"><span class="sk skimg"></span><span class="sk" style="width:72%"></span><span class="sk" style="width:38%"></span></div>';
    }

    grid.classList.add('ld');
    grid.setAttribute('aria-busy', 'true');
    clearTimeout(skTimer);
    skTimer = setTimeout(function () { grid.innerHTML = sk; }, 140);
  }

  function loadCatalog(more) {
    if (catCtl) { catCtl.abort(); }
    catCtl = new AbortController();

    if (!more) {
      S.page = 1;
      renderPills();
      startLoading();
    }

    var url = P.urls.catalog + (P.urls.catalog.indexOf('?') > -1 ? '&' : '?') + 'category_id=' + S.cat + '&page=' + S.page + '&q=' + encodeURIComponent(S.q);

    B.api(url, { signal: catCtl.signal }).then(function (r) {
      stopLoading();
      S.pages = r.pages;
      S.total = r.total;

      if (S.cat === 0 && S.q === '') { S.allTotal = r.total; }

      if (more) { S.products = S.products.concat(r.products); } else { S.products = r.products; }

      r.products.forEach(function (p) { S.byId[p.id] = p; });
      renderCatalog();
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      stopLoading();
      B.ui.toast(e.message, 'err');
    });
  }

  function renderPills() {
    var all = S.allTotal !== null ? S.allTotal : P.categories.reduce(function (n, c) { return n + c.total; }, 0);
    var list = [{ category_id: 0, name: t('sl_all', 'All'), total: all }].concat(P.categories);

    $('sl-pills').innerHTML = list.map(function (c) {
      return '<button class="catc' + (c.category_id === S.cat ? ' on' : '') + '" type="button" data-cat="' + c.category_id + '" role="tab" aria-selected="' + (c.category_id === S.cat) + '"><span class="ci">' + ic(c.category_id ? 'tag' : 'grid') + '</span><span><b>' + esc(c.name) + '</b><small>' + plural(c.total) + '</small></span></button>';
    }).join('');
  }

  function renderCatalog() {
    renderPills();

    var cat = P.categories.filter(function (c) { return c.category_id === S.cat; })[0];
    $('sl-ph').innerHTML = esc(S.q ? t('sl_results', 'Results') : (cat ? cat.name : t('sl_products', 'Products'))) + ' <small>' + plural(S.total) + '</small>';

    var grid = $('sl-grid');
    grid.className = 'grid ' + S.layout;

    if (!S.products.length) {
      grid.innerHTML = '<div class="empty">' + esc(t('sl_no_match', 'No product matches')) + (S.q ? ' "' + esc(S.q) + '"' : '') + '.</div>';
      return;
    }

    var html = S.products.map(function (p) {
      var have = inCart(p.id);
      var left = p.qty === null ? null : p.qty - have;
      var blocked = p.out || p.blocked || (left !== null && left <= 0 && !P.negStock);
      var thumb = p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : ic('box-seam');
      var tag = '';

      if (p.blocked) { tag = t('sl_not_sellable', 'Not available in POS'); }
      else if (p.out) { tag = t('sl_out', 'Out of stock'); }
      else if (p.low) { tag = t('sl_only', 'Only') + ' ' + (left === null ? p.qty : Math.max(left, 0)) + ' ' + t('sl_left', 'left'); }

      var price = (p.options ? '<span class="from">' + esc(t('sl_from', 'from')) + '</span> ' : '') + money(p.price) + (p.old !== null ? '<span class="old">' + money(p.old) + '</span>' : '');
      var optb = p.options ? '<span class="optb">' + ic('sliders2') + esc(t('sl_options', 'Options')) + '</span>' : '';
      var badge = have ? '<span class="qtybadge">' + have + '</span>' : '';

      if (S.layout === 'compact') {
        return '<div class="cc' + (have ? ' sel' : '') + (blocked ? ' dis' : '') + '"' + (blocked ? '' : ' data-add="' + p.id + '" role="button" tabindex="0"') + '><div class="cthumb">' + thumb + '</div><div class="cinfo"><div class="nm">' + esc(p.name) + '</div>' +
          (tag ? '<div class="ctag' + (p.out || p.blocked ? ' out' : '') + '">' + esc(tag) + '</div>' : '') +
          '<div class="crow"><span class="price">' + price + '</span>' + optb + '</div></div>' + badge + '</div>';
      }

      return '<button class="card" type="button" data-add="' + p.id + '"' + (blocked ? ' disabled' : '') + '>' + (tag ? '<span class="tag' + (p.out || p.blocked ? ' out' : '') + '">' + esc(tag) + '</span>' : '') + badge +
        '<div class="thumb">' + thumb + '</div><div class="nm">' + esc(p.name) + '</div><div class="row"><span class="price">' + price + '</span>' + (p.options ? optb : '<span class="sku">' + esc(p.sku || p.model) + '</span>') + '</div></button>';
    }).join('');

    if (S.page < S.pages) {
      html += '<button class="btn more" type="button" data-more>' + esc(t('sl_more', 'Load more')) + '</button>';
    }

    grid.innerHTML = html;
  }

  /* ---------- quote ---------- */
  var quoteTimer = null;
  var quoteSeq = 0;

  function payload() {
    var guest = S.customer && !S.customer.id ? { name: S.customer.name, phone: S.customer.phone, email: S.customer.email } : null;

    return {
      items: S.cart.map(function (c) { return { product_id: c.pid, quantity: c.qty, option: c.opt || {} }; }),
      customer_id: S.customer && S.customer.id ? S.customer.id : 0,
      guest: guest,
      discount: S.discount,
      charge: S.charge,
      coupon: S.coupon,
      shipping: S.shipping
    };
  }

  function refresh(now) {
    persist();
    clearTimeout(quoteTimer);

    if (!S.cart.length) {
      quoteSeq++;
      S.view = null;
      S.pending = false;
      renderOrder();
      renderCatalog();
      return;
    }

    S.pending = true;
    renderOrder();
    quoteTimer = setTimeout(doQuote, now ? 0 : 140);
  }

  function doQuote() {
    var mine = ++quoteSeq;

    B.api(P.urls.quote, { data: payload() }).then(function (r) {
      if (mine !== quoteSeq) { return; }

      S.view = r.cart;
      S.pending = false;

      if (r.cart.coupon_error) {
        S.couponErr = S.coupon;
        S.coupon = '';
        persist();
        S.open = 'coupon';
        S.view = null;
        refresh(true);
        return;
      }

      if (r.cart.shipping.selected) { S.shipping = r.cart.shipping.selected; }

      var codes = r.cart.payments.map(function (m) { return m.code; });

      if (codes.length && codes.indexOf(S.pay) === -1) { S.pay = codes[0]; S.tender = 0; }

      persist();
      renderOrder();
      renderCatalog();
    }).catch(function (e) {
      if (mine !== quoteSeq) { return; }
      S.pending = false;

      if (e.status === 403) {
        // Not allowed to use a discount, charge or coupon: drop them
        S.discount = null; S.charge = 0; S.coupon = '';
        B.ui.toast(e.message, 'err');
        refresh(true);
        return;
      }

      B.ui.toast(e.message, 'err');
      renderOrder();
    });
  }

  /* ---------- cart ---------- */
  function addLine(p, qty, opt, hasOpts) {
    var key = lineKey(p.id, opt);
    var idx = -1;

    S.cart.forEach(function (c, i) { if (lineKey(c.pid, c.opt) === key) { idx = i; } });

    if (p.qty !== null && !P.negStock && inCart(p.id) + qty > p.qty) {
      B.ui.toast(t('sl_no_more', 'No more stock for this item.'), 'err');
      return false;
    }

    if (idx > -1) { S.cart[idx].qty += qty; }
    else { S.cart.push({ pid: p.id, qty: qty, opt: opt || {}, name: p.name, hasOpts: !!hasOpts }); }

    S.rid = S.rid || '';
    refresh(true);

    return true;
  }

  function pick(p) {
    if (p.options) {
      openPicker(p, null);
    } else if (addLine(p, 1, {}, false)) {
      B.ui.toast(p.name + ' ' + t('sl_added', 'added'));
    }
  }

  /* ---------- option picker ---------- */
  function openPicker(p, editIndex) {
    B.api(P.urls.product + (P.urls.product.indexOf('?') > -1 ? '&' : '?') + 'product_id=' + p.id).then(function (r) {
      var existing = editIndex !== null ? S.cart[editIndex].opt : null;
      var sel = {};

      r.options.forEach(function (g) {
        if (g.type === 'checkbox') {
          sel[g.id] = existing && existing[g.id] ? [].concat(existing[g.id]).map(Number) : [];
        } else if (existing && existing[g.id]) {
          sel[g.id] = Number(existing[g.id]);
        } else if (g.required) {
          var v = g.values.filter(function (x) { return !x.out; })[0];
          sel[g.id] = v ? v.id : 0;
        } else {
          sel[g.id] = 0;
        }
      });

      function price() {
        var sum = r.product.price;

        r.options.forEach(function (g) {
          g.values.forEach(function (v) {
            var on = g.type === 'checkbox' ? sel[g.id].indexOf(v.id) > -1 : sel[g.id] === v.id;
            if (on) { sum += v.delta; }
          });
        });

        return sum;
      }

      function complete() {
        return r.options.every(function (g) {
          return !g.required || (g.type === 'checkbox' ? sel[g.id].length : sel[g.id]);
        });
      }

      B.ui.modal(function (box, close) {
        box.className = 'bp-modal opm';

        function draw() {
          var thumb = r.product.image ? '<img src="' + esc(r.product.image) + '" alt="">' : ic('box-seam');

          box.innerHTML = '<div class="oph"><div class="cthumb">' + thumb + '</div><div class="opinfo"><h3>' + esc(r.product.name) + '</h3><small>' + esc(r.product.sku || r.product.model) + '</small></div><div class="opp" id="sl-opp">' + money(price()) + '</div></div>' +
            '<div class="opbody">' + r.options.map(function (g) {
              return '<div class="og"><div class="ogh"><b>' + esc(g.name) + '</b><small>' + esc(g.required ? t('sl_required', 'Required') : t('sl_optional', 'Optional')) + ' · ' + esc(g.type === 'checkbox' ? t('sl_choose_any', 'Choose any') : t('sl_choose_one', 'Choose one')) + '</small></div><div class="ogv">' +
                g.values.map(function (v) {
                  var on = g.type === 'checkbox' ? sel[g.id].indexOf(v.id) > -1 : sel[g.id] === v.id;
                  return '<button class="oc' + (on ? ' on' : '') + '" type="button" data-g="' + g.id + '" data-v="' + v.id + '"' + (v.out ? ' disabled' : '') + '><span>' + esc(v.name) + '</span>' + (v.delta ? '<small>' + (v.delta > 0 ? '+' : '') + money(v.delta) + '</small>' : '') + '</button>';
                }).join('') + '</div></div>';
            }).join('') + '</div>' +
            '<div class="opf"><button class="btn" type="button" data-x>' + esc(t('cancel', 'Cancel')) + '</button><button class="btn pri" type="button" data-ok' + (complete() ? '' : ' disabled') + '>' + ic('plus-lg') + esc(editIndex !== null ? t('sl_update', 'Update item') : t('sl_add', 'Add to order')) + '</button></div>';
        }

        draw();

        box.addEventListener('click', function (e) {
          var oc = e.target.closest('.oc');

          if (oc) {
            var g = r.options.filter(function (x) { return String(x.id) === oc.getAttribute('data-g'); })[0];
            var v = Number(oc.getAttribute('data-v'));

            if (g.type === 'checkbox') {
              var i = sel[g.id].indexOf(v);
              if (i > -1) { sel[g.id].splice(i, 1); } else { sel[g.id].push(v); }
            } else {
              sel[g.id] = sel[g.id] === v && !g.required ? 0 : v;
            }

            draw();
            return;
          }

          if (e.target.closest('[data-x]')) { close(null); return; }

          if (e.target.closest('[data-ok]')) {
            var opt = {};

            r.options.forEach(function (g) {
              if (g.type === 'checkbox') { if (sel[g.id].length) { opt[g.id] = sel[g.id].slice(); } }
              else if (sel[g.id]) { opt[g.id] = sel[g.id]; }
            });

            close(opt);
          }
        });
      }, true).then(function (opt) {
        if (!opt) { return; }

        if (editIndex !== null) {
          var c = S.cart[editIndex];
          var key = lineKey(p.id, opt);
          var dup = -1;

          S.cart.forEach(function (x, i) { if (i !== editIndex && lineKey(x.pid, x.opt) === key) { dup = i; } });

          if (dup > -1) { S.cart[dup].qty += c.qty; S.cart.splice(editIndex, 1); }
          else { c.opt = opt; }

          refresh(true);
          B.ui.toast(t('sl_updated', 'Item updated'));
        } else if (addLine(p, 1, opt, true)) {
          B.ui.toast(p.name + ' ' + t('sl_added', 'added'));
        }
      });
    }).catch(function (e) { B.ui.toast(e.message, 'err'); });
  }

  /* ---------- order panel ---------- */
  var VALID = { inc: 1, dec: 1 };

  function renderOrder() {
    var v = S.view;
    var fresh = v && v.lines.length === S.cart.length;
    var n = S.cart.reduce(function (a, c) { return a + c.qty; }, 0);

    $('sl-cnt').textContent = n ? plural(n) : '';
    $('sl-order').classList.toggle('busy', S.pending);

    var typing = d.activeElement && d.activeElement.classList && d.activeElement.classList.contains('qin');
    var linesHtml = S.cart.length ? S.cart.map(function (c, i) {
      var L = fresh ? v.lines[i] : null;
      var name = L && L.name ? L.name : c.name;
      var unit = L ? L.unit : 0;
      var left = L && L.left !== null ? L.left : null;
      var noMore = left !== null && left <= 0 && !P.negStock;
      var opt = L ? L.option : '';

      return '<div class="line' + (L && L.error ? ' err' : '') + '"><div class="t">' + esc(name) + '</div><div class="ln">' +
        (c.hasOpts ? '<button class="lopt" type="button" data-optedit="' + i + '" aria-label="' + esc(t('sl_change_opts', 'Change options')) + '"><span>' + esc(opt || t('sl_options', 'Options')) + '</span>' + ic('pencil') + '</button>' : '') +
        '<div class="u">' + (L ? money(unit) + ' ' + esc(t('sl_each', 'each')) + (left !== null && left <= P.low ? ' · ' + Math.max(left, 0) + ' ' + esc(t('sl_left', 'left')) : '') : '…') + '</div>' +
        (L && L.error ? '<div class="bad">' + esc(L.error.message) + '</div>' : '') + '</div>' +
        '<div class="step" role="group" aria-label="' + esc(t('sl_qty', 'Quantity')) + '"><button type="button" data-dec="' + i + '" aria-label="' + esc(t('sl_dec', 'Decrease')) + '">' + ic(c.qty === 1 ? 'trash3' : 'dash-lg') + '</button><input class="qin" data-qty="' + i + '" inputmode="numeric" autocomplete="off" aria-label="' + esc(t('sl_qty', 'Quantity')) + '" value="' + c.qty + '"><button type="button" data-inc="' + i + '" aria-label="' + esc(t('sl_inc', 'Increase')) + '"' + (noMore ? ' disabled' : '') + '>' + ic('plus-lg') + '</button></div>' +
        '<div class="amt">' + (L ? money(unit * c.qty) : '') + '</div></div>';
    }).join('') : '<div class="blank">' + esc(t('sl_empty', 'Tap a product or scan a barcode to start the order.')) + '</div>';

    if (typing) {
      // Keep the focused quantity field alive; only refresh the amounts
      var amts = $('sl-lines').querySelectorAll('.amt');
      S.cart.forEach(function (c, i) {
        var L = fresh ? v.lines[i] : null;
        if (amts[i] && L) { amts[i].textContent = money(L.unit * c.qty); }
      });
    } else {
      $('sl-lines').innerHTML = linesHtml;
    }

    renderOpts();
    renderTotals(fresh);
  }

  function payTitle() {
    var m = S.view ? S.view.payments.filter(function (x) { return x.code === S.pay; })[0] : null;
    return m ? m.title : (S.pay === 'cash' ? t('sl_cash', 'Cash') : S.pay);
  }

  function shipTitle() {
    var q = S.view ? S.view.shipping.quotes.filter(function (x) { return x.code === S.view.shipping.selected; })[0] : null;
    return q ? q.title : '';
  }

  function renderOpts() {
    var v = S.view;
    var on = function (k) { return S.open === k ? ' on' : ''; };
    var guestOk = P.guest || S.customer;
    var cname = S.customer ? S.customer.name : t('sl_guest', 'Guest');
    var chips = '<button class="chip' + on('customer') + (guestOk ? '' : ' need') + '" type="button" data-open="customer" aria-expanded="' + (S.open === 'customer') + '"><small>' + ic(S.customer ? 'person-check' : 'person') + esc(t('sl_customer', 'Customer')) + '</small><span>' + esc(P.guest || S.customer ? cname : t('sl_choose', 'Choose')) + '</span></button>';

    if (v && v.shipping.quotes.length) {
      chips += '<button class="chip' + on('ship') + '" type="button" data-open="ship" aria-expanded="' + (S.open === 'ship') + '"><small>' + ic('truck') + esc(t('sl_shipping', 'Shipping')) + '</small><span>' + esc(shipTitle()) + '</span></button>';
    }

    var cash = S.pay === 'cash' ? (S.tender ? t('sl_cash', 'Cash') + ' · ' + money(S.tender) : t('sl_cash', 'Cash') + ' · ' + t('sl_exact', 'Exact').toLowerCase()) : payTitle();
    chips += '<button class="chip' + on('pay') + '" type="button" data-open="pay" aria-expanded="' + (S.open === 'pay') + '"><small>' + ic(S.pay === 'cash' ? 'cash-coin' : 'credit-card') + esc(t('sl_payment', 'Payment')) + '</small><span>' + esc(cash) + '</span></button>';

    var dl = S.discount ? (S.discount.type === '%' ? S.discount.value + '% ' + t('sl_off', 'off') : money(S.discount.value)) : t('sl_discount', 'Discount');
    var acts = '';

    if (B.can('discount')) { acts += '<button class="act' + (S.discount ? ' set' : '') + on('disc') + '" type="button" data-open="disc">' + ic('percent') + esc(dl) + '</button>'; }
    if (B.can('charge')) { acts += '<button class="act' + (S.charge ? ' set' : '') + on('charge') + '" type="button" data-open="charge">' + ic('plus-circle') + esc(S.charge ? money(S.charge) : t('sl_charge', 'Charge')) + '</button>'; }
    if (B.can('coupon')) { acts += '<button class="act' + (S.coupon ? ' set' : '') + on('coupon') + '" type="button" data-open="coupon">' + ic('ticket-perforated') + esc(S.coupon || t('sl_coupon', 'Coupon')) + '</button>'; }

    var head = '<div class="chips" style="grid-template-columns:repeat(' + (v && v.shipping.quotes.length ? 3 : 2) + ',1fr)">' + chips + '</div>' + (acts ? '<div class="acts">' + acts + '</div>' : '');
    var holder = $('sl-panel');
    var busyField = holder && d.activeElement && holder.contains(d.activeElement) && d.activeElement.tagName === 'INPUT';

    if (busyField) {
      // A field in the open panel has focus: refresh the chips only
      $('sl-chips').innerHTML = head;
      return;
    }

    $('sl-opts').innerHTML = '<div id="sl-chips">' + head + '</div><div id="sl-panel">' + panel() + '</div>';

    if (S.open === 'customer' && !S.newc) { loadCustomers(false); }
  }

  var oi = function (n) { return '<span class="oi">' + ic(n) + '</span>'; };
  var btnCancel = function () { return '<button class="btn" type="button" data-act="close">' + ic('x-lg') + esc(t('cancel', 'Cancel')) + '</button>'; };
  var btnApply = function (a) { return '<button class="btn pri" type="button" data-act="' + a + '">' + ic('check-lg') + esc(t('sl_apply', 'Apply')) + '</button>'; };
  var btnRemove = function (a) { return '<button class="btn" type="button" data-act="' + a + '">' + ic('trash3') + esc(t('sl_remove', 'Remove')) + '</button>'; };

  function panel() {
    var v = S.view;

    if (S.open === 'customer' && S.newc) {
      var n = S.newc;
      var f = function (k, label, ph, mode) {
        return '<div class="field"><label for="sl-nc-' + k + '">' + esc(label) + (k !== 'email' ? ' <b class="req">*</b>' : '') + '</label><input class="inp' + (n.err[k] ? ' err' : '') + '" id="sl-nc-' + k + '" data-nc="' + k + '" value="' + esc(n[k]) + '" placeholder="' + esc(ph) + '" autocomplete="off"' + (mode ? ' inputmode="' + mode + '"' : '') + '>' + (n.err[k] ? '<div class="errtxt">' + esc(n.err[k]) + '</div>' : '') + '</div>';
      };

      return '<div class="panel"><div class="phd"><h4>' + esc(t('sl_new_customer', 'New customer')) + '</h4><button class="btn" type="button" data-act="nccancel">' + ic('x-lg') + esc(t('cancel', 'Cancel')) + '</button><button class="btn pri" type="button" data-act="ncok">' + ic('check-lg') + esc(t('sl_save', 'Save')) + '</button></div>' +
        f('name', t('sl_full_name', 'Full name'), t('sl_full_name_ph', 'e.g. Budi Santoso')) + f('phone', t('sl_phone', 'Phone'), t('sl_phone_ph', 'e.g. 0812-3456-7788'), 'tel') + f('email', t('sl_email_opt', 'Email (optional)'), 'name@example.com', 'email') + '</div>';
    }

    if (S.open === 'customer') {
      return '<div class="panel"><div class="phd"><h4>' + esc(t('sl_customer', 'Customer')) + '</h4><button class="btn" type="button" data-act="newcust">' + ic('person-plus') + esc(t('sl_new_customer', 'New customer')) + '</button><button class="xbtn" type="button" data-act="close" aria-label="' + esc(t('close', 'Close')) + '">' + ic('x-lg') + '</button></div>' +
        '<input class="inp" id="sl-cq" placeholder="' + esc(t('sl_search_customer', 'Search name or phone')) + '" value="' + esc(S.cq) + '" autocomplete="off">' +
        (P.guest && !S.cq ? '<button class="opt' + (!S.customer ? ' on' : '') + '" type="button" data-cust="0">' + oi('person') + '<span>' + esc(t('sl_guest', 'Guest')) + '<small>' + esc(t('sl_walkin', 'Walk-in, no account')) + '</small></span></button>' : '') +
        '<div id="sl-clist">' + clistHtml() + '</div></div>';
    }

    if (S.open === 'ship' && v) {
      return '<div class="panel"><h4>' + esc(t('sl_shipping_method', 'Shipping method')) + '</h4>' + v.shipping.quotes.map(function (q) {
        return '<button class="opt' + (q.code === v.shipping.selected ? ' on' : '') + '" type="button" data-ship="' + esc(q.code) + '">' + oi('truck') + '<span>' + esc(q.title) + '<small>' + esc(q.group) + '</small></span><span class="mono">' + money(q.cost) + '</span></button>';
      }).join('') + '<div class="pa">' + btnCancel() + '</div></div>';
    }

    if (S.open === 'pay' && v) {
      var tiles = v.payments.map(function (m) {
        return '<button class="opt' + (S.pay === m.code ? ' on' : '') + '" type="button" data-pay="' + esc(m.code) + '">' + oi(m.code === 'cash' ? 'cash-coin' : 'credit-card') + esc(m.title) + '</button>';
      }).join('');

      var cashBlock = '';

      if (S.pay === 'cash') {
        cashBlock = '<h4>' + esc(t('sl_cash_received', 'Cash received')) + '</h4><div class="tend"><button type="button" data-tender="0" class="' + (!S.tender ? 'on' : '') + '">' + esc(t('sl_exact', 'Exact')) + '</button>' +
          tenders(v.total).map(function (x) { return '<button type="button" data-tender="' + x + '" class="' + (Math.abs(S.tender - x) < 1e-6 ? 'on' : '') + '">' + money(x) + '</button>'; }).join('') + '</div>' +
          '<input class="inp mono" id="sl-tin" inputmode="decimal" placeholder="' + esc(t('sl_other_amount', 'Other amount')) + '" value="' + (S.tender && tenders(v.total).indexOf(S.tender) === -1 ? S.tender : '') + '" autocomplete="off">';
      }

      return '<div class="panel"><h4>' + esc(t('sl_payment_method', 'Payment method')) + '</h4><div class="tiles" style="grid-template-columns:repeat(' + Math.min(Math.max(v.payments.length, 1), 3) + ',1fr)">' + tiles + '</div>' + cashBlock + '<div class="pa"><button class="btn pri" type="button" data-act="close">' + ic('check-lg') + esc(t('sl_done', 'Done')) + '</button></div></div>';
    }

    if (S.open === 'disc') {
      return '<div class="panel"><h4>' + esc(t('sl_discount', 'Discount')) + '</h4><div class="irow"><div class="seg"><button type="button" data-dt="%" class="' + (S.draft.type === '%' ? 'on' : '') + '">%</button><button type="button" data-dt="F" class="' + (S.draft.type === 'F' ? 'on' : '') + '">' + esc(currencySymbol()) + '</button></div>' +
        '<input class="inp mono" id="sl-dv" inputmode="decimal" placeholder="0" value="' + esc(S.draft.val) + '" autocomplete="off"></div>' +
        (P.maxDiscount && B.boot.user && B.boot.user.role === 'cashier' ? '<div class="hint">' + esc(t('sl_max_discount', 'Cashier limit')) + ': ' + P.maxDiscount + '%</div>' : '') +
        '<div class="pa">' + (S.discount ? btnRemove('discrm') : '') + btnCancel() + btnApply('discok') + '</div></div>';
    }

    if (S.open === 'charge') {
      return '<div class="panel"><h4>' + esc(t('sl_extra_charge', 'Extra charge')) + '</h4><input class="inp mono" id="sl-cv" inputmode="decimal" placeholder="' + esc(t('sl_charge_ph', 'e.g. 3.00 for gift wrap')) + '" value="' + (S.charge || '') + '" autocomplete="off"><div class="pa">' + (S.charge ? btnRemove('chrm') : '') + btnCancel() + btnApply('chok') + '</div></div>';
    }

    if (S.open === 'coupon') {
      var msg = S.couponErr ? '<div class="err">' + esc(t('sl_code', 'Code')) + ' "' + esc(S.couponErr) + '" ' + esc(t('sl_coupon_bad', 'is not valid or has expired.')) + '</div>' : '';

      return '<div class="panel"><h4>' + esc(t('sl_coupon_code', 'Coupon code')) + '</h4><input class="inp mono" id="sl-cc" placeholder="' + esc(t('sl_enter_code', 'Enter code')) + '" value="' + esc(S.coupon) + '" style="text-transform:uppercase" autocomplete="off">' + msg + '<div class="pa">' + (S.coupon ? btnRemove('cprm') : '') + btnCancel() + btnApply('cpok') + '</div></div>';
    }

    return '';
  }

  function currencySymbol() {
    try {
      var parts = nf.formatToParts(0);
      for (var i = 0; i < parts.length; i++) { if (parts[i].type === 'currency') { return parts[i].value; } }
    } catch (e) { /* fall through */ }

    return P.currency.code;
  }

  // Nice cash amounts above the total
  function tenders(total) {
    if (!(total > 0)) { return []; }

    var base = Math.pow(10, Math.floor(Math.log10(total)));
    var cand = [Math.ceil(total / (base / 2)) * (base / 2), Math.ceil(total / base) * base, Math.ceil(total / (base * 5)) * base * 5];
    var out = [];

    cand.forEach(function (x) {
      x = Math.round(x * 100) / 100;
      if (x > total + 1e-9 && out.indexOf(x) === -1) { out.push(x); }
    });

    return out.sort(function (a, b) { return a - b; }).slice(0, 3);
  }

  function clistHtml() {
    if (S.clist === null) { return '<div class="hint">…</div>'; }
    if (!S.clist.length) { return '<div class="hint">' + esc(t('sl_no_customer', 'No customer found')) + '</div>'; }

    return S.clist.map(function (c) {
      return '<button class="opt' + (S.customer && S.customer.id === c.id ? ' on' : '') + '" type="button" data-cust="' + c.id + '">' + oi('person-check') + '<span>' + esc(c.name) + '<small>' + esc(c.phone || c.email) + '</small></span></button>';
    }).join('');
  }

  var custTimer = null;
  var custCtl = null;

  function loadCustomers(delay) {
    clearTimeout(custTimer);

    custTimer = setTimeout(function () {
      if (custCtl) { custCtl.abort(); }
      custCtl = new AbortController();

      B.api(P.urls.customers + (P.urls.customers.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(S.cq), { signal: custCtl.signal }).then(function (r) {
        S.clist = r.customers;
        var el = $('sl-clist');
        if (el) { el.innerHTML = clistHtml(); }
      }).catch(function (e) {
        if (e && e.name === 'AbortError') { return; }
        B.ui.toast(e.message, 'err');
      });
    }, delay ? 250 : 0);
  }

  function renderTotals(fresh) {
    var v = S.view;
    var html = '';

    if (v && fresh) {
      v.totals.forEach(function (r) {
        var cls = r.code === 'sub_total' ? 'r g' : (r.code === 'total' ? 'r big' : 'r');
        html += '<div class="' + cls + '"><span>' + esc(r.title) + '</span><span>' + money(r.amount) + '</span></div>';
      });

      if (v.blocked) { html += '<div class="r warn"><span>' + esc(v.blocked.message) + '</span><span></span></div>'; }

      if (S.pay === 'cash' && S.tender) {
        html += '<div class="r"><span>' + esc(t('sl_received', 'Received')) + ' ' + money(S.tender) + '</span><span style="color:var(--green)">' + esc(t('sl_change', 'Change')) + ' ' + money(Math.max(S.tender - v.total, 0)) + '</span></div>';
      }
    }

    $('sl-tot').innerHTML = html;

    var total = v && fresh ? v.total : 0;
    var ready = !!(v && fresh && v.ok && !S.pending && !S.paying && v.payments.length && (P.guest || S.customer) && !(S.pay === 'cash' && S.tender && S.tender + 1e-9 < v.total));
    var pay = $('sl-pay');

    pay.disabled = !ready;
    pay.classList.toggle('wait', S.paying);
    pay.innerHTML = ic('check2-circle') + esc(t('sl_pay', 'Pay')) + ' ' + money(total);

    var n = S.cart.reduce(function (a, c) { return a + c.qty; }, 0);
    $('sl-mcount').textContent = n ? t('sl_view_order', 'View order') + ' · ' + plural(n) : t('sl_order_empty', 'Order is empty');
    $('sl-mtotal').textContent = money(total);
  }

  /* ---------- checkout ---------- */
  function checkout() {
    if (S.paying) { return; }

    S.paying = true;
    S.rid = S.rid || uid();
    persist();
    renderTotals(true);

    var data = payload();
    data.payment = S.pay;
    data.tendered = S.pay === 'cash' ? S.tender : 0;
    data.request_id = S.rid;

    B.api(P.urls.checkout, { data: data }).then(function (r) {
      S.paying = false;
      showDone(r);
    }).catch(function (e) {
      S.paying = false;

      if (e.data && e.data.cart) { S.view = e.data.cart; }

      // A rejected cart needs a fresh attempt id; a network failure keeps the id so a retry cannot double-charge
      if (e.code !== 'network') { S.rid = ''; persist(); }

      B.ui.toast(e.message, 'err');
      renderOrder();
    });
  }

  function printReceipt(url) {
    var old = d.querySelector('iframe.sl-print');
    if (old) { old.parentNode.removeChild(old); }

    var f = d.createElement('iframe');
    f.className = 'sl-print';
    f.setAttribute('aria-hidden', 'true');
    f.onload = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { B.ui.toast(t('sl_print_fail', 'Could not open the print dialog.'), 'err'); }
    };
    f.src = url;
    d.body.appendChild(f);
  }

  function resetSale() {
    S.cart = []; S.customer = null; S.discount = null; S.charge = 0; S.coupon = ''; S.couponErr = ''; S.shipping = ''; S.tender = 0; S.rid = ''; S.open = null; S.view = null; S.pending = false;
    persist();
    renderOrder();
    loadCatalog(false);
    closeSheet();
    var q = $('sl-q');
    if (q) { q.focus(); }
  }

  function showDone(r) {
    var method = payTitle();
    var cash = S.pay === 'cash';

    B.ui.modal(function (box, close) {
      box.className = 'bp-modal sl-done';
      box.innerHTML = '<div class="ok">' + ic('check-lg') + '</div><h2>' + esc(t('sl_paid', 'Payment received')) + '</h2>' +
        '<dl><dt>' + esc(t('sl_order', 'Order')) + '</dt><dd>#' + r.order_id + '</dd><dt>' + esc(t('sl_method', 'Method')) + '</dt><dd>' + esc(method) + '</dd><dt>' + esc(t('sl_total', 'Total')) + '</dt><dd>' + money(r.total) + '</dd>' +
        (cash ? '<dt>' + esc(t('sl_received', 'Received')) + '</dt><dd>' + money(r.tendered) + '</dd><dt>' + esc(t('sl_change', 'Change')) + '</dt><dd>' + money(r.change) + '</dd>' : '') + '</dl>' +
        '<div class="row"><button class="btn" type="button" data-print>' + ic('printer') + esc(t('sl_print', 'Print receipt')) + '</button><button class="btn pri" type="button" data-new>' + ic('plus-lg') + esc(t('sl_new_order', 'New order')) + '</button></div>';

      box.addEventListener('click', function (e) {
        if (e.target.closest('[data-print]')) { printReceipt(r.receipt_url); }
        if (e.target.closest('[data-new]')) { close(true); }
      });

      var nb = box.querySelector('[data-new]');
      if (nb) { nb.focus(); }
    }, false).then(function () { resetSale(); });
  }

  /* ---------- mobile sheet ---------- */
  function openSheet() { $('sl-order').classList.add('open'); }
  function closeSheet() { $('sl-order').classList.remove('open'); }

  /* ---------- events ---------- */
  function act(name) {
    if (name === 'close') { S.open = null; S.newc = null; }
    else if (name === 'newcust') { S.newc = { name: S.cq, phone: '', email: '', err: {} }; }
    else if (name === 'nccancel') { S.newc = null; }
    else if (name === 'ncok') { saveCustomer(); return; }
    else if (name === 'discrm') { S.discount = null; S.open = null; refresh(true); return; }
    else if (name === 'discok') {
      var val = parseFloat(String(S.draft.val).replace(',', '.'));
      if (!(val > 0)) { S.discount = null; } else { S.discount = { type: S.draft.type, value: S.draft.type === '%' ? Math.min(val, 100) : val }; }
      S.open = null; refresh(true); return;
    }
    else if (name === 'chrm') { S.charge = 0; S.open = null; refresh(true); return; }
    else if (name === 'chok') {
      var cv = parseFloat(String(($('sl-cv') || {}).value || '').replace(',', '.'));
      S.charge = cv > 0 ? cv : 0; S.open = null; refresh(true); return;
    }
    else if (name === 'cprm') { S.coupon = ''; S.couponErr = ''; S.open = null; refresh(true); return; }
    else if (name === 'cpok') {
      var code = String((($('sl-cc') || {}).value || '')).trim().toUpperCase();
      S.couponErr = '';
      if (!code) { S.coupon = ''; S.open = null; refresh(true); return; }
      S.coupon = code; S.open = null; refresh(true); return;
    }

    renderOpts();
  }

  function saveCustomer() {
    var n = S.newc;
    n.err = {};

    B.api(P.urls.customer, { data: { name: n.name, phone: n.phone, email: n.email } }).then(function (r) {
      S.customer = r.customer;
      S.newc = null; S.open = null; S.cq = ''; S.clist = null;
      B.ui.toast(t('sl_customer_added', 'Customer added'));
      refresh(true);
    }).catch(function (e) {
      if (e.fields && Object.keys(e.fields).length) { n.err = e.fields; renderOpts(); return; }
      B.ui.toast(e.message, 'err');
    });
  }

  function onClick(e) {
    var el = e.target.closest ? e.target : null;
    if (!el || !root.contains(el)) { return; }

    var x;

    if ((x = el.closest('[data-cat]'))) { S.cat = Number(x.getAttribute('data-cat')); loadCatalog(false); return; }
    if (el.closest('[data-more]')) { S.page++; loadCatalog(true); return; }

    if ((x = el.closest('[data-add]'))) {
      var p = S.byId[Number(x.getAttribute('data-add'))];
      if (p) { pick(p); }
      return;
    }

    if ((x = el.closest('[data-inc]'))) { S.cart[Number(x.getAttribute('data-inc'))].qty++; refresh(true); return; }

    if ((x = el.closest('[data-dec]'))) {
      var i = Number(x.getAttribute('data-dec'));
      if (S.cart[i].qty <= 1) { S.cart.splice(i, 1); } else { S.cart[i].qty--; }
      refresh(true);
      return;
    }

    if ((x = el.closest('[data-optedit]'))) {
      var idx = Number(x.getAttribute('data-optedit'));
      var prod = S.byId[S.cart[idx].pid] || { id: S.cart[idx].pid, name: S.cart[idx].name, price: 0, qty: null, image: '', sku: '', model: '' };
      openPicker(prod, idx);
      return;
    }

    if ((x = el.closest('[data-open]'))) {
      var k = x.getAttribute('data-open');
      S.open = S.open === k ? null : k;
      S.newc = null;
      if (k === 'customer') { S.cq = ''; S.clist = null; }
      if (k === 'disc') { S.draft = S.discount ? { type: S.discount.type, val: String(S.discount.value) } : { type: '%', val: '' }; }
      if (k === 'coupon') { S.couponErr = ''; }
      renderOpts();
      return;
    }

    if ((x = el.closest('[data-act]'))) { act(x.getAttribute('data-act')); return; }

    if ((x = el.closest('[data-cust]'))) {
      var cid = Number(x.getAttribute('data-cust'));
      S.customer = cid ? S.clist.filter(function (c) { return c.id === cid; })[0] || null : null;
      S.open = null;
      refresh(true);
      return;
    }

    if ((x = el.closest('[data-ship]'))) { S.shipping = x.getAttribute('data-ship'); S.open = null; refresh(true); return; }

    if ((x = el.closest('[data-pay]'))) { S.pay = x.getAttribute('data-pay'); S.tender = 0; persist(); renderOrder(); return; }

    if ((x = el.closest('[data-tender]'))) { S.tender = Number(x.getAttribute('data-tender')); persist(); renderOrder(); return; }

    if ((x = el.closest('[data-dt]'))) { S.draft.type = x.getAttribute('data-dt'); renderOpts(); return; }

    if (el.closest('#sl-clear')) {
      if (!S.cart.length) { return; }
      B.ui.confirm(t('sl_clear_q', 'Clear the cart? All items in the current order will be removed.'), t('sl_clear_ok', 'Clear cart')).then(function (ok) { if (ok) { S.rid = ''; resetSale(); } });
      return;
    }

    if (el.closest('#sl-pay')) { checkout(); return; }
    if (el.closest('#sl-open')) { openSheet(); return; }
    if (el.closest('#sl-close')) { closeSheet(); }
  }

  function onInput(e) {
    var el = e.target;

    if (el.id === 'sl-cq') { S.cq = el.value; loadCustomers(true); return; }
    if (el.id === 'sl-dv') { S.draft.val = el.value; return; }
    if (el.id === 'sl-tin') {
      var n = parseFloat(String(el.value).replace(',', '.'));
      S.tender = n > 0 ? n : 0;
      persist();
      renderTotals(S.view && S.view.lines.length === S.cart.length);
      return;
    }

    var nc = el.getAttribute && el.getAttribute('data-nc');
    if (nc && S.newc) { S.newc[nc] = el.value; return; }

    if (el.hasAttribute && el.hasAttribute('data-qty')) {
      var i = Number(el.getAttribute('data-qty'));
      var q = parseInt(el.value, 10);

      if (q > 0 && S.cart[i]) { S.cart[i].qty = Math.min(q, 9999); refresh(false); }
    }
  }

  function onChange(e) {
    var el = e.target;

    if (el.hasAttribute && el.hasAttribute('data-qty')) {
      var i = Number(el.getAttribute('data-qty'));
      var q = parseInt(el.value, 10);

      if (!(q > 0)) { el.value = S.cart[i].qty; }
    }
  }

  var searchTimer = null;

  function onSearch(e) {
    var el = e.target;
    clearTimeout(searchTimer);

    searchTimer = setTimeout(function () {
      S.q = el.value.trim();
      loadCatalog(false);
    }, 250);
  }

  // Enter in the search box looks the text up as a barcode first
  function onSearchKey(e) {
    if (e.key !== 'Enter') { return; }

    e.preventDefault();
    var el = e.target;
    var code = el.value.trim();
    if (!code) { return; }

    clearTimeout(searchTimer);

    B.api(P.urls.scan + (P.urls.scan.indexOf('?') > -1 ? '&' : '?') + 'code=' + encodeURIComponent(code)).then(function (r) {
      if (r.products.length === 1) {
        var p = r.products[0];
        S.byId[p.id] = p;
        el.value = '';
        S.q = '';
        loadCatalog(false);
        pick(p);
      } else {
        S.q = code;
        loadCatalog(false);
        if (!r.products.length) { B.ui.toast(t('sl_no_barcode', 'No sellable product for') + ' ' + code, 'err'); }
      }
    }).catch(function (err) { B.ui.toast(err.message, 'err'); });
  }

  // Printable keys outside a field go to the search box, so a scanner works without focus
  function onKey(e) {
    var tag = (d.activeElement && d.activeElement.tagName) || '';
    var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    var q = $('sl-q');

    if (!q) { return; }

    if (e.key === 'F2' || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
      e.preventDefault();
      q.focus();
      q.select();
      return;
    }

    if (!typing && !e.ctrlKey && !e.metaKey && !e.altKey && e.key.length === 1 && !d.getElementById('bp-ov').children.length) {
      q.focus();
    }
  }

  /* ---------- wire up ---------- */
  root.addEventListener('click', onClick);
  root.addEventListener('input', onInput);
  root.addEventListener('change', onChange);
  root.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.cc[data-add]')) { e.preventDefault(); e.target.click(); }
  });
  d.addEventListener('keydown', onKey);

  var q = $('sl-q');
  if (q) {
    q.addEventListener('input', onSearch);
    q.addEventListener('keydown', onSearchKey);
  }

  var lay = $('sl-lay');
  if (lay) {
    Array.prototype.forEach.call(lay.querySelectorAll('button'), function (b) { b.classList.toggle('on', b.getAttribute('data-layout') === S.layout); });
    lay.addEventListener('click', function (e) {
      var x = e.target.closest('[data-layout]');
      if (!x) { return; }
      S.layout = x.getAttribute('data-layout');
      B.store.set(LAYOUT_KEY, S.layout);
      Array.prototype.forEach.call(lay.querySelectorAll('button'), function (b) { b.classList.toggle('on', b === x); });
      renderCatalog();
    });
  }

  B.onLeave(function () {
    d.removeEventListener('keydown', onKey);
    clearTimeout(quoteTimer);
    clearTimeout(searchTimer);
    clearTimeout(custTimer);
    quoteSeq++;
    if (catCtl) { catCtl.abort(); }
    if (custCtl) { custCtl.abort(); }
    var f = d.querySelector('iframe.sl-print');
    if (f && f.parentNode) { f.parentNode.removeChild(f); }
  });

  renderPills();
  loadCatalog(false);
  renderOrder();

  if (S.cart.length) { refresh(true); }
})(window, document, window.BPOS);
