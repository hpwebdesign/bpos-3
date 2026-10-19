/* BPOS app boot: theme, language, account menu, terminal choice, heartbeat. */
(function (w, d, B) {
  var boot = B.boot;
  var ui = B.ui;

  function $(id) { return d.getElementById(id); }

  // Theme
  function curTheme() {
    var a = d.documentElement.getAttribute('data-theme');
    if (a) { return a; }
    return w.matchMedia && w.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function paintTheme() {
    var b = $('bp-theme');
    if (b) { b.querySelector('i').className = 'bi bi-' + (curTheme() === 'dark' ? 'sun' : 'moon-stars'); }
  }

  if ($('bp-theme')) {
    $('bp-theme').addEventListener('click', function () {
      var next = curTheme() === 'dark' ? 'light' : 'dark';
      d.documentElement.setAttribute('data-theme', next);
      B.store.set('bpos.theme', next);
      paintTheme();
    });
    paintTheme();
  }

  // Language: the server renders every string, so switching reloads the page
  Array.prototype.forEach.call(d.querySelectorAll('[data-lang]'), function (b) {
    b.addEventListener('click', function () {
      d.cookie = 'bpos_lang=' + encodeURIComponent(b.getAttribute('data-lang')) + '; path=/; max-age=31536000; SameSite=Lax' + (w.location.protocol === 'https:' ? '; Secure' : '');
      w.location.reload();
    });
  });

  // Currency: amounts are priced on the server, so switching reloads the page
  Array.prototype.forEach.call(d.querySelectorAll('[data-cur]'), function (b) {
    b.addEventListener('click', function () {
      d.cookie = 'bpos_currency=' + encodeURIComponent(b.getAttribute('data-cur')) + '; path=/; max-age=31536000; SameSite=Lax' + (w.location.protocol === 'https:' ? '; Secure' : '');
      w.location.reload();
    });
  });

  // Sign out
  if ($('bp-logout')) {
    $('bp-logout').addEventListener('click', function () {
      B._ending = true;
      B.api(boot.urls.logout, { method: 'POST', data: {} }).then(function (res) {
        w.location.href = res.next || boot.urls.login;
      }, function () {
        w.location.href = boot.urls.login;
      });
    });
  }

  // Terminal
  function paintTerminal(t) {
    boot.terminal = t;
    var sub = $('bp-sub');
    if (sub) { sub.textContent = boot.store.name + (t.name ? ' · ' + t.name : ''); }
    var acct = $('bp-acct-terminal');
    if (acct) { acct.textContent = acct.textContent.split(':')[0] + ': ' + (t.name || acct.getAttribute('data-none') || ''); }
    B.emit('terminal', t);
  }

  function pickTerminal(list, dismissible) {
    var items = list.map(function (t) { return { id: t.terminal_id, label: t.name }; });

    return ui.choose(B.t('choose_terminal'), items, dismissible).then(function (id) {
      if (id === null) {
        return dismissible ? null : pickTerminal(list, false);
      }

      return B.api(boot.urls.terminal, { method: 'POST', data: { terminal_id: id } }).then(function (res) {
        paintTerminal(res.terminal);
        ui.toast(B.t('terminal_saved'));
      }, function (err) {
        ui.toast(err.message, 'err');
        return dismissible ? null : pickTerminal(list, false);
      });
    });
  }

  if ($('bp-acct-terminal')) { $('bp-acct-terminal').setAttribute('data-none', $('bp-acct-terminal').textContent.split(': ').slice(1).join(': ')); }

  if ($('bp-change-terminal')) {
    $('bp-change-terminal').addEventListener('click', function () { pickTerminal(boot.terminals, true); });
  }

  if (boot.choices && boot.choices.length) { pickTerminal(boot.choices, false); }

  // Keep the session informed; a dead session sends the user to sign in
  var beat = setInterval(function () {
    B.api(boot.urls.heartbeat, { method: 'POST', data: {} }).then(function (res) {
      if (res.role !== boot.user.role) { w.location.reload(); }
    }, function () { /* offline: the banner below covers it */ });
  }, 60000);

  w.addEventListener('beforeunload', function () { clearInterval(beat); });

  w.addEventListener('offline', function () { ui.toast(B.t('offline'), 'err'); });
})(window, document, window.BPOS);
