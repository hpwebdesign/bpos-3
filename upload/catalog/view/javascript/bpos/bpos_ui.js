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
