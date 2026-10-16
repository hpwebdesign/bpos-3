
$(function () {
  // Tabs & panels
  var $tabPassword   = $('#tab-password');
  var $tabPin        = $('#tab-pin');
  var $panelPassword = $('#panel-password');
  var $panelPin      = $('#panel-pin');

  // Theme toggle
  var $html        = $(document.documentElement);
  var $themeToggle = $('#themeToggle');

  function toggleTheme() {
    var isWhite = $html.attr('data-theme') === 'white';
    $html.attr('data-theme', isWhite ? 'navy' : 'white');
  }
  $themeToggle.on('click', toggleTheme);

  // Switch tab
  function setTab(which) {
    
    var pinMode = (which === 'pin');

    $tabPassword.attr('aria-selected', String(!pinMode));
    $tabPin.attr('aria-selected', String(pinMode));

    if (pinMode) {
      $panelPassword.hide();
      $panelPin.show();
      $('#pinInput').focus();
    } else {
      $panelPassword.show();
      $panelPin.hide();
      $('#username').focus();
    }
    checkOnlineState();
  }
  $tabPassword.on('click', function(){ $('.alert-login').html(''); setTab('password'); });
  $tabPin.on('click', function(){ $('.alert-login').html(''); setTab('pin'); });

  // PIN keypad
 var $pinInput = $('#pinInput');
var $dots     = $('.pin-dot');

function renderDots() {
  var v = $pinInput.val() || '';
  $dots.each(function(i, el){
    $(el).toggleClass('filled', i < v.length);
  });
}

function addDigit(d) {
  var current = $pinInput.val() || '';
  if (current.length >= 6) return;
  $pinInput.val(current + d);
  renderDots();
  checkOnlineState();
}

function backspace() {
  var current = $pinInput.val() || '';
  $pinInput.val(current.slice(0, -1));
  renderDots();
  checkOnlineState();
}

function clearPin() {
  $pinInput.val('');
  renderDots();
  checkOnlineState();
}

// 🔥 PENTING: pakai DELEGATION ke document
$(document).on('click', '.keypad .key', function (e) {
  e.preventDefault();

  const key    = this.dataset.key;
  const action = this.dataset.action;

  if (key) {
    addDigit(key);
  } else if (action === 'backspace') {
    backspace();
  } else if (action === 'clear') {
    clearPin();
  }
});

  // Focus listeners to refresh status
  $.each(['username','password','pin-username'], function(_, id){
  $('#' + id).on('focus', function(){ checkOnlineState(); });
});

  // Submit handlers
  // $panelPassword.on('submit', function(e){
  //   e.preventDefault();
  //   checkOnlineState();
  //   // alert('Password login submitted');
  // });

  // $panelPin.on('submit', function(e){
  //   e.preventDefault();
  //   checkOnlineState();
  //   if ($pinInput.val().length !== 6) {
  //     alert('Please enter a 6-digit PIN');
  //     return;
  //   }
  //    this.submit();
  //   clearPin();

  // });

  // Online status
  var $statusDot  = $('#statusDot');
  var $statusText = $('#statusText');

  function setOnlineState(isOnline) {
    $statusDot.attr('class', 'dot ' + (isOnline ? 'ok' : 'off'));
    $statusText.text(isOnline ? 'Online' : 'Offline');
  }

  function checkOnlineState() {
    setOnlineState(navigator.onLine);
  }

  $(window).on('online',  function(){ setOnlineState(true);  });
  $(window).on('offline', function(){ setOnlineState(false); });

  // Check every 3 seconds
  setInterval(checkOnlineState, 3000);

  // Init
  setTab('password');
  renderDots();
});
