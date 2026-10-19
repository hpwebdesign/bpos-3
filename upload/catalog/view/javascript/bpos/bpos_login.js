/* BPOS sign-in page. Strings, URLs and the language list come from #bpos-login-data. */
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var DATA = {};
  try { DATA = JSON.parse($('bpos-login-data').textContent); } catch (e) { DATA = { i18n: {}, urls: {}, langs: [], lang: 'en-gb' }; }

  var LS = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* storage unavailable */ } }
  };
  var t = function (k) { return DATA.i18n[k] != null ? DATA.i18n[k] : k; };
  var S = { mode: 'pw', pin: '', lockUntil: 0, errKey: null, errExtra: '', busy: false };

  /* theme */
  function curTheme() {
    var a = document.documentElement.getAttribute('data-theme');
    if (a) { return a; }
    return window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function paintTheme() { $('theme').innerHTML = '<i class="bi bi-' + (curTheme() === 'dark' ? 'sun' : 'moon-stars') + '"></i>'; }
  $('theme').addEventListener('click', function () {
    var n = curTheme() === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', n);
    LS.set('bpos.theme', n);
    paintTheme();
  });

  /* language: the server renders every string, so cycle the cookie and reload */
  $('lang').addEventListener('click', function () {
    var list = DATA.langs || [];
    if (list.length < 2) { return; }
    var next = list[(list.indexOf(DATA.lang) + 1) % list.length];
    document.cookie = 'bpos_lang=' + encodeURIComponent(next) + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    location.reload();
  });
  if ((DATA.langs || []).length < 2) { $('lang').style.display = 'none'; }

  /* clock */
  function tick() {
    var d = new Date();
    $('clock').firstChild.nodeValue = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    try { $('date').textContent = d.toLocaleDateString(DATA.lang, { weekday: 'short', day: 'numeric', month: 'short' }); } catch (e) { $('date').textContent = ''; }
  }
  tick();
  setInterval(tick, 20000);

  /* sign-in mode */
  function setMode(m) {
    S.mode = m;
    clearErr();
    $('mPw').classList.toggle('on', m === 'pw');
    $('mPin').classList.toggle('on', m === 'pin');
    $('mPw').setAttribute('aria-selected', m === 'pw');
    $('mPin').setAttribute('aria-selected', m === 'pin');
    $('pwBox').hidden = m !== 'pw';
    $('pinBox').hidden = m !== 'pin';
    $('forgot').hidden = m !== 'pw';
    if (m === 'pin') { drawDots(); } else { $('pw').focus(); }
  }
  $('mPw').addEventListener('click', function () { setMode('pw'); });
  $('mPin').addEventListener('click', function () { setMode('pin'); });

  /* password */
  $('eye').addEventListener('click', function () {
    var show = $('pw').type === 'password';
    $('pw').type = show ? 'text' : 'password';
    $('eye').innerHTML = '<i class="bi bi-eye' + (show ? '-slash' : '') + '"></i>';
    $('eye').setAttribute('aria-label', t(show ? 'hide_pw' : 'show_pw'));
  });
  $('pw').addEventListener('keyup', function (e) { if (e.getModifierState) { $('caps').hidden = !e.getModifierState('CapsLock'); } });
  $('pw').addEventListener('blur', function () { $('caps').hidden = true; });
  $('forgot').addEventListener('click', function () { toast(t('forgot')); });

  /* PIN */
  function drawDots() {
    var h = '';
    for (var i = 0; i < 6; i++) { h += '<i class="' + (i < S.pin.length ? 'on' : '') + '"></i>'; }
    $('dots').innerHTML = h;
    $('dots').setAttribute('aria-label', 'PIN ' + S.pin.length);
  }
  function pinKey(k) {
    if (S.busy) { return; }
    clearErr();
    if (k === 'del') { S.pin = S.pin.slice(0, -1); }
    else if (k === 'clr') { S.pin = ''; }
    else if (S.pin.length < 6) { S.pin += k; }
    drawDots();
  }
  $('pad').addEventListener('click', function (e) {
    var b = e.target.closest('[data-k]');
    if (b) { pinKey(b.dataset.k); }
  });
  document.addEventListener('keydown', function (e) {
    if (S.mode !== 'pin' || e.ctrlKey || e.metaKey || e.altKey || document.activeElement === $('user')) { return; }
    if (/^\d$/.test(e.key)) { pinKey(e.key); e.preventDefault(); }
    else if (e.key === 'Backspace') { pinKey('del'); e.preventDefault(); }
    else if (e.key === 'Escape') { pinKey('clr'); }
  });

  /* messages */
  function showErr(key, extra) {
    S.errKey = key;
    S.errExtra = extra || '';
    var box = $('err');
    box.hidden = false;
    box.classList.toggle('lock', key === 'err_locked');
    box.querySelector('.bi').className = 'bi bi-' + (key === 'err_locked' ? 'hourglass-split' : 'exclamation-circle');
    $('errTxt').textContent = key === 'err_locked' ? t(key) + ' ' + extra : t(key);
  }
  function clearErr() {
    S.errKey = null;
    $('err').hidden = true;
    $('user').classList.remove('err');
    $('pw').classList.remove('err');
    $('dots').classList.remove('err');
  }
  function fail(key, which) {
    showErr(key);
    if (which) { $(which).classList.add('err'); }
    var c = $('card');
    c.classList.remove('shake');
    void c.offsetWidth;
    c.classList.add('shake');
  }
  var tt;
  function toast(m) {
    var e = $('toast');
    e.textContent = m;
    e.hidden = false;
    clearTimeout(tt);
    tt = setTimeout(function () { e.hidden = true; }, 2600);
  }
  $('user').addEventListener('input', clearErr);
  $('pw').addEventListener('input', clearErr);

  /* lockout countdown, driven by the server's retry value */
  var lockTimer;
  function lockLoop(seconds) {
    clearInterval(lockTimer);
    S.lockUntil = Date.now() + seconds * 1000;
    function step() {
      var left = Math.ceil((S.lockUntil - Date.now()) / 1000);
      if (left <= 0) {
        clearInterval(lockTimer);
        clearErr();
        $('go').disabled = false;
        return;
      }
      showErr('err_locked', Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2));
      $('go').disabled = true;
    }
    step();
    lockTimer = setInterval(step, 1000);
  }

  /* requests */
  function post(url, data, csrf) {
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (csrf) { headers['X-BPOS-Token'] = csrf; }

    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(data) }).then(function (res) {
      return res.text().then(function (text) {
        var body = {};
        try { body = text ? JSON.parse(text) : {}; } catch (e) { body = {}; }
        return { status: res.status, body: body };
      });
    });
  }

  function spin(on, label) {
    var sp = $('go').querySelector('.spin');
    if (on && !sp) { $('go').insertAdjacentHTML('afterbegin', '<span class="spin"></span>'); }
    if (!on && sp) { sp.remove(); }
    $('goTxt').textContent = label;
  }

  function finish(next) {
    S.busy = true;
    $('go').disabled = true;
    spin(true, t('welcome'));
    setTimeout(function () { location.href = next; }, 500);
  }

  /* terminal chooser, shown when the store has several terminals and none is remembered */
  function chooseTerminal(res) {
    ['user', 'pw'].forEach(function (id) { $(id).closest('.field').hidden = true; });
    document.querySelector('.seg2').hidden = true;
    $('pwBox').hidden = true;
    $('pinBox').hidden = true;
    document.querySelector('.card .row').hidden = true;
    $('go').hidden = true;
    $('adm').hidden = true;
    $('termBox').hidden = false;

    var list = $('termList');
    list.innerHTML = '';

    res.choose.forEach(function (term) {
      var b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = '<i class="bi bi-hdd-network"></i><span></span>';
      b.lastChild.textContent = term.name;
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(list.children, function (x) { x.disabled = true; });
        post(DATA.urls.terminal, { terminal_id: parseInt(term.terminal_id, 10) }, res.csrf).then(function (r) {
          if (r.body && r.body.ok) { finish(res.next); }
          else { Array.prototype.forEach.call(list.children, function (x) { x.disabled = false; }); showErr('err_generic'); }
        }, function () {
          Array.prototype.forEach.call(list.children, function (x) { x.disabled = false; });
          showErr('err_network');
        });
      });
      list.appendChild(b);
    });

    list.firstChild.focus();
  }

  function afterAuth(res) {
    if (res.choose && res.choose.length) { chooseTerminal(res); }
    else { finish(res.next); }
  }

  /* submit */
  $('card').addEventListener('submit', function (e) {
    e.preventDefault();
    if (S.busy || Date.now() < S.lockUntil) { return; }
    clearErr();

    var u = $('user').value.trim();
    if (!u) { fail('err_username', 'user'); $('user').focus(); return; }
    if (S.mode === 'pw' && !$('pw').value) { fail('err_password', 'pw'); $('pw').focus(); return; }
    if (S.mode === 'pin' && S.pin.length < 4) { fail('err_pin', 'dots'); return; }

    S.busy = true;
    $('go').disabled = true;
    spin(true, t('signing_in'));

    var pin = S.mode === 'pin';

    post(pin ? DATA.urls.pin : DATA.urls.login, { username: u, secret: pin ? S.pin : $('pw').value }).then(function (r) {
      S.busy = false;
      $('go').disabled = false;
      spin(false, $('goTxt').getAttribute('data-label') || $('goTxt').textContent);

      var b = r.body || {};

      if (b.ok) {
        LS.set('bpos.user', $('rem').checked ? u : '');
        afterAuth(b);
        return;
      }

      S.pin = '';
      drawDots();

      if (b.code === 'locked' || b.code === 'blocked') { lockLoop(parseInt(b.retry, 10) || 60); return; }
      if (b.code === 'invalid') { fail(pin ? 'err_invalid_pin' : 'err_invalid_pw', pin ? 'dots' : 'pw'); return; }

      showErr('err_generic');
      if (b.message) { $('errTxt').textContent = b.message; }
    }, function () {
      S.busy = false;
      $('go').disabled = false;
      spin(false, $('goTxt').getAttribute('data-label'));
      showErr('err_network');
    });
  });

  $('goTxt').setAttribute('data-label', $('goTxt').textContent);

  /* signed in to OpenCart admin: one-click entry as owner */
  if (!$('adm').hidden) {
    $('admGo').addEventListener('click', function () {
      if (S.busy) { return; }
      S.busy = true;
      $('admGo').disabled = true;
      toast(t('opening_admin'));

      post(DATA.urls.admin, { nonce: DATA.nonce }).then(function (r) {
        var b = r.body || {};
        if (b.ok) { afterAuth(b); S.busy = false; return; }
        S.busy = false;
        $('admGo').disabled = false;
        showErr('err_generic');
      }, function () {
        S.busy = false;
        $('admGo').disabled = false;
        showErr('err_network');
      });
    });
  }

  var ru = LS.get('bpos.user');
  if (ru) { $('user').value = ru; $('rem').checked = true; }
  paintTheme();
  drawDots();
  (ru ? $('pw') : $('user')).focus();

  /* scene */
  var svg = $('town');
  function fit() { svg.setAttribute('viewBox', window.innerWidth <= 880 ? '40 270 660 500' : '0 0 1600 900'); svg.setAttribute('preserveAspectRatio', 'xMidYMax meet'); }
  fit();
  window.addEventListener('resize', fit);

  if (!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) {
    window.addEventListener('pointermove', function (e) {
      var s = document.documentElement.style;
      s.setProperty('--mx', ((e.clientX / window.innerWidth) - .5).toFixed(3));
      s.setProperty('--my', ((e.clientY / window.innerHeight) - .5).toFixed(3));
    }, { passive: true });
  }

  // Delivery robot: the setting turns it off; the scene itself always renders.
  var doodle = document.body.getAttribute('data-doodle') !== '0';
  var van = document.querySelector('#town .van');
  if (!doodle && van) { van.remove(); }

  // Long store names shrink to fit the sign
  var st = $('signTxt');
  function fitSign() {
    st.removeAttribute('textLength');
    var w = st.getComputedTextLength ? st.getComputedTextLength() : 0;
    if (w > 380) { st.setAttribute('textLength', 380); st.setAttribute('lengthAdjust', 'spacingAndGlyphs'); }
  }
  fitSign();
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitSign); }
})();
