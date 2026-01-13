<?php
// includes/chat.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$me = $_SESSION['username'] ?? (isset($_SESSION['user_id']) ? 'User#'.$_SESSION['user_id'] : null);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/chat-widget.css">

<style>
  /* tiny inline bits just for the report UI */
  .c-head { display:flex; align-items:center; gap:8px; }
  .c-time { margin-left:auto; color: var(--muted); font-size: 12px; }
  .c-kebab {
    border: 1px solid var(--border); background: var(--panel-2);
    color: var(--muted); border-radius: 6px; padding: 0 6px; height: 22px;
    cursor: pointer; margin-left: 6px; line-height: 20px;
  }
  .c-menu { position: relative; }
  .c-menu-list {
    position:absolute; right:0; top:24px; min-width: 120px; z-index: 5;
    background: var(--panel); border:1px solid var(--border); border-radius: 8px;
    padding: 6px; display:none;
  }
  .c-menu.open .c-menu-list { display:block; }
  .c-menu-item {
    display:block; width:100%; text-align:left; cursor:pointer;
    border:1px solid transparent; background:transparent; color:var(--text);
    border-radius:6px; padding:6px 8px; font-size:13px;
  }
  .c-menu-item:hover { background: var(--panel-2); border-color: var(--border); }
  .c-menu-item[disabled] { opacity:.6; cursor:default; }
</style>

<div id="chat-widget" class="chat-widget collapsed" aria-live="polite">
  <div class="chat-frame">
    <div class="chat-header">
      <div class="chat-title">Chat</div>
      <div class="chat-tabs" role="tablist">
        <button class="chat-tab active" data-channel="global">Global</button>
        <button class="chat-tab" data-channel="ads">Ads</button>
        <button class="chat-tab" data-channel="support">Support</button>
        <button class="chat-tab" data-channel="guild">Guild</button>
      </div>
      <button id="chat-close" class="chat-icon" title="Hide">✕</button>
    </div>

    <div class="chat-scroll" id="chatLog"></div>

    <form id="chatForm" class="chat-input" autocomplete="off">
      <input type="text" id="chatMsg" placeholder="<?= $me ? 'Type here…' : 'Login to chat' ?>" <?= $me ? '' : 'disabled' ?> maxlength="500">
      <button class="chat-send" type="submit" <?= $me ? '' : 'disabled' ?> title="Send">➤</button>
    </form>
  </div>

  <button id="chat-toggle" class="chat-toggle">Show Chat</button>
</div>

