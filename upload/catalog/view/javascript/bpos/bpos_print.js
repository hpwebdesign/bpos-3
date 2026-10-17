/* BPOS.print: receipt printing. Browser dialog, or raw ESC/POS over Web Serial, WebUSB or the RawBT app. The choice is stored per browser. */
(function (w, d, B) {
  var ui = B.ui;
  var KEY = 'bpos.printer';
  var print = (B.print = {});
  var cfg = load();

  function load() {
    var v = null;

    try { v = JSON.parse(B.store.get(KEY) || 'null'); } catch (e) { v = null; }

    return Object.assign({ mode: 'dialog', cols: 0, cut: false, usb: null }, v || {});
  }

  function save() { B.store.set(KEY, JSON.stringify(cfg)); }

  var t = function (k, f) { return B.t(k, f); };

  /* ---------- ESC/POS ---------- */
  var MAP = { '€': 'EUR', '£': 'GBP', '¥': 'JPY', '·': '-', '–': '-', '—': '-', '’': "'", '‘': "'", '“': '"', '”': '"', '…': '...', '×': 'x', ' ': ' ' };

  // Printers use a 1-byte code page, so everything is reduced to plain ASCII
  function ascii(s) {
    s = String(s == null ? '' : s).replace(/[€£¥·–—’‘“”…× ]/g, function (c) { return MAP[c]; });
    s = s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;

    return s.replace(/[^\x20-\x7e]/g, '?');
  }

  function wrap(s, cols) {
    var out = [];

    String(s == null ? '' : s).split(/\r?\n/).forEach(function (para) {
      para = ascii(para);

      var cur = '';

      para.split(' ').forEach(function (word) {
        while (word.length > cols) {
          if (cur) { out.push(cur); cur = ''; }
          out.push(word.slice(0, cols));
          word = word.slice(cols);
        }

        if (!cur) { cur = word; }
        else if ((cur + ' ' + word).length <= cols) { cur += ' ' + word; }
        else { out.push(cur); cur = word; }
      });

      out.push(cur);
    });

    return out;
  }

  function pad(left, right, cols) {
    left = ascii(left);
    right = ascii(right);

    if (left.length + right.length + 1 <= cols) {
      return [left + new Array(cols - left.length - right.length + 1).join(' ') + right];
    }

    var rows = wrap(left, cols);

    rows.push(new Array(Math.max(0, cols - right.length) + 1).join(' ') + right);

    return rows;
  }

  function build(r, cols, cut) {
    var o = [];
    var raw = function () { for (var i = 0; i < arguments.length; i++) { o.push(arguments[i]); } };
    var line = function (s) {
      var a = ascii(s);

      for (var i = 0; i < a.length; i++) { o.push(a.charCodeAt(i)); }
      o.push(10);
    };
    var lines = function (arr) { arr.forEach(line); };
    var hr = new Array(cols + 1).join('-');
    var L = r.labels || {};

    raw(0x1b, 0x40, 0x1b, 0x74, 0);
    raw(0x1b, 0x61, 1);
    lines(wrap(r.header || '', cols));
    raw(0x1b, 0x61, 0);
    line(hr);
    line((L.receipt || 'Receipt') + ' #' + r.order_id);
    line(r.date);
    if (r.cashier) { lines(wrap((L.cashier || 'Cashier') + ': ' + r.cashier, cols)); }
    if (r.terminal) { lines(wrap((L.terminal || 'Terminal') + ': ' + r.terminal, cols)); }
    if (r.customer) { lines(wrap((L.customer || 'Customer') + ': ' + r.customer, cols)); }
    line(hr);

    r.lines.forEach(function (l) {
      lines(wrap(l.name, cols));
      if (l.options) { wrap(l.options, cols - 2).forEach(function (x) { line('  ' + x); }); }
      lines(pad('  ' + l.qty + ' x ' + l.price, l.total, cols));
    });

    line(hr);

    r.totals.forEach(function (x) {
      if (x.big) { raw(0x1b, 0x45, 1); }
      lines(pad(x.title, x.value, cols));
      if (x.big) { raw(0x1b, 0x45, 0); }
    });

    line(hr);
    lines(pad(L.payment || 'Payment', r.payment, cols));

    if (r.cash) {
      lines(pad(L.received || 'Received', r.tendered, cols));
      lines(pad(L.change || 'Change', r.change, cols));
    }

    if (r.footer) {
      line(hr);
      raw(0x1b, 0x61, 1);
      lines(wrap(r.footer, cols));
      raw(0x1b, 0x61, 0);
    }

    if (r.barcode) {
      raw(0x1b, 0x61, 1, 10);
      raw(0x1d, 0x68, 60, 0x1d, 0x77, 2, 0x1d, 0x48, 2, 0x1d, 0x6b, 4);
      String(r.order_id).split('').forEach(function (c) { raw(c.charCodeAt(0)); });
      raw(0, 10, 0x1b, 0x61, 0);
    }

    raw(0x1b, 0x64, 4);
    if (cut) { raw(0x1d, 0x56, 66, 0); }

    return new Uint8Array(o);
  }

  function colsFor(r) { return cfg.cols || (r && r.width === 58 ? 32 : 48); }

  function sample() {
    return {
      order_id: 0, date: new Date().toLocaleString(), header: 'BPOS', footer: '', cashier: '', terminal: '', customer: '', width: 58,
      lines: [{ name: 'Test item', options: '', qty: 1, price: '1.00', total: '1.00' }],
      totals: [{ title: 'Total', value: '1.00', big: true }], payment: 'Cash', cash: false, tendered: '', change: '', barcode: false,
      labels: { receipt: t('pr_test_title', 'Test print') }
    };
  }

  /* ---------- transports ---------- */
  function chunks(bytes, size, fn) {
    var p = Promise.resolve();

    for (var i = 0; i < bytes.length; i += size) {
      (function (part) { p = p.then(function () { return fn(part); }); })(bytes.slice(i, i + size));
    }

    return p;
  }

  function serialPort(choose, ask) {
    if (!w.navigator.serial) { return Promise.reject(new Error(t('pr_no_serial', 'This browser does not support Web Serial. Use Chrome or Edge.'))); }

    return (choose ? Promise.resolve([]) : w.navigator.serial.getPorts()).then(function (ports) {
      if (ports.length) { return ports[0]; }
      if (!choose && !ask) { throw new Error(t('pr_not_connected', 'No printer is connected. Open Printer in the account menu and choose one.')); }

      return w.navigator.serial.requestPort();
    });
  }

  function wait(ms) { return new Promise(function (r) { w.setTimeout(r, ms); }); }

  // Bluetooth serial buffers the data, so the port is closed only after a short pause or the tail of the receipt is lost
  function sendSerial(bytes, ask) {
    return serialPort(false, ask).then(function (port) {
      return port.open({ baudRate: 9600 }).then(function () { return wait(300); }).then(function () {
        var writer = port.writable.getWriter();

        return chunks(bytes, 512, function (part) { return writer.write(part); }).then(function () {
          return writer.close();
        }).then(function () {
          return wait(1200);
        }).then(function () {
          return port.close();
        }, function (e) {
          return writer.abort().catch(function () { /* ignore */ }).then(function () { return port.close(); }).catch(function () { /* ignore */ }).then(function () { throw e; });
        });
      });
    });
  }

  function usbDevice(choose, ask) {
    if (!w.navigator.usb) { return Promise.reject(new Error(t('pr_no_usb', 'This browser does not support WebUSB. Use Chrome or Edge.'))); }

    if (choose) {
      return w.navigator.usb.requestDevice({ filters: [] }).then(function (dev) {
        cfg.usb = { v: dev.vendorId, p: dev.productId };
        save();
        return dev;
      });
    }

    return w.navigator.usb.getDevices().then(function (list) {
      var hit = list.filter(function (x) { return cfg.usb && x.vendorId === cfg.usb.v && x.productId === cfg.usb.p; })[0] || list[0];

      if (!hit && ask) { return w.navigator.usb.requestDevice({ filters: [] }).then(function (dev) { cfg.usb = { v: dev.vendorId, p: dev.productId }; save(); return dev; }); }

      if (!hit) { throw new Error(t('pr_not_connected', 'No printer is connected. Open Printer in the account menu and choose one.')); }

      return hit;
    });
  }

  function findOut(dev) {
    var best = null;

    (dev.configuration ? dev.configuration.interfaces : []).forEach(function (ifc) {
      var alt = ifc.alternates[0];

      alt.endpoints.forEach(function (ep) {
        if (ep.direction === 'out' && ep.type === 'bulk' && (!best || alt.interfaceClass === 7)) {
          best = { ifc: ifc.interfaceNumber, ep: ep.endpointNumber };
        }
      });
    });

    return best;
  }

  function sendUsb(bytes, ask) {
    return usbDevice(false, ask).then(function (dev) {
      return dev.open().then(function () {
        return dev.configuration ? null : dev.selectConfiguration(1);
      }).then(function () {
        var target = findOut(dev);

        if (!target) { throw new Error(t('pr_no_endpoint', 'No writable USB endpoint found on this printer.')); }

        return dev.claimInterface(target.ifc).then(function () {
          return chunks(bytes, 4096, function (part) { return dev.transferOut(target.ep, part); });
        }).then(function () {
          return dev.releaseInterface(target.ifc);
        });
      }).then(function () { return dev.close(); }, function (e) {
        return dev.close().then(function () { throw e; }, function () { throw e; });
      });
    });
  }

  function sendRawbt(bytes) {
    var bin = '';

    for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }

    w.location.href = 'rawbt:base64,' + w.btoa(bin);

    return Promise.resolve();
  }

  function send(bytes, ask) {
    if (cfg.mode === 'serial') { return sendSerial(bytes, ask); }
    if (cfg.mode === 'usb') { return sendUsb(bytes, ask); }

    return sendRawbt(bytes);
  }

  /* ---------- public ---------- */
  // Resolves true when the receipt was handled here, false when the caller should open the browser print dialog
  print.receipt = function (htmlUrl) {
    if (cfg.mode === 'dialog') { return Promise.resolve(false); }

    ui.busy(true);

    return B.api(htmlUrl + (htmlUrl.indexOf('?') > -1 ? '&' : '?') + 'format=json').then(function (r) {
      return send(build(r, colsFor(r), cfg.cut));
    }).then(function () {
      ui.toast(t('pr_sent', 'Receipt sent to the printer.'));
    }).catch(function (e) {
      ui.toast((e && e.message) || t('pr_failed', 'Printing failed.'), 'err');
    }).then(function () {
      ui.busy(false);

      return true;
    });
  };

  function setup() {
    var esc = B.esc;
    var local = Object.assign({}, cfg);

    ui.modal(function (box, close) {
      var modes = [['dialog', t('pr_mode_dialog', 'Browser print dialog')], ['serial', t('pr_mode_serial', 'ESC/POS via Web Serial (Bluetooth)')], ['usb', t('pr_mode_usb', 'ESC/POS via WebUSB')], ['rawbt', t('pr_mode_rawbt', 'ESC/POS via RawBT app (Android)')]];

      box.classList.add('pr-box');
      box.innerHTML = '<h2>' + esc(t('pr_title', 'Printer')) + '</h2>' +
        '<p>' + esc(t('pr_hint', 'Saved on this device only.')) + '</p>' +
        '<label class="pr-f"><span>' + esc(t('pr_mode', 'Print method')) + '</span><select id="pr-mode">' + modes.map(function (m) { return '<option value="' + m[0] + '"' + (m[0] === local.mode ? ' selected' : '') + '>' + esc(m[1]) + '</option>'; }).join('') + '</select></label>' +
        '<label class="pr-f"><span>' + esc(t('pr_cols', 'Paper width')) + '</span><select id="pr-cols"><option value="0">' + esc(t('pr_cols_auto', 'Automatic (from terminal)')) + '</option><option value="32">58 mm</option><option value="48">80 mm</option></select></label>' +
        '<label class="pr-c"><input type="checkbox" id="pr-cut"> <span>' + esc(t('pr_cut', 'Cut paper after printing')) + '</span></label>' +
        '<div class="pr-row" id="pr-conn"><button class="btn" type="button" id="pr-choose">' + esc(t('pr_choose', 'Choose printer')) + '</button><button class="btn" type="button" id="pr-test">' + esc(t('pr_test', 'Test print')) + '</button></div>' +
        '<small id="pr-msg" class="pr-msg"></small>' +
        '<div class="pr-row"><button class="btn" type="button" id="pr-cancel">' + esc(t('pr_cancel', 'Cancel')) + '</button><button class="btn pri" type="button" id="pr-save">' + esc(t('pr_save', 'Save')) + '</button></div>';

      var $ = function (id) { return box.querySelector('#' + id); };
      var msg = function (s, bad) { $('pr-msg').textContent = s || ''; $('pr-msg').className = 'pr-msg' + (bad ? ' bad' : ''); };

      $('pr-cols').value = String(local.cols);
      $('pr-cut').checked = !!local.cut;

      function sync() {
        local.mode = $('pr-mode').value;
        $('pr-conn').hidden = local.mode === 'dialog';
        $('pr-choose').hidden = local.mode !== 'serial' && local.mode !== 'usb';
        msg('');
      }

      $('pr-mode').addEventListener('change', sync);
      sync();

      $('pr-choose').addEventListener('click', function () {
        var p = local.mode === 'usb' ? usbDevice(true) : serialPort(true);

        p.then(function () { msg(t('pr_chosen', 'Printer selected.')); }, function (e) { msg(e.message, true); });
      });

      $('pr-test').addEventListener('click', function () {
        var prev = cfg;

        cfg = Object.assign({}, local, { cols: +$('pr-cols').value, cut: $('pr-cut').checked, usb: cfg.usb });

        send(build(sample(), colsFor(sample()), cfg.cut), true).then(function () { msg(t('pr_sent', 'Receipt sent to the printer.')); }, function (e) { msg(e.message, true); }).then(function () { cfg = prev; });
      });

      $('pr-cancel').addEventListener('click', function () { close(null); });
      $('pr-save').addEventListener('click', function () {
        cfg.mode = $('pr-mode').value;
        cfg.cols = +$('pr-cols').value;
        cfg.cut = $('pr-cut').checked;
        save();
        ui.toast(t('pr_saved', 'Printer settings saved.'));
        close(true);
      });
    }, true);
  }

  print.setup = setup;
  print.build = build;

  d.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('#bp-printer')) { setup(); }
  });
})(window, document, window.BPOS);
