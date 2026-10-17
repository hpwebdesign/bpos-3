/* BPOS.ui: toast, dropdown menus, modal list, confirm, busy bar. */
(function (w, d, B) {
  var ui = B.ui = {};
  var busyTimer = null;

  ui.toast = function (msg, type) {
    var box = d.getElementById('bp-toasts');
    if (!box) { return; }

    var el = d.createElement('div');
    el.className = 'toast' + (type ? ' ' + type : '');
    el.textContent = msg;
    box.appendChild(el);

    setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 3200);
  };

  ui.busy = function (on) {
    clearTimeout(busyTimer);

    if (on) {
      busyTimer = setTimeout(function () { d.body.classList.add('loading'); }, 120);
    } else {
      d.body.classList.remove('loading');
    }
  };

  // Dropdowns: <button data-dd="x"> toggles #bp-menu-x
  function closeMenus(except) {
    Array.prototype.forEach.call(d.querySelectorAll('.ddm'), function (m) {
      if (m !== except) { m.hidden = true; }
    });
  }

  d.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-dd]') : null;

    if (btn) {
      var menu = d.getElementById('bp-menu-' + btn.getAttribute('data-dd'));
      if (menu) {
        var open = menu.hidden;
        closeMenus(menu);
        menu.hidden = !open;
      }
      return;
    }

    if (!e.target.closest || !e.target.closest('.ddm')) { closeMenus(null); }
    else if (e.target.closest('.ddm button')) { closeMenus(null); }
  });

  d.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeMenus(null); }
  });

  function overlay(build, dismissible) {
    var ov = d.getElementById('bp-ov');

    return new Promise(function (resolve) {
      var box = d.createElement('div');
      box.className = 'bp-modal';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');

      function close(value) {
        ov.hidden = true;
        ov.innerHTML = '';
        d.removeEventListener('keydown', onKey);
        resolve(value);
      }

      function onKey(e) { if (dismissible && e.key === 'Escape') { close(null); } }

      build(box, close);

      ov.innerHTML = '';
      ov.appendChild(box);
      ov.hidden = false;
      d.addEventListener('keydown', onKey);

      var first = box.querySelector('button');
      if (first) { first.focus(); }
    });
  };

  // Generic modal: build(box, close) fills the dialog, close(value) resolves the promise
  ui.modal = overlay;

  // items: [{id, label}] -> resolves with the picked id, or null when dismissed
  ui.choose = function (title, items, dismissible) {
    return overlay(function (box, close) {
      var h = d.createElement('h2');
      h.textContent = title;
      box.appendChild(h);

      var list = d.createElement('div');
      list.className = 'bp-list';

      items.forEach(function (it) {
        var b = d.createElement('button');
        b.type = 'button';
        b.innerHTML = '<i class="bi bi-hdd-network"></i><span></span>';
        b.lastChild.textContent = it.label;
        b.addEventListener('click', function () { close(it.id); });
        list.appendChild(b);
      });

      box.appendChild(list);

      if (dismissible) {
        var c = d.createElement('button');
        c.type = 'button';
        c.className = 'btn';
        c.textContent = B.t('cancel');
        c.addEventListener('click', function () { close(null); });
        box.appendChild(c);
      }
    }, dismissible);
  };

  // Resolves true on confirm, false otherwise
  ui.confirm = function (message, okLabel) {
    return overlay(function (box, close) {
      var p = d.createElement('p');
      p.textContent = message;
      p.style.color = 'var(--ink)';
      box.appendChild(p);

      var row = d.createElement('div');
      row.style.cssText = 'display:flex;gap:8px;justify-content:flex-end';

      var no = d.createElement('button');
      no.type = 'button';
      no.className = 'btn';
      no.textContent = B.t('cancel');
      no.addEventListener('click', function () { close(false); });

      var yes = d.createElement('button');
      yes.type = 'button';
      yes.className = 'btn pri';
      yes.textContent = okLabel || 'OK';
      yes.addEventListener('click', function () { close(true); });

      row.appendChild(no);
      row.appendChild(yes);
      box.appendChild(row);
    }, true).then(function (v) { return v === true; });
  };
})(window, document, window.BPOS);

/* BPOS.xlsx(filename, sheets): builds a real .xlsx in the browser (stored zip, no library) and downloads it.
   sheets: [{ name, rows: [[cell, ...], ...] }]. Numbers become numeric cells, everything else inline text. */
