(function ($) {
  var $root = $('#bps-root');
  if (!$root.length) { return; }

  var saving = false;
  var FAIL = $root.data('fail');
  var URL = { save: $root.data('save'), logo: $root.data('logo'), patch: $root.data('patch'), remove: $root.data('remove') };

  // Success goes to a toast in the top-right corner. Errors stay in the page until dismissed.
  function toast(text) {
    var $box = $('#bps-toasts');
    if (!$box.length) { $box = $('<div id="bps-toasts" aria-live="polite"></div>').appendTo('body'); }
    var $t = $('<div class="bps-toast" role="status"><i class="fa fa-check-circle"></i><span></span></div>');
    $t.find('span').text(text);
    $box.append($t);
    setTimeout(function () { $t.addClass('bps-toast-in'); }, 10);
    var close = function () { $t.removeClass('bps-toast-in'); setTimeout(function () { $t.remove(); }, 250); };
    $t.on('click', close);
    setTimeout(close, 3500);
  }

  function alertBox(type, text) {
    if (type === 'success') { $('#bps-alert').empty(); toast(text); return; }
    var $a = $('<div class="alert alert-' + type + ' alert-dismissible"><i class="fa fa-exclamation-circle"></i> <span></span><button type="button" class="close" aria-label="Close">&times;</button></div>');
    $a.find('span').text(text);
    $('#bps-alert').empty().append($a);
  }
  $(document).on('click', '#bps-alert .close', function () { $(this).closest('.alert').remove(); });

  // Tabs
  function showTab(name) {
    $root.find('.bps-sn [data-bps-tab]').removeClass('bps-on').filter('[data-bps-tab="' + name + '"]').addClass('bps-on');
    $root.find('.bps-pane').removeClass('bps-on').filter('#bps-' + name).addClass('bps-on');
    // Full URL: OpenCart's <base> tag would resolve a bare hash to the admin root
    try { history.replaceState(null, '', window.location.pathname + window.location.search + '#bps-' + name); } catch (e) { /* ignore */ }
  }
  $root.on('click', '[data-bps-tab]', function (e) { e.preventDefault(); showTab($(this).data('bps-tab')); });

  // Language switch inside the Text card
  var curLang = $('#bps-lang-seg button.bps-on').data('bps-lang');
  function showLang(code) {
    curLang = code;
    $('#bps-lang-seg button').removeClass('bps-on').filter('[data-bps-lang="' + code + '"]').addClass('bps-on');
    $('.bps-lang-pane').removeClass('bps-on').filter('[data-bps-lang-pane="' + code + '"]').addClass('bps-on');
    preview();
  }
  $root.on('click', '[data-bps-lang]', function () { showLang($(this).data('bps-lang')); });

  // Live preview of the sign-in text and logo
  function preview() {
    var $pane = $('.bps-lang-pane[data-bps-lang-pane="' + curLang + '"]');
    var $pv = $('#bps-pv');
    var h = $.trim($pane.find('[data-bps-head]').val()) || $pv.data('def-head');
    var d = $.trim($pane.find('[data-bps-desc]').val()) || $pv.data('def-desc');
    $('#bps-pv-h').text(h);
    $('#bps-pv-p').text(d);
  }
  $root.on('input', '[data-bps-head], [data-bps-desc]', preview);

  $('#bps-reset-text').on('click', function () {
    $('[data-bps-head], [data-bps-desc]').val('');
    preview();
    syncDirty();
  });

  // Slug: keep it lowercase, drop invalid characters, refresh the copy links
  $('#bps-slug').on('input', function () {
    var v = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
    if (v !== this.value) { this.value = v; }
    $('#bps-urls code').each(function () {
      var $c = $(this), cur = String($c.data('bps-url'));
      var base = cur.replace(/index\.php\?route=bpos\/auth$/, '').replace(/[^\/]*$/, '');
      var next = v ? base + v : base + 'index.php?route=bpos/auth';
      $c.data('bps-url', next).text(next);
    });
  });

  $root.on('click', '[data-bps-copy]', function () {
    var text = String($(this).closest('.bps-url').find('code').data('bps-url'));
    var $btn = $(this), old = $btn.text();
    var done = function () { $btn.text('\u2713'); setTimeout(function () { $btn.text(old); }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () {});
    } else {
      var $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
      $t[0].select(); try { document.execCommand('copy'); done(); } catch (e) {} $t.remove();
    }
  });

  // Logo upload
  function setLogo(path, url) {
    $('#bps-logo-path').val(path);
    $('#bps-tile').empty().append(url ? $('<img alt="">').attr('src', url) : '<span class="bps-def">B</span>');
    $('#bps-pv-logo').empty().append(url ? $('<img alt="">').attr('src', url) : document.createTextNode('B'));
    $('#bps-logo-rm').prop('hidden', !path);
    syncDirty();
    var $label = $('#bps-logo-up span');
    $label.text(path ? $label.attr('data-change') : $label.attr('data-upload'));
  }
  $('#bps-logo-up').on('click', function () { $('#bps-logo-file').trigger('click'); });
  $('#bps-logo-rm').on('click', function () { setLogo('', ''); });
  $('#bps-logo-file').on('change', function () {
    var file = this.files && this.files[0];
    if (!file) { return; }
    var fd = new FormData();
    fd.append('logo', file);
    var input = this;
    $.ajax({ url: URL.logo, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
      .done(function (r) {
        if (r.success) { setLogo(r.path, r.url); showErr({ module_bpos_login_logo: '' }); }
        else { showErr({ module_bpos_login_logo: r.error }); }
      })
      .fail(function () { alertBox('danger', FAIL); })
      .always(function () { input.value = ''; });
  });

  // Dirty state: the Save button stays disabled until a field changes
  var baseline = {};
  function flat() {
    var out = {};
    $root.find('[data-bps-field]').each(function () {
      var $f = $(this), name = $f.attr('name');
      if ($f.is(':radio')) { if ($f.is(':checked')) { out[name] = $f.val(); } }
      else if ($f.is(':checkbox')) { out[name] = $f.is(':checked') ? 1 : 0; }
      else { out[name] = $f.val(); }
    });
    return out;
  }
  function stable(o) { return JSON.stringify(Object.keys(o).sort().map(function (k) { return [k, o[k]]; })); }
  function syncDirty() {
    var d = stable(flat()) !== stable(baseline);
    $('#bps-save').prop('disabled', !d || saving);
    $('#bps-un').prop('hidden', !d);
  }
  $root.on('input change', '[data-bps-field]', syncDirty);

  // Collect every [data-bps-field] into a nested object (name, name[a] or name[a][b])
  function collect() {
    var out = {};
    $root.find('[data-bps-field]').each(function () {
      var $f = $(this), name = $f.attr('name'), val;
      if ($f.is(':radio')) { if (!$f.is(':checked')) { return; } val = $f.val(); }
      else if ($f.is(':checkbox')) { val = $f.is(':checked') ? 1 : 0; }
      else { val = $f.val(); }
      var keys = name.replace(/\]/g, '').split('[');
      var node = out;
      for (var i = 0; i < keys.length - 1; i++) { node[keys[i]] = node[keys[i]] || {}; node = node[keys[i]]; }
      node[keys[keys.length - 1]] = val;
    });
    return out;
  }

  function showErr(errors) {
    $root.find('.has-bps-err').removeClass('has-bps-err');
    $root.find('[data-bps-err]').text('');
    var first = null;
    $.each(errors || {}, function (name, msg) {
      var $e = $root.find('[data-bps-err="' + name.replace(/"/g, '') + '"]');
      $e.text(msg);
      if (msg) { $e.closest('.bps-row, .bps-card').find('.form-control').first().addClass('has-bps-err'); if (!first) { first = $e; } }
    });
    if (first) {
      var $pane = first.closest('.bps-pane');
      if ($pane.length) { showTab($pane.attr('id').replace('bps-', '')); }
      var $lp = first.closest('.bps-lang-pane');
      if ($lp.length) { showLang($lp.data('bps-lang-pane')); }
    }
  }

  $('#bps-save').on('click', function () {
    if (saving) { return; }
    saving = true;
    var $b = $(this).prop('disabled', true);
    $.ajax({ url: URL.save, type: 'POST', data: JSON.stringify(collect()), contentType: 'application/json', dataType: 'json' })
      .done(function (r) {
        if (r.success) { showErr({}); alertBox('success', r.message); baseline = flat(); }
        else { showErr(r.errors); alertBox('danger', r.error || 'Error'); }
      })
      .fail(function () { alertBox('danger', FAIL); })
      .always(function () { saving = false; syncDirty(); });
  });

  $('#bps-patch').on('click', function () {
    var $b = $(this).prop('disabled', true);
    $.ajax({ url: URL.patch, type: 'POST', dataType: 'json' })
      .done(function (r) { alertBox(r.success ? 'success' : 'danger', r.message || r.error); })
      .fail(function () { alertBox('danger', FAIL); })
      .always(function () { $b.prop('disabled', false); });
  });

  $('#bps-confirm').on('input', function () { $('#bps-remove').prop('disabled', $.trim(this.value) !== 'bpos'); });
  $('#bps-remove').on('click', function () {
    var $b = $(this).prop('disabled', true);
    $.ajax({ url: URL.remove, type: 'POST', data: JSON.stringify({ confirm: $.trim($('#bps-confirm').val()) }), contentType: 'application/json', dataType: 'json' })
      .done(function (r) {
        if (r.success && r.redirect) { window.location = r.redirect; return; }
        alertBox(r.success ? 'success' : 'danger', r.message || r.error);
        $b.prop('disabled', false);
      })
      .fail(function () { alertBox('danger', FAIL); $b.prop('disabled', false); });
  });


  // ---------------------------------------------------------------
  // Terminal selector (Display, Receipt, Scanner share one choice)
  // ---------------------------------------------------------------
  function showDev(id) {
    id = String(id);
    $('.bps-dev-sel').val(id);
    $('.bps-dev').removeClass('bps-on').filter('[data-bps-dev="' + id + '"]').addClass('bps-on');
    drawReceipt();
  }
  $root.on('change', '.bps-dev-sel', function () { showDev(this.value); });
  $root.on('click', '[data-bps-config]', function () { showDev($(this).data('bps-config')); showTab('display'); });

  // ---------------------------------------------------------------
  // Receipt preview, built from the current form values
  // ---------------------------------------------------------------
  function money(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function drawReceipt() {
    var $p = $('#bps-paper');
    if (!$p.length) { return; }
    var paper = $('.bps-dev.bps-on [data-bps-paper]:checked').val() || '58';
    var on = function (k) { return $root.find('[name="module_bpos_rc_' + k + '"]').is(':checked'); };
    var header = $.trim($root.find('[name="module_bpos_rc_header"]').val()) || $p.data('store');
    var footer = $root.find('[name="module_bpos_rc_footer"]').val() || '';
    var tax = 120;
    var $h = $('<div>');
    var line = function (a, b, cls) { return $('<div class="rl">').addClass(cls || '').append($('<span>').text(a), $('<span>').text(b)); };
    var pc = function (t, cls) { return $('<div class="pc">').addClass(cls || '').text(t); };
    var dash = function () { return $('<div class="pd">'); };

    if (on('logo')) { $h.append('<div class="plogo"><i class="fa fa-shopping-bag"></i></div>'); }
    $.each(String(header).split('\n'), function (i, t) { $h.append(pc(t, i === 0 ? 'b' : '')); });
    $h.append(dash(), line($p.data('l-order') + ' #1046', '16 Oct 2026'));
    if (on('cashier')) { $h.append(line($p.data('l-cashier'), 'Rina')); }
    $h.append(dash(), $('<div class="pi">').text('MacBook Air'), line('1 x ' + money(1000), money(1000)), $('<div class="pi">').text('iPod Nano'), line('2 x ' + money(100), money(200)), dash(), line($p.data('l-sub'), money(1200)));
    if (on('tax')) { $h.append(line($p.data('l-tax'), money(tax))); }
    $h.append(line($p.data('l-total'), money(1200 + (on('tax') ? tax : 0)), 'b'), line($p.data('l-cash'), money(1500)), line($p.data('l-change'), money(1500 - 1200 - (on('tax') ? tax : 0))), dash(), pc(footer));
    if (on('barcode')) { $h.append('<div class="pbar"></div>'); }
    $p.attr('class', 'bps-paper w' + paper).empty().append($h.children());
    $('#bps-rc-w').text(paper);
  }
  $root.on('input change', '[data-bps-rc], [data-bps-paper]', drawReceipt);

  // ---------------------------------------------------------------
  // POS users and terminals (own forms, no modal dependency).
  // Saving never reloads the page: the affected panes are fetched again and swapped in place.
  // ---------------------------------------------------------------
  function postJson(url, body) {
    return $.ajax({ url: url, type: 'POST', data: JSON.stringify(body), contentType: 'application/json', dataType: 'json' });
  }

  function fieldsOf($form, attr) {
    var out = {};
    $form.find('[' + attr + ']').each(function () {
      var $f = $(this);
      out[$f.attr('name')] = $f.is(':checkbox') ? ($f.is(':checked') ? 1 : 0) : $f.val();
    });
    return out;
  }

  function showFormErrors($form, errAttr, errors) {
    $form.find('[' + errAttr + ']').text('');
    $.each(errors || {}, function (name, msg) { $form.find('[' + errAttr + '="' + name + '"]').text(msg); });
  }

  // Panes that depend on users or terminals
  var LIVE = ['stores', 'terminals', 'display', 'receipt', 'scan'];

  function setField($f, value) {
    if ($f.is(':radio')) { $f.prop('checked', String($f.val()) === String(value)); }
    else if ($f.is(':checkbox')) { $f.prop('checked', !!Number(value)); }
    else { $f.val(value); }
  }

  function refreshPanes(done) {
    var dev = $('.bps-dev-sel').first().val();
    var keep = flat();

    $.ajax({ url: window.location.pathname + window.location.search, type: 'GET', dataType: 'html' })
      .done(function (html) {
        var $doc = $('<div>').append($.parseHTML(html));
        var ok = true;
        $.each(LIVE, function (i, id) { if (!$doc.find('#bps-' + id).length) { ok = false; } });
        if (!ok) { alertBox('danger', FAIL); return; }

        var before = baseline;
        $.each(LIVE, function (i, id) { $('#bps-' + id).html($doc.find('#bps-' + id).html()); });

        // Keep what was typed in terminal fields that still exist
        var fresh = flat();
        $root.find('[data-bps-field]').each(function () {
          var name = $(this).attr('name');
          if (Object.prototype.hasOwnProperty.call(keep, name)) { setField($(this), keep[name]); }
        });

        // New fields count as saved values, removed ones are forgotten
        baseline = {};
        $.each(fresh, function (name, value) {
          baseline[name] = Object.prototype.hasOwnProperty.call(before, name) ? before[name] : value;
        });

        var $first = $('.bps-dev-sel').first();
        showDev($('.bps-dev-sel option[value="' + dev + '"]').length ? dev : $first.val());
        syncDirty();
        if (done) { done(); }
      })
      .fail(function () { alertBox('danger', FAIL); });
  }

  // Two-click delete: the first click arms the button, the second one confirms
  function armDelete($btn, run) {
    if ($btn.data('armed')) { run(); return; }
    var html = $btn.html();
    $btn.data('armed', 1).addClass('btn-danger').text($btn.closest('[data-confirm]').data('confirm'));
    setTimeout(function () { $btn.data('armed', 0).removeClass('btn-danger').html(html); }, 3500);
  }

  function runAction($btn, request, $form, errAttr) {
    $btn.prop('disabled', true);
    request
      .done(function (r) {
        if (r.success) { refreshPanes(function () { alertBox('success', r.message); }); return; }
        if ($form) { showFormErrors($form, errAttr, r.errors); }
        alertBox('danger', r.error || 'Error');
      })
      .fail(function () { alertBox('danger', FAIL); })
      .always(function () { $btn.prop('disabled', false); });
  }

  // Users
  function openUser(u) {
    var $uf = $('#bps-user-form');
    u = u || { user_id: 0, name: '', username: '', role: 'cashier', store_id: 0, status: 1, has_pin: 0 };
    $uf.find('[name=user_id]').val(u.user_id);
    $uf.find('[name=name]').val(u.name);
    $uf.find('[name=username]').val(u.username);
    $uf.find('[name=password]').val('');
    $uf.find('[name=pin]').val('');
    $uf.find('[name=role]').val(u.role);
    $uf.find('[name=store_id]').val(String(u.store_id));
    $uf.find('[name=status]').prop('checked', !!Number(u.status));
    $uf.find('[name=clear_pin]').prop('checked', false);
    $('#bps-clearpin-wrap').prop('hidden', !Number(u.has_pin));
    $('#bps-pw-help').text(u.user_id ? $('#bps-pw-help').data('edit') : '');
    showFormErrors($uf, 'data-bps-uerr', {});
    $uf.prop('hidden', false);
    $uf.find('[name=name]').trigger('focus');
  }
  $root.on('click', '#bps-user-add', function () { openUser(); });
  $root.on('click', '#bps-user-cancel', function () { $('#bps-user-form').prop('hidden', true); });
  $root.on('click', '[data-bps-user-edit]', function () { openUser($(this).closest('[data-bps-user]').data('bps-user')); });
  $root.on('click', '#bps-user-save', function () {
    var $uf = $('#bps-user-form');
    runAction($(this), postJson($('#bps-users-card').data('save'), fieldsOf($uf, 'data-bps-uf')), $uf, 'data-bps-uerr');
  });
  $root.on('click', '[data-bps-user-del]', function () {
    var id = $(this).data('bps-user-del'), $btn = $(this);
    armDelete($btn, function () { runAction($btn, postJson($('#bps-users-card').data('del'), { user_id: id })); });
  });

  // Terminals. The first terminal of a fresh install is suggested as "Default".
  function openTerm(t) {
    var $tf = $('#bps-term-form');
    t = t || { terminal_id: 0, name: $('#bps-term-card [data-bps-term]').length ? '' : $tf.data('default'), store_id: 0, status: 1 };
    $tf.find('[name=terminal_id]').val(t.terminal_id);
    $tf.find('[name=name]').val(t.name);
    $tf.find('[name=store_id]').val(String(t.store_id));
    $tf.find('[name=status]').prop('checked', !!Number(t.status));
    showFormErrors($tf, 'data-bps-terr', {});
    $tf.prop('hidden', false);
    $tf.find('[name=name]').trigger('focus');
  }
  $root.on('click', '#bps-term-add', function () { openTerm(); });
  $root.on('click', '#bps-term-cancel', function () { $('#bps-term-form').prop('hidden', true); });
  $root.on('click', '[data-bps-term-edit]', function () { openTerm($(this).closest('[data-bps-term]').data('bps-term')); });
  $root.on('click', '#bps-term-save', function () {
    var $tf = $('#bps-term-form');
    runAction($(this), postJson($('#bps-term-card').data('save'), fieldsOf($tf, 'data-bps-tf')), $tf, 'data-bps-terr');
  });
  $root.on('click', '[data-bps-term-del]', function () {
    var id = $(this).data('bps-term-del'), $btn = $(this);
    armDelete($btn, function () { runAction($btn, postJson($('#bps-term-card').data('del'), { terminal_id: id })); });
  });

  // Restore the tab from the address and show a message left by the last action
  var hash = String(window.location.hash || '').replace('#bps-', '');
  if (hash && $('#bps-' + hash).hasClass('bps-pane')) { showTab(hash); }
  var $firstDev = $('.bps-dev-sel').first();
  if ($firstDev.length) { showDev($firstDev.val()); }

  preview();
  baseline = flat();
  syncDirty();
})(jQuery);
