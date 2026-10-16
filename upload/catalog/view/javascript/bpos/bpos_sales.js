/* Sales page (placeholder): session countdown. Runs on every visit of the page. */
(function () {
  var el = document.getElementById('sl-left');
  if (!el) { return; }

  var end = parseInt(el.getAttribute('data-expires'), 10) * 1000;

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function tick() {
    var left = Math.max(0, Math.round((end - Date.now()) / 1000));
    el.textContent = pad(Math.floor(left / 3600)) + ':' + pad(Math.floor(left % 3600 / 60)) + ':' + pad(left % 60);
  }

  tick();
  var timer = setInterval(tick, 1000);

  BPOS.onLeave(function () { clearInterval(timer); });
})();
