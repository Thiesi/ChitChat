// public/js/toast.js
// Minimal toast system. Zero deps. Auto-injects CSS. ESM-ready.
// Usage: import { toast } from './js/toast.js'; toast.success('Saved');
const css = `
#toasts{ position:fixed; right:16px; bottom:16px; display:flex; flex-direction:column; gap:10px; z-index: 2147483646; }
.toast{ min-width:280px; max-width:420px; background: var(--toast, var(--panel, #111827)); color: var(--fg, #e5e7eb);
  border:1px solid var(--toastBorder, #334155); border-radius:12px; padding:10px 12px; box-shadow: 0 10px 25px rgba(0,0,0,.35);
  display:flex; align-items:flex-start; gap:10px; opacity:0; transform: translateY(10px); transition: opacity .18s ease, transform .18s ease; }
.toast.show{ opacity:1; transform: translateY(0); }
.toast .icon{ font-weight:700; }
.toast .body{ flex:1 }
.toast .close{ cursor:pointer; border:none; background:transparent; color:var(--muted,#9ca3af); font-size:18px; line-height:1; }
.toast.info{ border-left: 4px solid #0ea5e9; }
.toast.success{ border-left: 4px solid #22c55e; }
.toast.warn{ border-left: 4px solid #f59e0b; }
.toast.error{ border-left: 4px solid #ef4444; }`;

let inited = false;
function ensure(){
  if(inited) return;
  const s=document.createElement('style'); s.id='toast-css'; s.textContent=css; document.head.appendChild(s);
  const c=document.createElement('div'); c.id='toasts'; c.setAttribute('aria-live','polite'); c.setAttribute('aria-atomic','true'); document.body.appendChild(c);
  inited = true;
}
function raw(text, type='info', timeout=4500){
  ensure();
  const wrap = document.getElementById('toasts');
  const div = document.createElement('div'); div.className = 'toast '+type;
  div.innerHTML = `<div class="icon">•</div><div class="body"></div><button class="close" aria-label="Close">×</button>`;
  const body = div.querySelector('.body');
  if(typeof text==='string'){ body.textContent = text; }
  else if(text instanceof Node){ body.appendChild(text); }
  else { body.textContent = String(text); }
  wrap.appendChild(div);
  const close = ()=>{ div.classList.remove('show'); setTimeout(()=>div.remove(), 180); };
  div.querySelector('.close').onclick = close;
  setTimeout(()=>div.classList.add('show'), 10);
  if(timeout>0) setTimeout(close, timeout);
  return { close, el: div };
}
export const toast = {
  show: raw,
  info: (t,ms)=>raw(t,'info',ms??4500),
  success: (t,ms)=>raw(t,'success',ms??3500),
  warn: (t,ms)=>raw(t,'warn',ms??6000),
  error: (t,ms)=>raw(t,'error',ms??8000),
  html: (node, type='info', ms=4500)=>raw(node,type,ms),
};
export default toast;
