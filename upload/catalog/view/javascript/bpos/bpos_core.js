/* BPOS core: namespace, i18n, events, page lifecycle. Loaded once per full page load. */
(function (w, d) {
  if (w.BPOS && w.BPOS._core) { return; }

  var boot = w.BPOS_BOOT || {};
  var leave = [];
  var handlers = {};
  var dirty = null;
  var loaded = {};

  var B = w.BPOS = {
    _core: true,
    boot: boot,

    t: function (key, fallback) {
      var m = boot.i18n || {};
      return m[key] != null ? m[key] : (fallback != null ? fallback : key);
    },

    can: function (cap) {
      return (boot.caps || []).indexOf(cap) !== -1;
    },

    // Page scripts register cleanup here. The router runs and clears it before swapping a page.
    onLeave: function (fn) { leave.push(fn); },

    runLeave: function () {
      var list = leave;
      leave = [];
      handlers = {};
      dirty = null;
      list.forEach(function (fn) { try { fn(); } catch (e) { if (w.console) { console.error(e); } } });
    },

    // Page-scoped events. Handlers are dropped together with the page.
    on: function (name, fn) { (handlers[name] = handlers[name] || []).push(fn); },

    emit: function (name, data) {
      (handlers[name] || []).slice().forEach(function (fn) { try { fn(data); } catch (e) { if (w.console) { console.error(e); } } });
    },

    // fn() returns true while the page holds unsaved edits
    setDirty: function (fn) { dirty = fn; },

    isDirty: function () {
      try { return !!(dirty && dirty()); } catch (e) { return false; }
    },

    // Load an external script once per page load
    load: function (src) {
      var url = d.createElement('a');
      url.href = src;
      var abs = url.href;

      if (!loaded[abs]) {
        loaded[abs] = new Promise(function (resolve, reject) {
          var el = d.createElement('script');
          el.src = abs;
          el.async = false;
          el.onload = function () { resolve(); };
          el.onerror = function () { delete loaded[abs]; reject(new Error('load ' + abs)); };
          d.head.appendChild(el);
        });
      }

      return loaded[abs];
    },

    // Same as load() but always executes: page scripts are small IIFEs that must run on every visit
    run: function (src) {
      return new Promise(function (resolve, reject) {
        var el = d.createElement('script');
        el.src = src;
        el.async = false;
        el.onload = function () { resolve(); };
        el.onerror = function () { reject(new Error('load ' + src)); };
        d.head.appendChild(el);
      });
    },

    // Moves a page's <template data-bp-top> into the header slot (first load; the router does it on navigation)
    mountTop: function (scope) {
      var slot = d.getElementById('bp-top-slot');
      var tpl = (scope || d).querySelector('template[data-bp-top]');

      if (slot && tpl) {
        slot.innerHTML = tpl.innerHTML;
        tpl.parentNode.removeChild(tpl);
      }
    },

    esc: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },

    store: {
      get: function (k) { try { return w.localStorage.getItem(k); } catch (e) { return null; } },
      set: function (k, v) { try { w.localStorage.setItem(k, v); } catch (e) { /* storage unavailable */ } }
    }
  };

  // Scripts already in the document count as loaded
  Array.prototype.forEach.call(d.querySelectorAll('script[src]'), function (s) {
    loaded[s.src] = Promise.resolve();
  });
})(window, document);
