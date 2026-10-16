/* BPOS router: real URLs, HTML fragments over XHR, pushState. Any failure falls back to a full page load. */
(function (w, d, B) {
  var main = d.getElementById('bp-main');
  if (!main) { return; }

  // Stylesheets of the first page sit inside the fragment; move them to the head so they survive navigation
  Array.prototype.forEach.call(main.querySelectorAll('link[rel="stylesheet"]'), function (l) {
    var dup = Array.prototype.some.call(d.head.querySelectorAll('link[rel="stylesheet"]'), function (x) { return x.href === l.href; });
    if (dup) { l.parentNode.removeChild(l); } else { d.head.appendChild(l); }
  });

  var ctrl = null;
  var seq = 0;
  var current = w.location.href;

  function navigate(href, push, confirmed) {
    var url = new URL(href, w.location.href);

    if (url.origin !== w.location.origin) { w.location.href = href; return; }
    if (url.href === current && push) { return; }

    if (!confirmed && B.isDirty()) {
      B.ui.confirm(B.t('discard', 'Discard unsaved changes?'), B.t('leave', 'Leave')).then(function (ok) {
        if (ok) { navigate(href, push, true); }
        else if (!push) { w.history.pushState({}, '', current); }
      });
      return;
    }

    if (ctrl) { ctrl.abort(); }
    ctrl = new AbortController();

    var mine = ++seq;
    B.ui.busy(true);

    w.fetch(url.href, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
      signal: ctrl.signal
    }).then(function (res) {
      var type = res.headers.get('content-type') || '';

      if (!res.ok || res.redirected || type.indexOf('text/html') === -1) { throw new Error('fallback'); }

      return res.text();
    }).then(function (html) {
      if (mine !== seq) { return; }

      B.runLeave();
      return inject(html).then(function () {
        if (mine !== seq) { return; }
        if (push) { w.history.pushState({}, '', url.href); }
        current = url.href;
        B.ui.busy(false);
        B.emit('navigate', { url: url.href });
      });
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { return; }
      if (mine !== seq) { return; }
      B.ui.busy(false);
      w.location.href = url.href;
    });
  }

  function ensureStyle(href) {
    var exists = Array.prototype.some.call(d.querySelectorAll('link[rel="stylesheet"]'), function (l) { return l.href === href; });

    if (!exists) {
      var link = d.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      d.head.appendChild(link);
    }
  }

  function inject(html) {
    var tpl = d.createElement('template');
    tpl.innerHTML = html;
    var root = tpl.content;

    Array.prototype.forEach.call(root.querySelectorAll('link[rel="stylesheet"]'), function (l) {
      ensureStyle(l.href);
      l.parentNode.removeChild(l);
    });

    Array.prototype.forEach.call(d.head.querySelectorAll('style[data-page-style]'), function (s) { s.parentNode.removeChild(s); });
    Array.prototype.forEach.call(root.querySelectorAll('style[data-page-style]'), function (s) {
      d.head.appendChild(s.cloneNode(true));
      s.parentNode.removeChild(s);
    });

    var slot = d.getElementById('bp-top-slot');
    var top = root.querySelector('template[data-bp-top]');
    if (slot) { slot.innerHTML = top ? top.innerHTML : ''; }
    if (top) { top.parentNode.removeChild(top); }

    var scripts = [];
    Array.prototype.forEach.call(root.querySelectorAll('script'), function (s) {
      scripts.push({ src: s.getAttribute('src') ? s.src : '', text: s.text, page: s.hasAttribute('data-bp-page') });
      s.parentNode.removeChild(s);
    });

    var first = root.firstElementChild;
    var title = first ? first.getAttribute('data-title') : '';
    var page = first ? first.getAttribute('data-page') : '';

    main.innerHTML = '';
    main.appendChild(root);

    setTitle(title);
    setActive(page);

    return scripts.reduce(function (p, s) {
      return p.then(function () {
        if (s.src) { return s.page ? B.run(s.src) : B.load(s.src); }

        var el = d.createElement('script');
        el.text = s.text;
        d.head.appendChild(el);
        d.head.removeChild(el);
      });
    }, Promise.resolve());
  }

  function setTitle(title) {
    if (!title) { return; }
    d.title = title + ' · ' + (B.boot.brand || 'Barik POS');
  }

  function setActive(page) {
    Array.prototype.forEach.call(d.querySelectorAll('a[data-route]'), function (a) {
      a.classList.toggle('on', a.getAttribute('data-route') === page);
    });
    d.body.setAttribute('data-page', page || '');
  }

  d.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }

    var a = e.target.closest ? e.target.closest('a[data-route]') : null;
    if (!a || (a.target && a.target !== '_self')) { return; }

    e.preventDefault();
    navigate(a.href, true);
  });

  w.addEventListener('popstate', function () { navigate(w.location.href, false); });

  w.addEventListener('beforeunload', function (e) {
    if (B.isDirty()) { e.preventDefault(); e.returnValue = ''; }
  });

  B.router = { go: function (href) { navigate(href, true); } };
})(window, document, window.BPOS);
