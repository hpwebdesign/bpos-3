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

  /* ---------- logo ---------- */
  var logoCache = {};

  // Store logo as a 1-bit raster at most half the paper width. 58 mm prints 384 dots, 80 mm prints 576 dots.
  function logoRaster(url, cols) {
    var key = url + '|' + cols;

    if (!logoCache[key]) {
      logoCache[key] = new Promise(function (resolve, reject) {
        var img = new w.Image();

        img.crossOrigin = 'anonymous';
        img.onload = function () {
          try {
            var scale = Math.min((cols >= 48 ? 288 : 192) / img.naturalWidth, 120 / img.naturalHeight);
            var wd = Math.max(8, Math.ceil(img.naturalWidth * scale / 8) * 8);
            var ht = Math.max(1, Math.round(img.naturalHeight * scale));
            var cv = d.createElement('canvas');

            cv.width = wd;
            cv.height = ht;

            var ctx = cv.getContext('2d');

            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, wd, ht);
            ctx.drawImage(img, 0, 0, wd, ht);

            var px = ctx.getImageData(0, 0, wd, ht).data;
            var wb = wd / 8;
            var bits = new Uint8Array(wb * ht);

            for (var y = 0; y < ht; y++) {
              for (var x = 0; x < wd; x++) {
                var i = (y * wd + x) * 4;

                if (0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2] < 150) { bits[y * wb + (x >> 3)] |= 0x80 >> (x & 7); }
              }
            }

            resolve({ w: wd, h: ht, wb: wb, data: bits });
          } catch (e) { reject(e); }
        };
        img.onerror = function () { reject(new Error('logo')); };
        img.src = url;
      });

      logoCache[key].catch(function () { delete logoCache[key]; });
    }

    return logoCache[key];
  }

  // CODE39 as the printer draws it (GS k 4): 2 dot narrow bars, 4 dot wide bars, *text* with start and stop marks.
  var C39 = { '0': 'nnnwwnwnn', '1': 'wnnwnnnnw', '2': 'nnwwnnnnw', '3': 'wnwwnnnnn', '4': 'nnnwwnnnw', '5': 'wnnwwnnnn', '6': 'nnwwwnnnn', '7': 'nnnwnnwnw', '8': 'wnnwnnwnn', '9': 'nnwwnnwnn', '*': 'nwnnwnwnn' };

  // Canvas preview of the barcode: 60 dots high, 12 printer dots per character column
  function barcodeCanvas(text) {
    var parts = ('*' + text + '*').split('');
    var cv = d.createElement('canvas');
    var x = 0;
    var runs = [];

    parts.forEach(function (ch, n) {
      (C39[ch] || C39['0']).split('').forEach(function (k, i) {
        var wd = k === 'w' ? 4 : 2;

        if (i % 2 === 0) { runs.push([x, wd]); }
        x += wd;
      });

      if (n < parts.length - 1) { x += 2; }
    });

    cv.width = x;
    cv.height = 60;

    var ctx = cv.getContext('2d');

    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, x, 60);
    ctx.fillStyle = '#000';
    runs.forEach(function (r) { ctx.fillRect(r[0], 0, r[1], 60); });
    cv.style.width = (x / 12) + 'ch';
    cv.style.display = 'block';
    cv.style.margin = '8px auto 2px';

    return cv;
  }

  // Adds the raster to the receipt when it has a logo URL. A logo that fails to load is skipped.
  function withLogo(r, cols) {
    if (!r.logo) { return Promise.resolve(r); }

    return logoRaster(r.logo, cols).then(function (g) { r.logoRaster = g; return r; }, function () { return r; });
  }

  // One layout feeds both the printer bytes and the admin preview. Entries: { s, c } text (c = centred) or { bar } barcode.
  function layout(r, cols) {
    var out = [];
    var hr = new Array(cols + 1).join('-');
    var L = r.labels || {};
    var add = function (arr, c) { arr.forEach(function (s) { out.push({ s: s, c: !!c }); }); };
    var txt = function (s) { out.push({ s: ascii(s), c: false }); };

    if (r.logoRaster) { out.push({ img: r.logoRaster }); }
    add(wrap(r.header || '', cols), true);
    txt(hr);
    txt((L.receipt || 'Receipt') + ' #' + r.order_id);
    txt(r.date);
    if (r.cashier) { add(wrap((L.cashier || 'Cashier') + ': ' + r.cashier, cols)); }
    if (r.terminal) { add(wrap((L.terminal || 'Terminal') + ': ' + r.terminal, cols)); }
    if (r.customer) { add(wrap((L.customer || 'Customer') + ': ' + r.customer, cols)); }
    txt(hr);

    r.lines.forEach(function (l) {
      add(wrap(l.name, cols));
      if (l.options) { wrap(l.options, cols - 2).forEach(function (x) { txt('  ' + x); }); }
      add(pad('  ' + l.qty + ' x ' + l.price, l.total, cols));
    });

    txt(hr);
    r.totals.forEach(function (x) { add(pad(x.title, x.value, cols)); });
    txt(hr);
    add(pad(L.payment || 'Payment', r.payment, cols));

    if (r.cash) {
      add(pad(L.received || 'Received', r.tendered, cols));
      add(pad(L.change || 'Change', r.change, cols));
    }

    if (r.footer) {
      txt(hr);
      add(wrap(r.footer, cols), true);
    }

    if (r.barcode) { out.push({ bar: String(r.order_id) }); }

    return out;
  }

  function build(r, cols, cut) {
    var o = [];
    var align = 0;
    var raw = function () { for (var i = 0; i < arguments.length; i++) { o.push(arguments[i]); } };

    raw(0x1b, 0x40, 0x1b, 0x74, 0);

    layout(r, cols).forEach(function (x) {
      if (x.img) {
        raw(0x1b, 0x61, 1, 0x1d, 0x76, 0x30, 0, x.img.wb & 255, x.img.wb >> 8, x.img.h & 255, x.img.h >> 8);
        for (var k = 0; k < x.img.data.length; k++) { o.push(x.img.data[k]); }
        raw(0x1b, 0x61, 0);
        align = 0;

        return;
      }

      if (x.bar !== undefined) {
        raw(0x1b, 0x61, 1, 10, 0x1d, 0x68, 60, 0x1d, 0x77, 2, 0x1d, 0x48, 2, 0x1d, 0x6b, 4);
        x.bar.split('').forEach(function (c) { raw(c.charCodeAt(0)); });
        raw(0, 10, 0x1b, 0x61, 0);
        align = 0;

        return;
      }

      var a = x.c ? 1 : 0;

      if (a !== align) { raw(0x1b, 0x61, a); align = a; }

      for (var i = 0; i < x.s.length; i++) { o.push(x.s.charCodeAt(i)); }
      o.push(10);
    });

    if (align) { raw(0x1b, 0x61, 0); }
    // The cutter sits about 2 cm behind the print head, so the last lines need a fixed feed before the cut
    raw(0x1b, 0x64, cut ? 6 : 3);
    if (cut) { raw(0x1d, 0x56, 66, 0); }

    return new Uint8Array(o);
  }

  function colsFor(r) { return cfg.cols || (r && r.width === 58 ? 32 : 48); }

  function sample() {
    return {
      order_id: 0, date: new Date().toLocaleString(), header: 'BPOS', footer: '', cashier: '', terminal: '', customer: '', width: 58,
      lines: [{ name: 'Test item', options: '', qty: 1, price: '1.00', total: '1.00' }],
      totals: [{ title: 'Total', value: '1.00' }], payment: 'Cash', cash: false, tendered: '', change: '', barcode: false,
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
          return wait(600);
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
      return withLogo(r, colsFor(r)).then(function () {
        return send(build(r, colsFor(r), r.cut == null ? cfg.cut : !!r.cut));
      });
    }).then(function () {
      ui.toast(t('pr_sent', 'Receipt sent to the printer.'));
    }).catch(function (e) {
      ui.toast((e && e.message) || t('pr_failed', 'Printing failed.'), 'err');
    }).then(function () {
      ui.busy(false);

      return true;
    });
  };

  // Settings-page test: o = { mode, cols, cut, receipt }. Always lets the browser ask for a printer.
  print.test = function (o) {
    var prev = cfg;
    var done = function () { var usb = cfg.usb; cfg = prev; cfg.usb = usb; save(); };

    cfg = Object.assign({}, prev, { mode: o.mode, cols: o.cols, cut: !!o.cut });

    var cols = o.cols || colsFor(o.receipt);

    return withLogo(o.receipt, cols).then(function (r) {
      return send(build(r, cols, cfg.cut), true);
    }).then(done, function (e) { done(); throw e; });
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
  print.layout = layout;
  print.logo = logoRaster;
  print.barcode = barcodeCanvas;

  d.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('#bp-printer')) { setup(); }
  });
})(window, document, window.BPOS);