(function (w, d, B) {
  var crcTable = null;

  function crc32(bytes) {
    if (!crcTable) {
      crcTable = [];
      for (var n = 0; n < 256; n++) {
        var c = n;
        for (var k = 0; k < 8; k++) { c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; }
        crcTable[n] = c >>> 0;
      }
    }

    var crc = 0xFFFFFFFF;
    for (var i = 0; i < bytes.length; i++) { crc = crcTable[(crc ^ bytes[i]) & 0xFF] ^ (crc >>> 8); }
    return (crc ^ 0xFFFFFFFF) >>> 0;
  }

  function xml(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }).replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '');
  }

  function col(i) {
    var s = '';
    for (i++; i > 0; i = Math.floor((i - 1) / 26)) { s = String.fromCharCode(65 + (i - 1) % 26) + s; }
    return s;
  }

  function sheetXml(rows) {
    var widths = [];

    var data = rows.map(function (row, r) {
      return '<row r="' + (r + 1) + '">' + row.map(function (v, c) {
        var ref = col(c) + (r + 1);
        if (v == null || v === '') { return ''; }
        widths[c] = Math.max(widths[c] || 8, Math.min(48, String(v).length + 2));
        if (typeof v === 'number' && isFinite(v)) { return '<c r="' + ref + '"><v>' + v + '</v></c>'; }
        return '<c r="' + ref + '" t="inlineStr"><is><t xml:space="preserve">' + xml(v) + '</t></is></c>';
      }).join('') + '</row>';
    }).join('');

    var cols = widths.length ? '<cols>' + widths.map(function (wd, i) { return '<col min="' + (i + 1) + '" max="' + (i + 1) + '" width="' + (wd || 8) + '" customWidth="1"/>'; }).join('') + '</cols>' : '';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' + cols + '<sheetData>' + data + '</sheetData></worksheet>';
  }

  function zip(files) {
    var enc = new TextEncoder();
    var parts = [];
    var central = [];
    var offset = 0;

    function u16(n) { return [n & 255, (n >> 8) & 255]; }
    function u32(n) { return [n & 255, (n >> 8) & 255, (n >> 16) & 255, (n >>> 24) & 255]; }

    files.forEach(function (f) {
      var name = enc.encode(f.name);
      var body = enc.encode(f.data);
      var crc = crc32(body);
      var head = [0x50, 0x4B, 3, 4].concat(u16(20), u16(0x0800), u16(0), u16(0), u16(0x21), u32(crc), u32(body.length), u32(body.length), u16(name.length), u16(0));

      parts.push(new Uint8Array(head), name, body);
      central.push(new Uint8Array([0x50, 0x4B, 1, 2].concat(u16(20), u16(20), u16(0x0800), u16(0), u16(0), u16(0x21), u32(crc), u32(body.length), u32(body.length), u16(name.length), u16(0), u16(0), u16(0), u16(0), u32(0), u32(offset))), name);
      offset += head.length + name.length + body.length;
    });

    var size = 0;
    central.forEach(function (c) { size += c.length; });

    return new Blob(parts.concat(central, [new Uint8Array([0x50, 0x4B, 5, 6, 0, 0, 0, 0].concat(u16(files.length), u16(files.length), u32(size), u32(offset), u16(0)))]), { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }

  B.xlsx = function (filename, sheets) {
    var files = [
      { name: '[Content_Types].xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' + sheets.map(function (s, i) { return '<Override PartName="/xl/worksheets/sheet' + (i + 1) + '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; }).join('') + '</Types>' },
      { name: '_rels/.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' },
      { name: 'xl/workbook.xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' + sheets.map(function (s, i) { return '<sheet name="' + xml(String(s.name).replace(/[\\\/?*\[\]:]/g, ' ').slice(0, 31)) + '" sheetId="' + (i + 1) + '" r:id="rId' + (i + 1) + '"/>'; }).join('') + '</sheets></workbook>' },
      { name: 'xl/_rels/workbook.xml.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' + sheets.map(function (s, i) { return '<Relationship Id="rId' + (i + 1) + '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' + (i + 1) + '.xml"/>'; }).join('') + '</Relationships>' }
    ].concat(sheets.map(function (s, i) { return { name: 'xl/worksheets/sheet' + (i + 1) + '.xml', data: sheetXml(s.rows) }; }));

    var a = d.createElement('a');
    a.href = w.URL.createObjectURL(zip(files));
    a.download = filename;
    d.body.appendChild(a);
    a.click();
    setTimeout(function () { w.URL.revokeObjectURL(a.href); d.body.removeChild(a); }, 500);
  };
})(window, document, window.BPOS);