<script>
(function(){
  const W    = document.getElementById('chat-widget');
  const log  = document.getElementById('chatLog');
  const form = document.getElementById('chatForm');
  const input= document.getElementById('chatMsg');
  const btnT = document.getElementById('chat-toggle');
  const btnX = document.getElementById('chat-close');
  const tabs = Array.from(document.querySelectorAll('.chat-tab'));

  if (!W || !log) return;

  const STATE_KEY = 'eldvar.chat.state';
  const CACHE_KEY = (ch)=> `eldvar.chat.cache.${ch}`;
  const MAX_CACHE = 120;

  let channel = 'global';
  let lastId  = 0;
  let busy    = false;

  // restore collapsed + channel
  try {
    const s = JSON.parse(localStorage.getItem(STATE_KEY) || '{}');
    if (s.collapsed) W.classList.add('collapsed');
    if (s.channel)   channel = s.channel;
  } catch(e){}

  // tab init
  tabs.forEach(b=>{
    b.classList.toggle('active', b.dataset.channel===channel);
    b.addEventListener('click', ()=>{
      if (b.dataset.channel === channel) return;
      channel = b.dataset.channel;
      tabs.forEach(t=>t.classList.toggle('active', t===b));
      saveState();
      switchChannel(true);
    });
  });

  function saveState(){
    try { localStorage.setItem(STATE_KEY, JSON.stringify({collapsed: W.classList.contains('collapsed'), channel})); } catch(e){}
  }

  // toggle
  btnT.addEventListener('click', ()=>{ W.classList.remove('collapsed'); btnT.textContent=''; saveState(); });
  btnX.addEventListener('click', ()=>{ W.classList.add('collapsed'); btnT.textContent='Show Chat'; saveState(); });

  // helpers
  const me = <?= json_encode($me) ?>;
  const esc = (s)=>s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  const initials = (n)=> (n||'U').trim().split(/\s+/).slice(0,2).map(p=>p[0]?.toUpperCase()||'U').join('');

  function render(msg){
    lastId = Math.max(lastId, msg.id);
    const mine = me && msg.username === me;

    const row = document.createElement('div');
    row.className = 'c-row' + (mine ? ' mine' : '');
    row.dataset.id = msg.id;

    const time = new Date(msg.ts*1000).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});

    row.innerHTML = `
      <div class="c-avatar">${initials(msg.username)}</div>
      <div class="c-bubble">
        <div class="c-head">
          <span class="c-name">${esc(msg.username)}</span>
          <span class="c-time">${time}</span>
          ${!mine ? `
            <div class="c-menu">
              <button type="button" class="c-kebab" aria-haspopup="true" aria-expanded="false" title="More" data-id="${msg.id}">⋯</button>
              <div class="c-menu-list" role="menu">
                <button class="c-menu-item" data-act="report" data-id="${msg.id}">Report</button>
              </div>
            </div>` : ``}
        </div>
        <div class="c-body">${esc(msg.body)}</div>
      </div>
    `;
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
  }

  function saveCache(list){
    try { localStorage.setItem(CACHE_KEY(channel), JSON.stringify(list.slice(-MAX_CACHE))); } catch(e){}
  }
  function loadCache(){
    try {
      const raw = localStorage.getItem(CACHE_KEY(channel));
      const a = raw ? JSON.parse(raw) : [];
      return Array.isArray(a) ? a : [];
    } catch(e){ return []; }
  }
  function merge(a,b){
    const m = new Map();
    a.forEach(x=>m.set(x.id,x));
    b.forEach(x=>m.set(x.id,x));
    return Array.from(m.values()).sort((x,y)=>x.id-y.id);
  }

  function renderList(list){
    log.innerHTML = '';
    list.forEach(render);
  }

  function switchChannel(fromTab=false){
    // close any open menus when switching
    closeMenus();

    // show cache instantly
    let cache = loadCache();
    if (cache.length){ renderList(cache); lastId = cache[cache.length-1].id; } else { log.innerHTML=''; lastId=0; }

    // fetch history for channel
    fetch(`<?= BASE_URL ?>/chat/history.php?channel=${encodeURIComponent(channel)}`)
      .then(r=>r.json()).then(j=>{
        const history = j.messages || [];
        const merged  = merge(cache, history);
        if (merged.length !== cache.length) { renderList(merged); lastId = merged.length ? merged[merged.length-1].id : 0; }
        saveCache(merged);
        // restart poll
        poll();
      }).catch(()=>{ poll(); });
  }

  function poll(){
    if (busy) return; busy = true;
    fetch(`<?= BASE_URL ?>/chat/poll.php?channel=${encodeURIComponent(channel)}&since=${lastId}`)
      .then(r=>r.json())
      .then(j=>{
        const incoming = (j && j.messages) ? j.messages : [];
        if (incoming.length){
          incoming.forEach(render);
          // update cache
          const cache = merge(loadCache(), incoming);
          saveCache(cache);
          lastId = cache.length ? cache[cache.length-1].id : lastId;
        }
      })
      .catch(()=>{})
      .finally(()=>{ busy = false; setTimeout(poll, 50); });
  }

  // send
  if (form) {
    form.addEventListener('submit', (e)=>{
      e.preventDefault();
      const body = (input.value||'').trim();
      if (!body) return;
      fetch('<?= BASE_URL ?>/chat/post.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({body, channel})
      }).then(r=>r.json()).then(j=>{
        if (j.ok) input.value='';
      });
    });
  }

  // ------- Report UI (menu + handler) -------
  function closeMenus(){
    log.querySelectorAll('.c-menu.open').forEach(m=>m.classList.remove('open'));
  }

  // open/close kebab menu + handle report click
  log.addEventListener('click', (e)=>{
    const kebab = e.target.closest('.c-kebab');
    if (kebab) {
      const menu = kebab.parentElement; // .c-menu
      const open = menu.classList.contains('open');
      closeMenus();
      menu.classList.toggle('open', !open);
      return;
    }
    const item = e.target.closest('.c-menu-item');
    if (item && item.dataset.act === 'report' && !item.disabled) {
      const msgId = parseInt(item.dataset.id, 10);
      doReport(msgId, item);
      closeMenus();
    } else {
      // click elsewhere closes menus
      const inMenu = e.target.closest('.c-menu');
      if (!inMenu) closeMenus();
    }
  });

  function doReport(messageId, btnEl){
    const reason = (prompt('Report this message. Please describe the issue:') || '').trim();
    if (!reason) return;

    btnEl.disabled = true;
    fetch('<?= BASE_URL ?>/chat/report.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ message_id: messageId, reason, channel })
    })
    .then(r=>r.json())
    .then(j=>{
      if (j && j.ok) {
        btnEl.textContent = 'Reported';
      } else {
        btnEl.disabled = false;
        alert(j && j.error ? j.error : 'Could not file report.');
      }
    })
    .catch(()=>{ btnEl.disabled = false; alert('Network error filing report.'); });
  }
  // ------------------------------------------

  // boot
  switchChannel();
})();
</script>
