// public/js/ui.js
// Small DOM helpers, modal handling, and form utils. Zero deps.
export const $ = (sel, root=document)=>root.querySelector(sel);
export const $$ = (sel, root=document)=>Array.from(root.querySelectorAll(sel));

export function el(tag, props={}, children=[]){
  const n = document.createElement(tag);
  Object.entries(props).forEach(([k,v])=>{
    if(k==='class') n.className = v;
    else if(k==='style' && typeof v==='object') Object.assign(n.style, v);
    else if(k.startsWith('on') && typeof v==='function') n.addEventListener(k.slice(2), v);
    else if(v!==undefined && v!==null) n.setAttribute(k, v);
  });
  (Array.isArray(children)?children:[children]).forEach(c=>{
    if(c==null) return;
    if(typeof c==='string') n.appendChild(document.createTextNode(c));
    else n.appendChild(c);
  });
  return n;
}

export function openModal(contentNode){
  let wrap = $('#modal-wrap');
  if(!wrap){
    wrap = el('div', { id:'modal-wrap', style:{ position:'fixed', inset:'0', background:'#0008', display:'flex', alignItems:'center', justifyContent:'center', zIndex: 2147483645 }});
    document.body.appendChild(wrap);
  }
  wrap.innerHTML='';
  const card = el('div',{ class:'modal-card', style:{ background:'var(--panel,#111827)', color:'var(--fg,#e5e7eb)', border:'1px solid var(--border,#334155)', borderRadius:'12px', padding:'12px', minWidth:'420px', maxWidth:'90vw', maxHeight:'85vh', overflow:'auto' }}, [
    el('div', {style:{display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:'8px'}}, [
      el('strong',{},'Dialog'),
      el('button', {onClick: closeModal, style:{border:'none', background:'transparent', color:'var(--muted,#9ca3af)', fontSize:'18px', cursor:'pointer'}}, '×')
    ]),
    contentNode
  ]);
  wrap.appendChild(card);
  wrap.style.display='flex';
  return { close: closeModal };
}
export function closeModal(){
  const wrap = $('#modal-wrap');
  if(wrap){ wrap.style.display='none'; wrap.innerHTML=''; }
}

export function formToJSON(form){
  const data = new FormData(form);
  const obj = {};
  data.forEach((v,k)=>{
    if(obj[k]!==undefined){
      if(!Array.isArray(obj[k])) obj[k]=[obj[k]];
      obj[k].append(v);
    } else obj[k]=v;
  });
  return obj;
}

export function confirmDialog(text, onYes){
  const body = el('div',{},[ el('div',{},text), el('div',{style:{display:'flex',gap:'8px',justifyContent:'flex-end',marginTop:'10px'}},[
    el('button',{onClick:closeModal},'Cancel'),
    el('button',{onClick:()=>{ closeModal(); onYes && onYes(); }, style:{borderColor:'#ef4444'}},'Confirm')
  ]) ]);
  return openModal(body);
}
