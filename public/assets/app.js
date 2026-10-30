'use strict';
const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const paths = {
  tree:'M12 3v6m-7 6v-4h14v4M12 9v6M3 16h4v5H3zm7 0h4v5h-4zm7 0h4v5h-4z',
  route:'M6 5h8a4 4 0 0 1 0 8H9a4 4 0 0 0 0 8h9M3 3h4v4H3zm14 16 3 2-3 2',
  plug:'m9 3 0 5m6-5v5M7 8h10v4a5 5 0 0 1-10 0zm5 9v5',
  upload:'M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5',
  download:'M12 3v13m-5-5 5 5 5-5M4 16v5h16v-5',
  publish:'m4 11 8-8 8 8M12 3v14M4 17v4h16v-4',
  plus:'M12 5v14M5 12h14', search:'m21 21-5-5M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0',
  spark:'m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4z',
  document:'M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6',
  folder:'M3 6h7l2 3h9v11H3z', arrow:'M4 12h16m-6-6 6 6-6 6',
  focus:'M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5M9 9h6v6H9z',
  grip:'M8 5h1m6 0h1M8 12h1m6 0h1M8 19h1m6 0h1',
  play:'m7 4 14 8-14 8z', reset:'M3 11a9 9 0 1 1 3 8M3 4v7h7',
  info:'M12 11v6m0-10v.1M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  close:'m6 6 12 12M6 18 18 6', edit:'m4 16 12-12 4 4L8 20H4zm9-9 4 4',
  link:'m9 15 6-6m-7 3-3 3a4 4 0 0 0 6 6l3-3m-4-12 3-3a4 4 0 0 1 6 6l-3 3',
  check:'m5 12 4 4L20 5', trash:'M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7',
  copy:'M8 8h12v13H8zM16 8V3H3v13h5', clock:'M12 6v6l4 2M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0'
};
const icon = name => `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${paths[name] || paths.document}"/></svg>`;
function icons(root = document) { $$('[data-icon]', root).forEach(e => e.innerHTML = icon(e.dataset.icon)); }
icons();
const S = {page:document.body.dataset.page, tree:null, selected:null, edit:false, collapsed:new Set(), summaryNode:null, scope:null, zoom:null, settings:{}, queue:Promise.resolve(), history:[], historyIndex:-1, visited:new Set(), current:new Set(), traceIndex:-1, responseTab:'readable', demo:0};
let toastTimer;
// The tree editor (editor.js) replaces these hooks; the lab and settings pages use the defaults.
S.hooks = {select: async id => { S.selected = id; renderGraph(); }, flush: async () => {}, tree: () => {}, init: async () => {}};
function toast(message, error = false) { clearTimeout(toastTimer); $('#toast-region').innerHTML = `<div class="toast${error ? ' error' : ''}">${esc(message)}</div>`; toastTimer = setTimeout(() => $('#toast-region').replaceChildren(), error ? 8000 : 4000); }
async function api(url, data, method) {
  const response = await fetch(url, {method:method || (data === undefined ? 'GET' : 'POST'), headers:{'Content-Type':'application/json','X-CSRF-Token':$('meta[name=csrf-token]').content}, ...(data === undefined ? {} : {body:JSON.stringify(data)})});
  let result; try { result = await response.json(); } catch { throw new Error('The server returned an unreadable response.'); }
  if (!response.ok) { const error = new Error(result.error?.message || 'The request failed.'); error.payload = result; throw error; }
  return result;
}
function nodeById(id) { return S.tree?.nodes.find(n => n.id === id); }
function children(parent) { return (S.tree?.nodes || []).filter(n => n.parent_id === parent).sort((a,b) => a.sort_order-b.sort_order || a.title.localeCompare(b.title)); }
function nodePath(node) { let parts = [node.slug], seen = new Set(); while (node.parent_id && !seen.has(node.id)) { seen.add(node.id); node = nodeById(node.parent_id); if (!node) break; parts.unshift(node.slug); } return 'viking://' + parts.join('/'); }
function isDraft() { return S.tree?.version === 'draft'; }
function descendants(id) { const result = new Set([id]); let size; do { size = result.size; S.tree.nodes.forEach(n => { if (result.has(n.parent_id)) result.add(n.id); }); } while (size !== result.size); return result; }
function kindIcon(node) { return node.kind === 'collection' ? 'tree' : node.kind === 'folder' ? 'folder' : 'document'; }
function markdown(content) {
  return String(content).split(/\n\s*\n/).map(block => {
    const m = block.match(/^(#{1,6}) (.+)$/); if (m) return `<h3>${esc(m[2])}</h3>`;
    return `<p>${esc(block).replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>').replace(/`([^`]+)`/g,'<code>$1</code>')}</p>`;
  }).join('');
}
async function loadTree(version, preserveEditor = false) {
  S.tree = await api('/api/tree?version=' + encodeURIComponent(version || S.tree?.version || (S.page === 'lab' ? 'latest' : 'draft')));
  if (!nodeById(S.selected)) S.selected = nodeById('demo_step1') ? 'demo_step1' : S.tree.nodes[0]?.id;
  const select = $(S.page === 'lab' ? '#lab-version' : '#workspace-version');
  if (select) { select.innerHTML = `<option value="draft">Working draft</option>` + S.tree.versions.map(v => `<option value="${esc(v.version)}">${esc(v.label)}${v.version === S.tree.published_version ? ' · Latest' : ''}</option>`).join(''); select.value = S.tree.version; }
  S.hooks.tree(preserveEditor); renderGraph();
}
function mutate(url, body, method) {
  const task = S.queue.catch(() => {}).then(async () => { if ($('#save-state')) $('#save-state').textContent = 'Saving…'; const result = await api(url, {...body, revision:S.tree.revision}, method); await loadTree(S.tree.version, true); return result; });
  S.queue = task; return task;
}
function layoutGraph() {
  let leaf = 0; const positions = new Map();
  const selected = nodeById(S.selected);
  const autoScope = $('#graph').clientWidth < 650 && !S.scope && selected ? (children(selected.id).length ? selected.id : selected.parent_id) : null;
  const scopeId = S.scope && nodeById(S.scope) ? S.scope : autoScope;
  const roots = scopeId ? [nodeById(scopeId)] : children(null);
  function walk(n, depth) { const kids = children(n.id); const ys = kids.map(k => walk(k,depth+1)); const y = ys.length ? (ys[0]+ys.at(-1))/2 : leaf++ * 88 + 30; positions.set(n.id,{x:depth*280+30,y,node:n}); return y; }
  roots.forEach(n => { walk(n,0); leaf += .45; });
  if (S.page === 'workspace' && !scopeId) positions.forEach(p => { if (p.node.layout) { p.x = Number(p.node.layout.x); p.y = Number(p.node.layout.y); } });
  let minX = Math.min(0,...[...positions.values()].map(p=>p.x-20)), minY = Math.min(0,...[...positions.values()].map(p=>p.y-20));
  positions.forEach(p=>{p.x-=minX;p.y-=minY;});
  return {positions,scopeId,width:Math.max(300,...[...positions.values()].map(p=>p.x+254)),height:Math.max(180,...[...positions.values()].map(p=>p.y+96)),offset:{x:minX,y:minY}};
}
function renderGraph(fit = false) {
  const view = $('#graph'); if (!view || !S.tree || !view.clientWidth) return;
  if (!S.tree.nodes.length) { view.innerHTML = '<div class="empty-state"><h2>Your tree starts here</h2><p>Import a document or add your first collection.</p></div>'; return; }
  const g = layoutGraph(); S.graph = g;
  if (fit || S.zoom === null) { const fitted = Math.max(.2, Math.min(1, (view.clientWidth-12)/g.width, (view.clientHeight-12)/g.height)); S.zoom = fit ? fitted : Math.max(view.clientWidth < 650 ? .9 : .7, fitted); }
  const z = S.zoom; const canvasWidth = Math.max(view.clientWidth,g.width*z), canvasHeight = Math.max(view.clientHeight,g.height*z);
  let edges = '', nodes = '';
  g.positions.forEach(p => {
    const parent = g.positions.get(p.node.parent_id);
    if (parent) { const x = parent.x+224,y = parent.y+33,mid=(x+p.x)/2; edges += `<path class="graph-edge${S.visited.has(p.node.id)&&S.visited.has(parent.node.id)?' visited':''}" d="M${x} ${y}C${mid} ${y} ${mid} ${p.y+33} ${p.x} ${p.y+33}"/>`; }
    const count = children(p.node.id).length;
    nodes += `<button class="graph-node${S.selected===p.node.id&&S.page==='workspace'?' selected':''}${S.visited.has(p.node.id)?' visited':''}${S.current.has(p.node.id)?' current':''}" style="left:${p.x}px;top:${p.y}px" data-graph-node="${esc(p.node.id)}" data-kind="${esc(p.node.kind)}" aria-label="${esc(p.node.title)}, ${count} children"><span class="node-symbol">${icon(kindIcon(p.node))}</span><span class="node-text"><span class="node-title">${esc(p.node.title)}</span><span class="node-meta">${esc(p.node.kind)}${p.node.summary_origin==='llm'?' · AI abstract':''}</span></span>${count?`<span class="node-number">${count}</span>`:''}</button>`;
    if(p.node.summary)nodes += `<button class="node-summary-toggle" style="left:${p.x+196}px;top:${p.y+44}px" data-summary-node="${esc(p.node.id)}" aria-expanded="${S.summaryNode===p.node.id}" aria-label="${S.summaryNode===p.node.id?'Hide':'Show'} summary for ${esc(p.node.title)}" title="Show summary">${S.summaryNode===p.node.id?'−':'+'}</button>`;
    if(S.summaryNode===p.node.id)nodes += `<div class="node-summary-popover" role="region" aria-label="Node summary" style="left:${p.x}px;top:${p.y+70}px"><strong>Abstract · ${esc(p.node.summary_origin)}</strong><p>${esc(p.node.summary)}</p><button class="text-button" data-summary-node="${esc(p.node.id)}">Close summary</button></div>`;
  });
  view.innerHTML = `<div class="graph-spacer" style="width:${canvasWidth}px;height:${canvasHeight}px;position:relative"><div class="graph-world" style="width:${g.width}px;height:${g.height}px;transform:translate(${Math.max(0,(view.clientWidth-g.width*z)/2)}px,${Math.max(0,(view.clientHeight-g.height*z)/2)}px) scale(${z})"><svg width="${g.width}" height="${g.height}" aria-hidden="true">${edges}</svg>${nodes}</div></div>`;
  if ($('#canvas-count')) $('#canvas-count').textContent = `${g.positions.size} nodes · ${g.scopeId ? nodeById(g.scopeId)?.title : 'All collections'}`;
  if ($('#fit-graph')) $('#fit-graph').textContent = `Fit · ${Math.round(z*100)}%`;
  const focusId = S.current.size === 1 ? [...S.current][0] : S.selected;
  const anchor = g.positions.get(focusId) || g.positions.values().next().value;
  if (anchor && (g.width*z > view.clientWidth || g.height*z > view.clientHeight)) { view.scrollLeft = Math.max(0, (anchor.x+112)*z-view.clientWidth/2); view.scrollTop = Math.max(0, (anchor.y+33)*z-view.clientHeight/2); }
}
function referencesHTML(refs, mode='workspace') {
  if (!refs?.length) return '';
  return `<div class="reference-list"><h3>Explicit references <span class="muted">· ${refs.length}</span></h3>${refs.map(r=>{const target=mode==='lab'?r.target:(r.target || nodeById(r.target_id));const title=target?.title;return `<button class="reference-item${!target?' danger':''}" ${target?`data-${mode==='lab'?'ref-read':'select'}="${esc(target.id)}"`:'disabled'}>${icon(target?'link':'info')}<span class="reference-copy">${esc(r.label)} ${target?'→ '+esc(title):'· Unresolved'}<small>${target?esc(target.pathway || nodePath(target)): 'No exact target found'}</small></span>${target?icon('arrow'):''}</button>`;}).join('')}</div>`;
}
function modal(title, description, body, footer) {
  $('#modal-content').innerHTML=`<div class="modal-header"><div><h2>${esc(title)}</h2><p>${esc(description)}</p></div><button class="icon-button" data-close aria-label="Close dialog">${icon('close')}</button></div><div class="modal-body">${body}<div class="modal-error" role="alert"></div></div>${footer?`<div class="modal-footer">${footer}</div>`:''}`;
  $('#modal').showModal();
}
function modalError(e){const el=$('.modal-error');if(el)el.innerHTML=`<p class="error-message">${esc(e.message)}</p>`;else toast(e.message,true);}
function closeModal(){$('#modal').close();}

// The lab always calls the same HTTP tools used by external consumers.
const demoSteps=[['list_collections',{},'Discover the root collections.'],['browse',{id:'demo_troubleshooting'},'Open the Troubleshooting menu.'],['browse',{id:'demo_network'},'Inspect the Network menu.'],['browse',{id:'demo_dropout'},'List every step in the dropout runbook.'],['read',{id:'demo_step1'},'Read step 1 and its exact next-step references.'],['read',{id:'demo_step2'},'The caller chooses the “service down” branch and explicitly requests step 2.']];
function setCommand(tool,args){$('#tool-name').value=tool;$('#command-json').value=JSON.stringify({...args,version:args.version||S.tree.version},null,2);}
function defaultCommand(tool){const id=S.selected||S.tree.nodes[0]?.id;const args=tool==='list_collections'?{}:tool==='browse'?{id,depth:1,limit:30}:tool==='read'?{id}:tool==='navigate'?{query:'Customer 1234 is facing dropouts and slow speed',max_instructions:3}:{query:'What should I do when the connection drops?',mode:'lexical',limit:6};setCommand(tool,args);}
async function runCommand(){if(!S.tree){toast('The tree is still loading.');return false;}const button=$('#run-command');if(button.disabled)return false;let args;try{args=JSON.parse($('#command-json').value);if(!args||Array.isArray(args)||typeof args!=='object')throw new Error('Arguments must be a JSON object.');}catch(e){toast(e.message,true);return false;}args.version ||= S.tree.version;$('#command-json').value=JSON.stringify(args,null,2);const tool=$('#tool-name').value;button.disabled=true;$('#demo-next').disabled=true;button.innerHTML=icon('clock')+' Sending…';const entry={tool,args,time:new Date(),result:null,error:null};try{entry.result=await api('/api/tools/'+tool,args);if(entry.result.version!==S.tree.version)await loadTree(entry.result.version);S.history.push(entry);S.historyIndex=S.history.length-1;S.traceIndex=-1;S.responseTab='readable';renderHistory();showEntry();return true;}catch(e){entry.error=e.payload||{status:'error',error:{message:e.message}};S.history.push(entry);S.historyIndex=S.history.length-1;S.responseTab='readable';renderHistory();showEntry();return false;}finally{button.disabled=false;$('#demo-next').disabled=S.demo>=demoSteps.length;button.innerHTML=icon('play')+' Send command <kbd>⌘ ↵</kbd>';}}
function renderHistory(){$('#history-count').textContent=S.history.length+' calls';$('#history').innerHTML=S.history.map((e,i)=>`<li><button class="${i===S.historyIndex?'active':''}" data-history="${i}"><span class="history-number">${String(i+1).padStart(2,'0')}</span><span class="history-copy">${esc(e.tool)}<small>${esc(e.args.id||e.args.query||e.args.pathway||'Root menu')}</small></span><span class="${e.error?'danger':'muted'}">${e.error?'Error':e.result.meta.duration_ms+' ms'}</span></button></li>`).join('')||'<li class="history-empty">Your requests will appear here.<br>Every entry is a real API call.</li>';keepActiveHistoryVisible();}
function keepActiveHistoryVisible(){const pane=$('#history');if(!pane)return;const active=$('button.active',pane);if(!active)return;const row=active.getBoundingClientRect(),box=pane.getBoundingClientRect(),panel=pane.closest('.command-panel').getBoundingClientRect(),bottom=Math.min(box.bottom,panel.bottom-1);if(row.bottom>bottom-8)pane.scrollTop+=row.bottom-bottom+8;else if(row.top<box.top+4)pane.scrollTop-=box.top-row.top+4;}
if($('#history'))new ResizeObserver(()=>keepActiveHistoryVisible()).observe($('#history'));
function updateVisits(){S.visited=new Set();S.current=new Set();const entry=S.history[S.historyIndex];if(!entry)return;const version=entry.result?.version;S.history.slice(0,S.historyIndex).filter(e=>e.result&&e.result.version===version).forEach(e=>e.result.trace.forEach(t=>(t.node_ids||[]).forEach(id=>S.visited.add(id))));const traces=entry.result?.trace||[];const through=S.traceIndex<0?traces.length-1:S.traceIndex;traces.slice(0,through+1).forEach(t=>(t.node_ids||[]).forEach(id=>S.visited.add(id)));(traces[through]?.node_ids||[]).forEach(id=>S.current.add(id));if(S.current.size===1)S.selected=[...S.current][0];renderGraph();$('#trace-status').textContent=entry.error?'Request failed · no traversal recorded':S.traceIndex<0?`${traces.length} recorded operations · ${S.current.size} current nodes`:`Recorded trace ${S.traceIndex+1} / ${traces.length}: ${traces[S.traceIndex]?.action}`;}
function showEntry(){const entry=S.history[S.historyIndex];if(!entry)return;$$('[data-response-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.responseTab===S.responseTab)));$('#response-meta').textContent=entry.error?'Request failed':`${entry.result.status} · ${entry.result.meta.duration_ms} ms · ${entry.result.version}`;updateVisits();const target=$('#response');if(S.responseTab==='json'){target.innerHTML=`<button class="text-button" id="copy-response">${icon('copy')} Copy response</button><pre class="response-json">${esc(JSON.stringify(entry.result||entry.error,null,2))}</pre>`;return;}if(entry.error){target.innerHTML=`<div class="error-message">${esc(entry.error.error.message)}</div><p class="field-note">No successful traversal was recorded for this request.</p>`;return;}if(S.responseTab==='trace'){target.innerHTML=`<div class="trace-controls"><button class="button" id="trace-next">${icon('play')} ${S.traceIndex<0?'Replay first operation':'Next operation'}</button><p class="field-note">Replay of recorded API events · no extra model calls</p></div>${entry.result.trace.map((t,i)=>`<div class="trace-item${i===S.traceIndex?' selected':''}"><div><h3>${i+1}. ${esc(t.action)}</h3><p>${esc(t.message)}</p>${t.scores?`<details><summary class="small muted">Jev relevance scores</summary><pre>${esc(JSON.stringify(t.scores,null,2))}</pre></details>`:''}</div></div>`).join('')}`;return;}
  const d=entry.result.data;
  let html=d.engine?`<div class="response-status">${esc(d.engine==='lexical'?'Keyword search · no model called':d.engine==='llm'?'LLM tree traversal':d.engine==='navigate'?'Router navigation · instructions only':'Jev + LLM tree traversal')} · ${esc(d.coverage.replaceAll('_',' '))}${entry.result.meta.usage?` · ${entry.result.meta.usage.calls} calls · ${entry.result.meta.usage.input_tokens + entry.result.meta.usage.output_tokens} tokens · ${esc(entry.result.meta.usage.model)}`:''}</div>`:'';
  if(entry.tool==='navigate'){html+=d.instructions.length?d.instructions.map(n=>`<article class="read-result"><h3>${esc(n.title)}${n.sequence?` <span class="muted small">· step ${n.sequence.position} of ${n.sequence.total}</span>`:''}</h3><div class="node-path">${esc(n.pathway)}</div><div class="prose">${markdown(n.content)}</div>${n.next.length?`<p class="field-note">Next: ${n.next.map(r=>`<button class="text-button" data-lab-read="${esc(r.read_args.id)}">${esc(r.label)}</button>`).join(' · ')}</p>`:''}</article>`).join(''):`<div class="note">${icon('info')}<p>${d.status==='budget_exhausted'?'The navigation budget was reached before an instruction was found. Narrow the scope and try again.':'No branch in this scope matches the request. Nothing was returned.'}</p></div>`;target.innerHTML=html;return;}
  if(d.answer)html+=`<div class="read-result"><h3>Answer</h3><div class="prose">${markdown(d.answer)}</div>${d.citations.map(c=>`<button class="text-button" data-lab-read="${esc(c.id)}">${icon('document')} ${esc(c.title)}</button>`).join(' · ')}</div><div class="divider"></div>`;
  if(['no_answer','no_results','budget_exhausted'].includes(d.status))html+=`<div class="note">${icon('info')}<p>${d.status==='budget_exhausted'?'The search budget was reached without a supported answer. Narrow the scope and try again.':d.status==='no_answer'?'No supported answer was found in the explored branches. Try another scope or browse the source documents.':'No matching content was found. Try different words or browse a menu.'}</p></div>`;
  if(entry.tool==='read')html+=d.items.map(n=>`<article class="read-result"><h3>${esc(n.title)}</h3><div class="node-path">${esc(n.pathway)}</div><div class="prose">${markdown(n.content)}</div>${referencesHTML(n.references,'lab')}<p class="field-note">${esc(n.source?.name||'Manually authored')} · ${esc(n.representation)}</p></article>`).join('');
  else html+=`<div class="result-list">${(d.items||[]).map(n=>`<div class="result-row"><div><h3>${esc(n.title)} ${n.child_count?`<span class="muted small">· ${n.child_count} children</span>`:''}</h3><p>${esc(n.snippet||n.summary)}</p><div class="node-path">${esc(n.pathway)}</div></div><div class="result-actions">${n.child_count?`<button class="text-button" data-lab-browse="${esc(n.id)}">Browse ${icon('arrow')}</button>`:''}<button class="text-button" data-lab-read="${esc(n.id)}">Read ${icon('document')}</button></div></div>`).join('')}</div>`;
  if(d.next_cursor)html+=`<button class="button" id="next-page" style="margin-top:14px">Load next page ${icon('arrow')}</button>`;
  if(!html)html='<p class="field-note">This menu has no children.</p>';target.innerHTML=html;
}
async function labVersion(value){await loadTree(value);populateGuided();S.scope=null;S.zoom=null;S.visited.clear();S.current.clear();renderGraph(true);defaultCommand($('#tool-name').value);}

async function loadSettings(){await loadSummarySettings();S.settings=await api('/api/settings');const form=$('#settings-form');if(!form)return;Array.from(form.elements).forEach(input=>{input.disabled=false;input.removeAttribute('title');});Object.entries(S.settings).forEach(([key,value])=>{const input=form.elements.namedItem(key);if(input)input.value=value;});if(S.settings.router_model_default&&form.elements.router_model){form.elements.router_model.value='';form.elements.router_model.placeholder='Default: '+S.settings.router_model;}['api_key','access_key','secret_key','session_token','bedrock_token','jev_key'].forEach(key=>{if(form.elements[key]){form.elements[key].value='';form.elements[key].placeholder=S.settings[key+'_set']?'Saved · leave blank to keep':'Not configured';}});(S.settings.environment_fields||[]).forEach(key=>{if(form.elements[key]){form.elements[key].disabled=true;form.elements[key].title='Managed by environment; change .env and recreate Docker containers';}});$('#model-status').textContent=S.settings.configured?'LLM configured':'LLM not configured';$('#mcp-http').textContent=location.origin+'/mcp';providerFields();}
function providerFields(){const provider=$('#provider')?.value;if(!provider)return;$('.provider-api').hidden=provider==='bedrock';$('.provider-aws').hidden=provider!=='bedrock';}

document.addEventListener('click',async event=>{
  const b=event.target.closest('button,a');if(!b)return;
  try{
    if(b.hasAttribute('data-close')){closeModal();return;}
    if(b.dataset.select){await S.hooks.select(b.dataset.select);return;}
    if(b.dataset.summaryNode){S.summaryNode=S.summaryNode===b.dataset.summaryNode?null:b.dataset.summaryNode;renderGraph();return;}
    if(b.dataset.zoom){S.zoom=Math.min(2,Math.max(.2,S.zoom*(b.dataset.zoom==='in'?1.2:1/1.2)));renderGraph();return;}
    if(b.dataset.issueNode){closeModal();await S.hooks.select(b.dataset.issueNode);return;}
    if(b.dataset.source){const source=await api('/api/sources/'+b.dataset.source);modal(source.name,'Preserved source · SHA-256 '+source.hash.slice(0,12),`<pre class="api-code">${esc(source.original)}</pre>`,`<button class="button" data-close>Close</button>`);return;}
    if(b.dataset.history!==undefined){S.historyIndex=Number(b.dataset.history);S.traceIndex=-1;const entry=S.history[S.historyIndex];if(entry.result&&entry.result.version!==S.tree.version)await loadTree(entry.result.version);renderHistory();showEntry();return;}
    if(b.dataset.responseTab){S.responseTab=b.dataset.responseTab;showEntry();return;}
    if(b.dataset.path&&S.page==='lab'){setCommand('read',{pathway:b.dataset.path,version:S.history[S.historyIndex]?.result?.version||S.tree.version});await runCommand();return;}
    if(b.dataset.labRead||b.dataset.labBrowse||b.dataset.refRead){const tool=b.dataset.labBrowse?'browse':'read',id=b.dataset.labRead||b.dataset.labBrowse||b.dataset.refRead;setCommand(tool,{id,version:S.history[S.historyIndex]?.result?.version||S.tree.version});await runCommand();return;}
    switch(b.id){
      case 'fit-graph':renderGraph(true);break;
      case 'graph-root':S.scope='all';S.zoom=null;renderGraph(true);break;
      case 'graph-focus':S.scope=children(S.selected).length?S.selected:(nodeById(S.selected)?.parent_id||S.selected);S.zoom=null;renderGraph(true);break;
      case 'guided-collections':setCommand('list_collections',{});await runCommand();break;
      case 'guided-browse':setCommand('browse',$('#guided-node').value?{id:$('#guided-node').value}:{});await runCommand();break;
      case 'guided-read':if(!$('#guided-node').value){toast('Select a node to read.',true);break;}setCommand('read',{id:$('#guided-node').value});await runCommand();break;
      case 'guided-search':{if(!$('#guided-query').value.trim()){toast('Enter a question or keywords.',true);break;}const mode=$('#guided-mode').value,scope=$('#guided-node').value?{id:$('#guided-node').value}:{};if(mode==='navigate')setCommand('navigate',{query:$('#guided-query').value,...scope});else setCommand('search',{query:$('#guided-query').value,mode,...(mode!=='lexical'&&$('#guided-answer').checked?{answer:true}:{}),...scope});await runCommand();break;}
      case 'run-command':await runCommand();break;
      case 'demo-next':{if(S.demo>=demoSteps.length)break;const [tool,args]=demoSteps[S.demo];setCommand(tool,args);if(await runCommand()){S.demo++;$('#demo-progress').textContent=S.demo+' / '+demoSteps.length;$('#demo-description').textContent=S.demo<demoSteps.length?demoSteps[S.demo][2]:'Traversal complete. The API returned content; the example caller chose each next step.';b.disabled=S.demo>=demoSteps.length;if(b.disabled)b.innerHTML=icon('check')+' Example complete';}break;}
      case 'trace-next':{const trace=S.history[S.historyIndex]?.result?.trace||[];S.traceIndex=S.traceIndex<0?0:S.traceIndex+1;if(S.traceIndex>=trace.length)S.traceIndex=-1;showEntry();break;}
      case 'next-page':{const e=S.history[S.historyIndex];setCommand(e.tool,{...e.args,cursor:e.result.data.next_cursor,version:e.result.version});await runCommand();break;}
      case 'reset-lab':S.history=[];S.historyIndex=-1;S.demo=0;S.traceIndex=-1;S.visited.clear();S.current.clear();renderHistory();renderGraph();$('#response').innerHTML='<p class="field-note">Session cleared. Send a command to start a new trail.</p>';$('#response-meta').textContent='Waiting for your first command';$('#trace-status').textContent='Select a node to prepare a read command.';$('#demo-progress').textContent='0 / 6';$('#demo-description').textContent=demoSteps[0][2];$('#demo-next').disabled=false;$('#demo-next').innerHTML='Run next example '+icon('arrow');setCommand('list_collections',{});break;
      case 'copy-response':await navigator.clipboard.writeText(JSON.stringify(S.history[S.historyIndex].result||S.history[S.historyIndex].error,null,2));toast('Response copied.');break;
      case 'test-model':b.disabled=true;b.textContent='Testing…';try{const r=await api('/api/model-test',{});if(!r.ok)throw new Error('The provider responded but did not return the expected test object.');toast('LLM connection verified.');}finally{b.disabled=false;b.textContent='Test LLM connection';}break;
      case 'clear-secrets':modal('Remove model credentials?','This removes the API keys stored in this workspace.','<p>Provider names and model settings will remain. AWS environment credentials, if set, are managed outside the application.</p>','<button class="button" data-close>Cancel</button><button class="button danger" id="confirm-clear-secrets">Remove credentials</button>');break;
      case 'confirm-clear-secrets':await api('/api/settings',{clear_secrets:true});closeModal();await loadSettings();toast('Stored credentials removed.');break;
    }
  }catch(e){if($('#modal').open)modalError(e);else toast(e.message,true);}
});
document.addEventListener('change',async event=>{try{if(event.target.id==='lab-version')await labVersion(event.target.value);if(event.target.id==='tool-name')defaultCommand(event.target.value);if(event.target.id==='guided-mode')$('#guided-answer-wrap').hidden=['navigate','lexical'].includes(event.target.value);if(event.target.id==='provider'){const endpoint=$('[name=endpoint]');if(event.target.value==='anthropic'&&endpoint.value==='https://api.openai.com/v1')endpoint.value='https://api.anthropic.com/v1';if(event.target.value==='openai'&&endpoint.value==='https://api.anthropic.com/v1')endpoint.value='https://api.openai.com/v1';providerFields();}}catch(e){toast(e.message,true);}});
document.addEventListener('submit',async event=>{event.preventDefault();try{if(event.target.id==='summary-form'){const f=event.target;await api('/api/summary-settings',{enabled:f.elements.enabled.checked,debounce:Number(f.elements.debounce.value),tokens:[1,2,3,4].map(i=>Number(f.elements['layer'+i].value))});$('#summary-setting-status').textContent='Saved';}if(event.target.id==='settings-form'){const b=$('button[type=submit]',event.target);b.disabled=true;try{const data=Object.fromEntries(new FormData(event.target));data.jev_threshold=Number(data.jev_threshold);await api('/api/settings',data);await loadSettings();toast('Model configuration saved.');}finally{b.disabled=false;}}if(event.target.id==='login-form'){await api('/api/login',Object.fromEntries(new FormData(event.target)));location.reload();}}catch(e){toast(e.message,true);}});
document.addEventListener('keydown',event=>{if((event.metaKey||event.ctrlKey)&&event.key==='Enter'&&S.page==='lab'){event.preventDefault();runCommand();}});
$('#graph')?.addEventListener('pointerdown',event=>{
  const element=event.target.closest('[data-graph-node]');if(!element||event.button!==0)return;
  const id=element.dataset.graphNode,start={x:event.clientX,y:event.clientY},position=S.graph.positions.get(id),canDrag=S.page==='workspace'&&isDraft()&&!S.graph.scopeId;let moved=false,dx=0,dy=0;
  element.setPointerCapture(event.pointerId);
  function move(e){dx=(e.clientX-start.x)/S.zoom;dy=(e.clientY-start.y)/S.zoom;if(Math.hypot(dx,dy)>6&&canDrag){moved=true;element.style.left=position.x+dx+'px';element.style.top=position.y+dy+'px';element.style.cursor='grabbing';}}
  async function up(){element.removeEventListener('pointermove',move);element.removeEventListener('pointerup',up);element.removeEventListener('pointercancel',cancel);try{if(moved){await S.hooks.flush();await mutate('/api/nodes',{id,layout:{x:Math.round(position.x+dx+S.graph.offset.x),y:Math.round(position.y+dy+S.graph.offset.y)}});}else if(S.page==='lab'){S.selected=id;setCommand('read',{id});toast('Read command prepared. Send it to inspect this node.');}else await S.hooks.select(id);}catch(e){renderGraph();toast(e.message,true);}}
  function cancel(){element.removeEventListener('pointermove',move);element.removeEventListener('pointerup',up);element.removeEventListener('pointercancel',cancel);renderGraph();}
  element.addEventListener('pointermove',move);element.addEventListener('pointerup',up);element.addEventListener('pointercancel',cancel);
});
// Native button keyboard activation does not produce pointer events.
$('#graph')?.addEventListener('click',event=>{const el=event.target.closest('[data-graph-node]');if(!el||event.detail!==0)return;if(S.page==='lab'){S.selected=el.dataset.graphNode;setCommand('read',{id:S.selected});}else S.hooks.select(el.dataset.graphNode).catch(e=>toast(e.message,true));});
let resizeTimer;window.addEventListener('resize',()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{if(S.tree)renderGraph(true);},150);});
async function init(){if(document.body.dataset.locked==='true')return;try{if(S.page==='settings'){await loadSettings();return;}S.settings=await api('/api/settings');const params=new URLSearchParams(location.search);await loadTree(S.page==='lab'?(params.get('version')||'latest'):'draft');if(S.page==='lab'){if(params.get('node')&&nodeById(params.get('node'))){S.selected=params.get('node');setCommand('read',{id:S.selected});}else setCommand('list_collections',{});}populateGuided();await S.hooks.init();renderGraph();}catch(e){toast(e.message,true);}}
async function loadSummarySettings(){const r=await api('/api/summary-settings'),f=$('#summary-form');if(f){f.elements.enabled.checked=r.settings.enabled;f.elements.debounce.value=r.settings.debounce;r.settings.tokens.forEach((t,i)=>f.elements['layer'+(i+1)].value=t);}}
function populateGuided(){const el=$('#guided-node');if(el)el.innerHTML='<option value="">All collections</option>'+S.tree.nodes.map(n=>`<option value="${esc(n.id)}">${esc(nodePath(n))}</option>`).join('');}
// Runs after every deferred script, so page scripts such as editor.js can install their hooks first.
document.addEventListener('DOMContentLoaded', init);
