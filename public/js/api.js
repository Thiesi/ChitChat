// public/js/api.js
import { toast } from './toast.js';
let CSRF = null;
export function setCsrf(token){ CSRF = token; }
async function request(path, { method='GET', body=null, form=false } = {}){
  const headers = {};
  if(CSRF) headers['X-CSRF-Token'] = CSRF;
  let fetchBody = undefined;
  if(body){
    if(form){ fetchBody = body; }
    else { headers['Content-Type'] = 'application/json'; fetchBody = JSON.stringify(body); }
  }
  let res;
  try{
    res = await fetch(`../backend/${path}`, { method, headers, body: fetchBody, credentials: 'include' });
  }catch(err){
    toast.error('Network error: '+(err?.message||err)); throw err;
  }
  if(!res.ok){
    const text = await res.text().catch(()=>String(res.status));
    toast.error(`Error ${res.status}: ${text}`);
    throw new Error(text);
  }
  const ct = res.headers.get('content-type') || '';
  if(ct.includes('application/json')) return res.json();
  return res.text();
}
export const api = { get:(p)=>request(p,{method:'GET'}), post:(p,b)=>request(p,{method:'POST',body:b}), postForm:(p,f)=>request(p,{method:'POST',body:f,form:true}) };
