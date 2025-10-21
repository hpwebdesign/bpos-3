  const $ = (q, ctx=document)=>ctx.querySelector(q);
    const $$ = (q, ctx=document)=>Array.from(ctx.querySelectorAll(q));

    const tabPassword = $('#tab-password');
    const tabPin = $('#tab-pin');
    const panelPassword = $('#panel-password');
    const panelPin = $('#panel-pin');

    const html = document.documentElement;
    const themeToggle = $('#themeToggle');

    function toggleTheme(){
      const isWhite = html.getAttribute('data-theme') === 'white';
      html.setAttribute('data-theme', isWhite ? 'navy' : 'white');
    }

    themeToggle.addEventListener('click', toggleTheme);

    function setTab(which){
      const pinMode = which === 'pin';
      tabPassword.setAttribute('aria-selected', String(!pinMode));
      tabPin.setAttribute('aria-selected', String(pinMode));
      panelPassword.style.display = pinMode ? 'none' : 'block';
      panelPin.style.display = pinMode ? 'block' : 'none';
      if(pinMode){ $('#pinInput').focus(); } else { $('#username').focus(); }
      checkOnlineState();
    }

    tabPassword.addEventListener('click', ()=> setTab('password'));
    tabPin.addEventListener('click', ()=> setTab('pin'));

    const pinInput = $('#pinInput');
    const dots = $$('.pin-dot');

    function renderDots(){
      const v = pinInput.value;
      dots.forEach((d,i)=> d.classList.toggle('filled', i < v.length));
    }

    function addDigit(d){
      if(pinInput.value.length >= 6) return;
      pinInput.value += d;
      renderDots();
      checkOnlineState();
    }

    function backspace(){
      pinInput.value = pinInput.value.slice(0, -1);
      renderDots();
      checkOnlineState();
    }

    function clearPin(){
      pinInput.value = '';
      renderDots();
      checkOnlineState();
    }

    $$('.keypad .key').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const key = btn.getAttribute('data-key');
        const action = btn.getAttribute('data-action');
        if(key){ addDigit(key); return; }
        if(action === 'backspace'){ backspace(); return; }
        if(action === 'clear'){ clearPin(); return; }
      });
    });

    ['username','password'].forEach(id=>{
      $('#'+id).addEventListener('focus',()=> checkOnlineState());
    });

    panelPassword.addEventListener('submit', (e)=>{
      e.preventDefault();
      checkOnlineState();
      alert('Password login submitted');
    });
    panelPin.addEventListener('submit', (e)=>{
      e.preventDefault();
      checkOnlineState();
      if(pinInput.value.length !== 6){
        alert('Please enter a 6-digit PIN');
        return;
      }
      alert('PIN login submitted: ' + pinInput.value.replace(/\\d/g,'•'));
      clearPin();
    });

    const statusDot = $('#statusDot');
    const statusText = $('#statusText');
    function setOnlineState(isOnline){
      statusDot.className = 'dot ' + (isOnline ? 'ok' : 'off');
      statusText.textContent = isOnline ? 'Online' : 'Offline';
    }

    function checkOnlineState(){
      setOnlineState(navigator.onLine);
    }

    window.addEventListener('online', ()=> setOnlineState(true));
    window.addEventListener('offline', ()=> setOnlineState(false));

    setInterval(checkOnlineState, 3000);

    setTab('password');