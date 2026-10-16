/* BPOS.api: fetch wrapper with CSRF header and uniform errors. */
(function (w, B) {
  function fail(status, body) {
    var err = new Error((body && body.message) || B.t('err_generic'));
    err.status = status;
    err.code = (body && body.code) || 'server';
    err.fields = (body && body.fields) || {};
    err.data = body || {};
    return err;
  }

  // opts: method, data (object, sent as JSON), signal
  B.api = function (url, opts) {
    opts = opts || {};

    var method = (opts.method || (opts.data ? 'POST' : 'GET')).toUpperCase();
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
    var init = { method: method, credentials: 'same-origin', headers: headers };

    if (method !== 'GET') { headers['X-BPOS-Token'] = (B.boot && B.boot.csrf) || ''; }
    if (opts.data !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.data);
    }
    if (opts.signal) { init.signal = opts.signal; }

    return w.fetch(url, init).then(function (res) {
      return res.text().then(function (text) {
        var body = null;
        try { body = text ? JSON.parse(text) : {}; } catch (e) { body = null; }

        if (res.status === 401) {
          B.sessionEnded();
          throw fail(401, body);
        }

        if (res.status === 419) {
          w.location.reload();
          throw fail(419, body);
        }

        if (!res.ok || !body || body.ok === false) {
          throw fail(res.status, body);
        }

        return body;
      });
    }, function (e) {
      if (e && e.name === 'AbortError') { throw e; }
      var err = new Error(B.t('err_network'));
      err.code = 'network';
      err.status = 0;
      throw err;
    });
  };

  // Cart state is persisted by the sales page, so a plain reload to the sign-in page loses nothing
  B.sessionEnded = function () {
    if (B._ending) { return; }
    B._ending = true;
    w.location.href = (B.boot.urls && B.boot.urls.login) || w.location.href;
  };
})(window, window.BPOS);
