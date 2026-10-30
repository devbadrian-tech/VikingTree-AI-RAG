'use strict';
// Tree editor for the workspace page. Loaded after app.js, which provides $, $$, esc, icon, api,
// mutate, modal, toast, loadTree, renderGraph and the shared state S.

const KINDS = {
  collection: {label: 'Collection', hint: 'A top-level area, e.g. Troubleshooting'},
  folder: {label: 'Folder', hint: 'Groups related documents, e.g. Network'},
  document: {label: 'Document', hint: 'A runbook, guide or article'},
  section: {label: 'Section', hint: 'A part of a document'},
  step: {label: 'Step', hint: 'One step of a runbook; the API starts at the first step'},
};
const E = {dirty: false, view: 'page', previewTimer: null, previewSeq: 0, cursor: null, unsaved: null};
S.collapsed = S.collapsed || new Set();

// ---------- helpers ----------
const kindLabel = kind => KINDS[kind]?.label || kind;
function slugify(text) {
  const s = String(text).replace(/\bstep\s+(\d+)\b/gi, 'step$1').replace(/[^a-zA-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
  return s ? s.slice(0, 80) : 'untitled';
}
function stepNumber(node) {
  const m = node.slug.match(/^step(\d+)$/) || node.title.match(/^step\s*(\d+)\b/i);
  return m ? Number(m[1]) : null;
}
function nextStepNumber(parentId) {
  const numbers = children(parentId).map(stepNumber).filter(n => n !== null);
  return numbers.length ? Math.max(...numbers) + 1 : 1;
}
function ancestors(node) {
  const list = []; let current = node, guard = 0;
  while (current?.parent_id && guard++ < 25) { current = nodeById(current.parent_id); if (current) list.unshift(current); }
  return list;
}
function nodeAtPath(path) {
  const parts = String(path).replace(/^viking:\/\//, '').split('/').filter(Boolean);
  let parent = null, found = null;
  for (const part of parts) { found = children(parent).find(c => c.slug === part); if (!found) return null; parent = found.id; }
  return found;
}
function backlinks(id) { return S.tree.nodes.filter(n => n.id !== id && n.references.some(r => r.target_id === id)); }
function defaultChildKind(parent) {
  if (!parent) return 'collection';
  return {collection: 'folder', folder: 'document', document: 'step', section: 'step', step: 'section'}[parent.kind] || 'document';
}
function stepSiblings(node) { return node?.parent_id ? children(node.parent_id).filter(c => c.kind === 'step') : []; }
function parentOptions(selected, exclude) {
  const blocked = exclude ? descendants(exclude) : new Set();
  return `<option value="">Top level (no parent)</option>` + S.tree.nodes.filter(n => !blocked.has(n.id))
    .map(n => ({n, p: nodePath(n)})).sort((a, b) => a.p.localeCompare(b.p))
    .map(({n, p}) => `<option value="${esc(n.id)}"${n.id === selected ? ' selected' : ''}>${esc(p.replace('viking://', ''))} · ${esc(n.title)}</option>`).join('');
}
function crumbs(node) {
  return ancestors(node).map(a => `<button class="crumb" data-select="${esc(a.id)}">${esc(a.title)}</button>`).join('<span class="crumb-sep">›</span>');
}

// Renders content with clickable links: [label](viking://…) and resolved "step N" mentions.
function richText(node) {
  const mentions = new Map(), broken = new Set();
  (node.references || []).forEach(r => {
    if (r.origin !== 'explicit_text') return;
    const key = r.mention.toLowerCase().replace(/\s+/g, '');
    if (r.target_id && nodeById(r.target_id)) mentions.set(key, r.target_id); else broken.add(key);
  });
  return String(node.content).split(/\n\s*\n/).map(block => {
    const heading = block.match(/^(#{1,6}) (.+)$/);
    if (heading) return `<h3>${esc(heading[2])}</h3>`;
    const html = esc(block).replace(/\[([^\]]+)\]\((viking:\/\/[^\s)]+)\)|\bstep\s*(\d+)\b/gi, (match, label, path) => {
      if (label) {
        const target = nodeAtPath(path);
        return target ? `<button type="button" class="inline-link" data-select="${esc(target.id)}" title="${path}">${icon('link')}${label}</button>`
          : `<span class="inline-link broken" title="No node at ${path}">${icon('info')}${label}</span>`;
      }
      const key = match.toLowerCase().replace(/\s+/g, '');
      if (mentions.has(key)) return `<button type="button" class="inline-link" data-select="${esc(mentions.get(key))}" title="${esc(nodePath(nodeById(mentions.get(key))))}">${match}</button>`;
      if (broken.has(key)) return `<span class="inline-link broken" title="Not linked: no matching step">${match}</span>`;
      return match;
    }).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>');
    // Lines such as "If X, go to step2" become decision rows; other lines stay as plain paragraphs.
    let out = '', plain = [];
    const flush = () => { if (plain.length) out += `<p>${plain.join('\n')}</p>`; plain = []; };
    for (const line of html.split('\n')) {
      if (/^\s*(if|when|otherwise|else|unless)\b/i.test(line) && line.includes('inline-link')) { flush(); out += `<div class="decision">${icon('route')}<span>${line.trim()}</span></div>`; }
      else plain.push(line);
    }
    flush();
    return out;
  }).join('');
}

function linkRow(ref, target) {
  if (!target) return `<div class="link-row broken">${icon('info')}<div><strong>${esc(ref.label)}</strong> is not linked<small>${ref.origin === 'explicit_link' ? 'No node exists at ' + esc(ref.mention) : 'No step with this number under the same document. Rename the step to “Step N …” or use Insert link.'}</small></div></div>`;
  return `<button class="link-row" data-select="${esc(target.id)}">${icon('link')}<div><strong>${esc(ref.label)}</strong> → ${esc(target.title)}<small>${esc(target.pathway || nodePath(target))}</small></div>${icon('arrow')}</button>`;
}

// ---------- toolbar ----------
function renderToolbar() {
  const draft = isDraft();
  $('#save-state').textContent = draft ? (E.dirty ? 'Unsaved changes' : 'All changes saved') : 'Published version · read only';
  $('#save-state').title = 'Draft revision ' + S.tree.revision;
  $('#publish-state').hidden = !draft || !S.tree.unpublished_changes;
  $('#version-dot').className = draft ? 'draft-dot' : 'status-dot';
  $('#export-link').href = '/api/export?version=' + encodeURIComponent(S.tree.version);
  ['add-root', 'import-button', 'publish-button'].forEach(id => { $('#' + id).disabled = !draft; });
  const issues = S.tree.issues.length, review = S.tree.navigation_review?.issues?.length || 0;
  const chip = $('#issues-button');
  chip.hidden = !draft || (!issues && !review);
  chip.className = 'status-chip' + (issues ? ' danger' : '');
  chip.innerHTML = issues ? `${icon('info')} ${issues} broken link${issues === 1 ? '' : 's'}` : `${icon('spark')} ${review} AI suggestion${review === 1 ? '' : 's'}`;
}

// ---------- outline ----------
function renderOutline() {
  const filter = ($('#tree-filter').value || '').trim().toLowerCase(), visible = new Set();
  const hits = filter ? S.tree.nodes.filter(n => (n.title + ' ' + nodePath(n) + ' ' + n.content).toLowerCase().includes(filter)) : [];
  hits.forEach(n => { visible.add(n.id); ancestors(n).forEach(a => visible.add(a.id)); });
  const hitIds = new Set(hits.map(n => n.id));
  $('#filter-count').textContent = filter ? `${hits.length} match${hits.length === 1 ? '' : 'es'} · ` : '';
  const draft = isDraft();
  const rows = (parent, level) => children(parent).filter(n => !filter || visible.has(n.id)).map(n => {
    const kids = children(n.id), open = filter || !S.collapsed.has(n.id), selected = S.selected === n.id;
    return `<div role="treeitem" aria-level="${level}" aria-selected="${selected}"${kids.length ? ` aria-expanded="${!!open}"` : ''}>
      <div class="tree-row${selected ? ' selected' : ''}${hitIds.has(n.id) ? ' hit' : ''}" data-node-id="${esc(n.id)}" data-kind="${esc(n.kind)}" draggable="${draft}">
        <button class="tree-toggle" data-toggle="${esc(n.id)}" aria-label="${open ? 'Collapse' : 'Expand'} ${esc(n.title)}"${kids.length ? '' : ' tabindex="-1" disabled'}>${kids.length ? (open ? '▾' : '▸') : ''}</button>
        <button class="tree-label" data-select="${esc(n.id)}" title="${esc(n.title)}&#10;${esc(nodePath(n))}">${icon(kindIcon(n))}<span class="tree-title">${esc(n.title)}</span></button>
        ${n.references.some(r => !r.target_id || !nodeById(r.target_id)) ? `<span class="tree-flag" title="Has a broken link">!</span>` : ''}
        ${draft ? `<button class="tree-add" data-add-child="${esc(n.id)}" aria-label="Add inside ${esc(n.title)}" title="Add inside">${icon('plus')}</button>` : ''}
      </div>${kids.length && open ? `<div class="tree-children" role="group">${rows(n.id, level + 1)}</div>` : ''}</div>`;
  }).join('');
  $('#outline').innerHTML = rows(null, 1) || `<p class="field-note">${filter ? 'Nothing matches.' : 'The tree is empty. Use New to add a collection.'}</p>`;
}

// ---------- node page (view mode) ----------
function renderPage() {
  const panel = $('#node-page'), n = nodeById(S.selected);
  if (!n) { panel.innerHTML = `<div class="empty-state"><span>${icon('document')}</span><h2>Pick something from the tree</h2><p>Select a node on the left, or use New to add one.</p></div>`; return; }
  // Never re-render an open form: that would throw away what the user typed.
  if (S.edit && isDraft()) return $('#node-form')?.dataset.nodeId === n.id ? undefined : renderEditor(n);
  const draft = isDraft(), kids = children(n.id), steps = stepSiblings(n), position = steps.findIndex(s => s.id === n.id);
  const prev = position > 0 ? steps[position - 1] : null, next = position >= 0 && position < steps.length - 1 ? steps[position + 1] : null;
  const links = backlinks(n.id), addLabel = n.kind === 'document' ? 'Add step' : 'Add inside';
  panel.innerHTML = `
    ${!draft ? `<div class="readonly-banner">${icon('info')}<span>You are viewing a published version. It cannot be changed.</span><button class="text-button" id="go-draft">Open the working draft ${icon('arrow')}</button></div>` : ''}
    <header class="page-head">
      <nav class="crumbs" aria-label="Location">${crumbs(n) || '<span class="muted">Top level</span>'}</nav>
      <div class="page-title-row">
        <h1>${esc(n.title)}</h1>
        ${draft ? `<div class="page-actions">
          <button class="button primary" id="edit-node">${icon('edit')} Edit</button>
          <button class="button" data-add-child="${esc(n.id)}">${icon('plus')} ${addLabel}</button>
          <button class="button" id="move-node">${icon('arrow')} Move</button>
          <button class="button danger-outline" id="delete-node">${icon('trash')} Delete</button>
        </div>` : ''}
      </div>
      <div class="page-meta">
        <span class="kind-badge" data-kind="${esc(n.kind)}">${icon(kindIcon(n))} ${esc(kindLabel(n.kind))}</span>
        ${position >= 0 ? `<span class="muted">Step ${position + 1} of ${steps.length} in ${esc(nodeById(n.parent_id)?.title || '')}</span>` : ''}
        <button class="path-chip" id="copy-path" title="Copy API arguments">${esc(nodePath(n))} ${icon('copy')}</button>
        <a class="text-button" href="/lab?node=${encodeURIComponent(n.id)}&version=${encodeURIComponent(S.tree.version)}">Test in Lab ${icon('arrow')}</a>
      </div>
      ${prev || next ? `<div class="step-nav">${prev ? `<button class="button" data-select="${esc(prev.id)}">← ${esc(prev.title)}</button>` : '<span></span>'}${next ? `<button class="button" data-select="${esc(next.id)}">${esc(next.title)} →</button>` : ''}</div>` : ''}
    </header>
    <section class="page-section">
      <h2>Instructions</h2>
      <div class="prose">${n.content ? richText(n) : `<p class="muted">No content yet.${draft ? ' Click Edit to write it.' : ''}</p>`}</div>
    </section>
    <section class="page-section">
      <h2>Goes to <span class="muted">· links in this content</span></h2>
      ${n.references.length ? `<div class="link-list">${n.references.map(r => linkRow(r, r.target_id ? nodeById(r.target_id) : null)).join('')}</div>` : `<p class="field-note">This content does not link anywhere.${draft ? ' In Edit, use <b>Insert link</b> or write “go to step2” to link to another step.' : ''}</p>`}
    </section>
    <section class="page-section">
      <h2>Linked from <span class="muted">· ${links.length}</span></h2>
      ${links.length ? `<div class="link-list">${links.map(l => `<button class="link-row" data-select="${esc(l.id)}">${icon('link')}<div><strong>${esc(l.title)}</strong><small>${esc(nodePath(l))}</small></div>${icon('arrow')}</button>`).join('')}</div>` : '<p class="field-note">Nothing links here yet.</p>'}
    </section>
    ${kids.length || (draft && ['collection', 'folder', 'document', 'section'].includes(n.kind)) ? `<section class="page-section">
      <h2>${n.kind === 'document' ? 'Steps and sections' : 'Inside this ' + esc(kindLabel(n.kind).toLowerCase())} <span class="muted">· ${kids.length}</span></h2>
      ${kids.length ? `<ol class="child-list">${kids.map((c, i) => `<li><button class="child-open" data-select="${esc(c.id)}">${icon(kindIcon(c))}<span>${esc(c.title)}</span><small>${esc(kindLabel(c.kind))}</small></button>${draft ? `<span class="child-order"><button class="icon-button" data-reorder="${esc(c.id)}" data-dir="-1" aria-label="Move ${esc(c.title)} up"${i === 0 ? ' disabled' : ''}>↑</button><button class="icon-button" data-reorder="${esc(c.id)}" data-dir="1" aria-label="Move ${esc(c.title)} down"${i === kids.length - 1 ? ' disabled' : ''}>↓</button></span>` : ''}</li>`).join('')}</ol>` : '<p class="field-note">Empty.</p>'}
      ${kids.some(c => c.kind === 'step') ? '<p class="field-note">The API starts at the first step in this list. Reordering does not rename steps.</p>' : ''}
      ${draft ? `<button class="button" data-add-child="${esc(n.id)}">${icon('plus')} ${addLabel}</button>` : ''}
    </section>` : ''}
    <section class="page-section">
      <h2>Navigation summary <span class="muted">· what the AI router reads</span></h2>
      ${n.summary ? `<div class="abstract-block"><div class="abstract-label"><span>Abstract</span><span>${esc(n.summary_origin === 'llm' ? 'AI generated' : n.summary_origin)}</span></div><p>${esc(n.summary)}</p></div>` : '<p class="field-note">No summary yet. AI summaries fill this in, or write one in Edit.</p>'}
      ${n.overview ? `<details class="overview-detail"><summary>Overview</summary><div class="prose">${markdown(n.overview)}</div></details>` : ''}
    </section>
    ${devPanel(n)}
    <div class="source-line">${icon('document')}<div>${n.source ? `${esc(n.source.name)}${n.source.line_start ? ` · lines ${n.source.line_start}–${n.source.line_end}` : ''}${n.source.id ? ` · <button class="text-button" data-source="${esc(n.source.id)}">View original file</button>` : ''}` : 'Written in this workspace.'}</div></div>`;
}

// What a calling application sends and gets for this node.
function devPanel(n) {
  const version = isDraft() ? (S.tree.published_version || 'latest') : S.tree.version;
  const args = JSON.stringify({pathway: nodePath(n), version}, null, 2);
  const curl = `curl ${location.origin}/api/tools/read \\\n  -H 'Authorization: Bearer $ARBOR_API_TOKEN' \\\n  -H 'Content-Type: application/json' \\\n  -d '${JSON.stringify({id: n.id, version})}'`;
  const block = (label, text) => `<div class="dev-block"><div class="dev-label"><span>${label}</span><button class="text-button" data-copy="${esc(text)}">${icon('copy')} Copy</button></div><pre>${esc(text)}</pre></div>`;
  return `<details class="dev-panel"><summary>${icon('plug')} For developers <span class="muted">· node ID ${esc(n.id)}</span></summary>
    ${isDraft() ? `<p class="field-note">Callers read published versions. Draft edits reach the API after you publish.</p>` : ''}
    ${block('read arguments', args)}${block('curl', curl)}</details>`;
}

// ---------- node page (edit mode) ----------
function renderEditor(n) {
  const kids = children(n.id).length;
  $('#node-page').innerHTML = `
    <form id="node-form" class="node-form" data-node-id="${esc(n.id)}" novalidate>
      <header class="page-head edit-head">
        <nav class="crumbs" aria-label="Location">${crumbs(n) || '<span class="muted">Top level</span>'}</nav>
        <div class="page-title-row"><h1>Editing: ${esc(n.title)}</h1>
          <div class="page-actions"><button type="submit" class="button primary">${icon('check')} Save <kbd>Ctrl S</kbd></button><button type="button" class="button" id="cancel-edit">Cancel</button></div></div>
      </header>
      <div class="form-error" role="alert"></div>
      <div class="field-grid">
        <label>Title<input name="title" value="${esc(n.title)}" maxlength="200" required></label>
        <label>Type<select name="kind">${Object.entries(KINDS).map(([k, v]) => `<option value="${k}"${k === n.kind ? ' selected' : ''}>${v.label} — ${v.hint}</option>`).join('')}</select></label>
      </div>
      <div class="field-heading"><label for="node-content">Instructions</label>
        <div class="editor-tools"><button type="button" class="button small-button" id="insert-decision">${icon('route')} Add decision</button><button type="button" class="button small-button" id="insert-link">${icon('link')} Insert link</button></div></div>
      <textarea id="node-content" name="content" class="content-input" spellcheck="true">${esc(n.content)}</textarea>
      <p class="field-note"><b>Add decision</b> writes a line like “If the light is off, go to Step 4”. <b>Insert link</b> points to any node. Inside a runbook you can also type “go to step2”; it links to the step named “Step 2 …” in the same document.</p>
      <div class="link-preview" id="link-preview" aria-live="polite"></div>
      <label for="node-summary">Navigation summary <span class="muted">· what the AI router reads to decide whether to open this branch</span></label>
      <textarea id="node-summary" name="summary" rows="3" maxlength="1200">${esc(n.summary)}</textarea>
      <div class="char-count" id="summary-count"></div>
      <details class="advanced"><summary>Advanced</summary>
        <label>URL name<input name="slug" value="${esc(n.slug)}" pattern="[a-z0-9][a-z0-9_-]*" maxlength="100"></label>
        <p class="field-note" id="slug-preview"></p>
        <label>Overview<textarea name="overview" rows="4">${esc(n.overview)}</textarea></label>
        <p class="field-note">${kids ? `${kids} child node${kids === 1 ? '' : 's'} move with this node.` : ''} To change where this node sits, use Move on the page.</p>
      </details>
    </form>`;
  updateEditorHints(); previewLinks(true); growTextarea();
  $('#node-form [name=title]').focus();
}
function growTextarea() {
  const t = $('#node-content'); if (!t) return;
  t.style.height = 'auto'; t.style.height = Math.max(280, t.scrollHeight + 4) + 'px';
}
function updateEditorHints() {
  const f = $('#node-form'); if (!f) return;
  const n = nodeById(S.selected), parent = n.parent_id ? nodePath(nodeById(n.parent_id)) : 'viking:/';
  $('#slug-preview').textContent = 'Path: ' + parent + '/' + (f.elements.slug.value || '…') + (f.elements.slug.value !== n.slug ? ' · links to this node are updated automatically' : '');
  $('#summary-count').textContent = `${f.elements.summary.value.length} / 1200 characters`;
}
function previewLinks(now = false) {
  clearTimeout(E.previewTimer);
  E.previewTimer = setTimeout(async () => {
    const f = $('#node-form'); if (!f) return;
    const seq = ++E.previewSeq, n = nodeById(S.selected);
    try {
      const r = await api('/api/references/preview', {id: n.id, kind: f.elements.kind.value, content: f.elements.content.value});
      if (seq !== E.previewSeq || !$('#link-preview')) return;
      $('#link-preview').innerHTML = r.references.length
        ? `<h3>Links in this text</h3>${r.references.map(ref => linkRow(ref, ref.target)).join('')}`
        : '<p class="field-note">No links in this text yet.</p>';
    } catch (e) { if ($('#link-preview')) $('#link-preview').innerHTML = `<p class="error-message">${esc(e.message)}</p>`; }
  }, now ? 0 : 400);
}
async function saveEdit() {
  const f = $('#node-form'), n = nodeById(S.selected); if (!f || !n) return;
  const data = {id: n.id};
  for (const key of ['title', 'kind', 'slug', 'content', 'summary', 'overview']) {
    const value = key === 'title' ? f.elements[key].value.trim() : f.elements[key].value;
    if (value !== n[key]) data[key] = value;
  }
  if (!data.title && data.title !== undefined) { showFormError('Enter a title.'); return; }
  if (data.slug !== undefined && !/^[a-z0-9][a-z0-9_-]{0,99}$/.test(data.slug)) { showFormError('URL name uses lowercase letters, numbers, hyphens and underscores, and starts with a letter or number.'); return; }
  const button = $('button[type=submit]', f); button.disabled = true;
  try {
    if (Object.keys(data).length > 1) await mutate('/api/nodes', data);
    E.dirty = false; S.edit = false; renderAll(); toast(Object.keys(data).length > 1 ? 'Saved to the draft.' : 'No changes to save.');
  } catch (e) { showFormError(e.message); $('#save-state').textContent = 'Not saved'; } finally { if (button.isConnected) button.disabled = false; }
}
function showFormError(message) { const el = $('.form-error'); if (el) { el.innerHTML = `<p class="error-message">${esc(message)}</p>`; el.scrollIntoView({block: 'nearest'}); } else toast(message, true); }

// Asks before leaving unsaved edits. Resolves true when it is safe to continue.
async function ensureSaved() {
  if (!S.edit || !E.dirty) { S.edit = false; return true; }
  modal('Save your changes?', 'You have unsaved edits on this node.', '<p>Save them to the draft, or discard them.</p>',
    '<button class="button" id="unsaved-cancel">Keep editing</button><button class="button danger" id="unsaved-discard">Discard</button><button class="button primary" id="unsaved-save">Save</button>');
  const choice = await new Promise(resolve => { E.unsaved = resolve; $('#modal').addEventListener('close', () => resolve('cancel'), {once: true}); });
  E.unsaved = null; if ($('#modal').open) closeModal();
  if (choice === 'cancel') return false;
  if (choice === 'save') { await saveEdit(); return !E.dirty; }
  E.dirty = false; S.edit = false; return true;
}

// ---------- dialogs ----------
function addDialog(parentId, kind) {
  const parent = parentId ? nodeById(parentId) : null;
  kind ||= defaultChildKind(parent);
  modal('Add to the tree', 'Give it a title and choose what it is.', `
    <label for="add-title">Title</label><input id="add-title" maxlength="200" placeholder="e.g. Check the modem lights" autocomplete="off">
    <fieldset class="kind-choice"><legend>What are you adding?</legend>${Object.entries(KINDS).map(([k, v]) => `<label class="kind-option"><input type="radio" name="add-kind" value="${k}"${k === kind ? ' checked' : ''}><span><strong>${v.label}</strong><small>${v.hint}</small></span></label>`).join('')}</fieldset>
    <label for="add-parent">Inside</label><select id="add-parent">${parentOptions(parentId)}</select>
    <label class="check-label" id="add-number-wrap"><input type="checkbox" id="add-number" checked><span id="add-number-text"></span></label>
    <p class="field-note" id="add-preview"></p>`,
    `<button class="button" data-close>Cancel</button><button class="button primary" id="submit-add">${icon('plus')} Add</button>`);
  updateAddPreview(); $('#add-title').focus();
}
function addDraft() {
  const title = $('#add-title').value.trim(), kind = $('input[name=add-kind]:checked')?.value, parent = $('#add-parent').value || null;
  const number = kind === 'step' && parent && $('#add-number').checked ? nextStepNumber(parent) : null;
  const finalTitle = number && !/^step\s*\d+/i.test(title) ? `Step ${number} · ${title}` : title;
  return {title: finalTitle, kind, parent_id: parent, ...(number && !/^step\s*\d+/i.test(title) ? {slug: 'step' + number} : {})};
}
function updateAddPreview() {
  if (!$('#add-title')) return;
  const kind = $('input[name=add-kind]:checked')?.value, parent = $('#add-parent').value || null;
  $('#add-number-wrap').hidden = !(kind === 'step' && parent);
  if (kind === 'step' && parent) $('#add-number-text').textContent = `Number it as Step ${nextStepNumber(parent)} (adds it to the end of ${nodeById(parent).title} and lets other steps say “go to step${nextStepNumber(parent)}”)`;
  const d = addDraft();
  $('#add-preview').textContent = d.title ? 'Will be created at ' + (parent ? nodePath(nodeById(parent)) : 'viking:/') + '/' + (d.slug || slugify(d.title)) : '';
}
async function submitAdd() {
  const d = addDraft();
  if (!d.title) { modalError(new Error('Enter a title.')); return; }
  const r = await mutate('/api/nodes', d);
  closeModal(); if (d.parent_id) S.collapsed.delete(d.parent_id);
  S.selected = r.result; S.edit = true; E.dirty = false; renderAll(); toast(`${kindLabel(d.kind)} added. Write its content, then Save.`);
}

function moveDialog() {
  const n = nodeById(S.selected);
  modal('Move ' + n.title, 'Choose its new location. Everything inside it moves too.', `
    <label for="move-parent">Move inside</label><select id="move-parent">${parentOptions(n.parent_id, n.id)}</select>
    <p class="field-note" id="move-preview"></p>
    <div class="note">${icon('info')}<p>Links to this node keep working; their paths are updated automatically. It goes to the end of the new location.</p></div>`,
    `<button class="button" data-close>Cancel</button><button class="button primary" id="submit-move">Move</button>`);
  updateMovePreview();
}
function updateMovePreview() {
  const n = nodeById(S.selected), parent = $('#move-parent')?.value;
  if ($('#move-preview')) $('#move-preview').textContent = 'New path: ' + (parent ? nodePath(nodeById(parent)) : 'viking:/') + '/' + n.slug;
}

function deleteDialog() {
  const n = nodeById(S.selected), removed = descendants(n.id);
  const outside = S.tree.nodes.filter(x => !removed.has(x.id) && x.references.some(r => removed.has(r.target_id)));
  modal(`Delete ${n.title}?`, removed.size > 1 ? `This also deletes ${removed.size - 1} node${removed.size === 2 ? '' : 's'} inside it.` : 'This removes it from the draft.', `
    ${outside.length ? `<div class="note warning">${icon('info')}<div><p><strong>${outside.length} other node${outside.length === 1 ? '' : 's'} link here.</strong> Those links will break and block publishing until fixed:</p><ul>${outside.slice(0, 8).map(o => `<li>${esc(o.title)} <span class="muted">${esc(nodePath(o))}</span></li>`).join('')}</ul></div></div>` : ''}
    <p>Published versions are not affected.</p>`,
    `<button class="button" data-close>Keep it</button><button class="button danger" id="confirm-delete">${icon('trash')} Delete ${removed.size} node${removed.size === 1 ? '' : 's'}</button>`);
}

// Picks a target and inserts a [label](viking://…) link at the cursor. In decision mode it
// writes a whole line: "If <condition>, go to [target](viking://…)."
function linkDialog(decision = false) {
  const textarea = $('#node-content'); E.cursor = [textarea.selectionStart, textarea.selectionEnd]; E.decision = decision;
  const selectedText = textarea.value.slice(E.cursor[0], E.cursor[1]);
  modal(decision ? 'Add a decision' : 'Insert a link', decision ? 'Write the condition, then pick where the caller should go.' : 'Pick the node this content should point to.', `
    ${decision ? `<label for="decision-if">If…</label><input id="decision-if" value="${esc(selectedText)}" placeholder="e.g. the DSL light is off" autocomplete="off">` : ''}
    <label for="link-search">${decision ? '…go to' : 'Target'}</label>
    <div class="filter-wrap in-modal">${icon('search')}<input id="link-search" type="search" placeholder="Search title or path…" autocomplete="off"></div>
    <div class="pick-list" id="link-options" role="listbox" aria-label="Link target"></div>
    <label for="link-label">Link text</label><input id="link-label" value="${decision ? '' : esc(selectedText)}" placeholder="Defaults to the target title">
    <p class="field-note" id="link-sample"></p>`,
    `<button class="button" data-close>Cancel</button><button class="button primary" id="submit-link" disabled>${icon(decision ? 'route' : 'link')} ${decision ? 'Add decision' : 'Insert link'}</button>`);
  renderLinkOptions(); $(decision ? '#decision-if' : '#link-search').focus();
}
function linkText() {
  const target = nodeById(E.linkTarget); if (!target) return '';
  const label = ($('#link-label').value.trim() || target.title).replace(/[\[\]]/g, '');
  const link = `[${label}](${nodePath(target)})`;
  if (!E.decision) return link;
  const condition = $('#decision-if').value.trim().replace(/^if\s+/i, '').replace(/[,.]\s*$/, '');
  return `If ${condition || '…'}, go to ${link}.`;
}
function updateLinkSample() { if ($('#link-sample')) $('#link-sample').textContent = E.linkTarget ? 'Will insert: ' + linkText() : ''; }
function renderLinkOptions() {
  const n = nodeById(S.selected), q = ($('#link-search')?.value || '').toLowerCase();
  const near = new Set([...(n.parent_id ? children(n.parent_id) : []), ...children(n.id)].filter(c => c.id !== n.id).map(c => c.id));
  const match = x => !q || (x.title + ' ' + nodePath(x)).toLowerCase().includes(q);
  const option = x => `<button type="button" class="pick-option${E.linkTarget === x.id ? ' selected' : ''}" role="option" aria-selected="${E.linkTarget === x.id}" data-link-target="${esc(x.id)}">${icon(kindIcon(x))}<span>${esc(x.title)}<small>${esc(nodePath(x))}</small></span></button>`;
  const nearList = S.tree.nodes.filter(x => near.has(x.id) && match(x)).sort((a, b) => a.sort_order - b.sort_order);
  const rest = S.tree.nodes.filter(x => !near.has(x.id) && x.id !== n.id && match(x)).slice(0, 60);
  $('#link-options').innerHTML = (nearList.length ? `<h3>Nearby</h3>${nearList.map(option).join('')}` : '') + (rest.length ? `<h3>Everything else</h3>${rest.map(option).join('')}` : '') || '<p class="field-note">Nothing matches.</p>';
}
function insertLink() {
  if (!nodeById(E.linkTarget)) return;
  if (E.decision && !$('#decision-if').value.trim()) { modalError(new Error('Write the condition, e.g. “the DSL light is off”.')); return; }
  const textarea = $('#node-content'), [start, end] = E.cursor || [textarea.value.length, textarea.value.length];
  let text = linkText();
  // A decision always sits on its own line.
  if (E.decision) { const before = textarea.value.slice(0, start); if (before && !before.endsWith('\n')) text = '\n' + text; if (textarea.value.slice(end) && !textarea.value.slice(end).startsWith('\n')) text += '\n'; }
  textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
  closeModal(); textarea.focus(); textarea.setSelectionRange(start + text.length, start + text.length);
  E.dirty = true; renderToolbar(); previewLinks(true); growTextarea();
}

function issuesDialog() {
  const issues = S.tree.issues, review = S.tree.navigation_review;
  const open = (id, title, text, extra = '') => `<div class="result-row"><div><h3>${esc(title)}</h3><p>${esc(text)}</p>${extra}</div><button class="text-button" data-issue-node="${esc(id)}">Open</button></div>`;
  modal('Things to check', 'Broken links block publishing. AI suggestions are optional.', `
    <h3 class="dialog-section">Broken links · ${issues.length}</h3>
    ${issues.length ? issues.map(i => open(i.node_id, i.title, 'Not linked: ' + i.mention)).join('') : '<p class="field-note">None. Every link points to an existing node.</p>'}
    ${review ? `<h3 class="dialog-section">AI navigation review · ${review.issues.length}${review.revision !== S.tree.revision ? ' <span class="muted">(from an older draft)</span>' : ''}</h3>
    ${review.issues.length ? review.issues.map(i => open(i.node_id, nodeById(i.node_id)?.title || i.node_id, i.message, i.suggested_pathway ? `<div class="node-path">Suggested: ${esc(i.suggested_pathway)}</div>` : '')).join('') : '<p class="field-note">The model reported no navigation issues.</p>'}` : ''}`,
    '<button class="button" data-close>Close</button>');
}

function importDialog() {
  let p = nodeById(S.selected); while (p && !['folder', 'collection'].includes(p.kind)) p = nodeById(p.parent_id);
  modal('Import documents', 'Each heading becomes a node. “## Step 1 …” headings become steps. The original file is kept.', `
    <div class="dropzone">${icon('upload')}<h3>Markdown or text files</h3><p class="field-note">Up to 30 files · 500 KB each · 2 MB total</p><input type="file" id="import-files" multiple accept=".md,.markdown,.txt" aria-label="Select documents"></div>
    <label for="import-parent">Put them inside</label><select id="import-parent">${parentOptions(p?.id || null)}</select>
    <details style="margin-top:16px"><summary>Or paste a document</summary><label for="import-name">File name</label><input id="import-name" value="runbook.md"><label for="import-text">Content</label><textarea id="import-text" placeholder="# Runbook title&#10;&#10;## Step 1 · Check the cable&#10;Your instructions here. If it works, go to step2."></textarea></details>
    <label class="check-label"><input type="checkbox" id="import-ai"${S.settings.configured ? ' checked' : ' disabled'}><span>Write AI summaries and group the imported documents${S.settings.configured ? '' : ' · configure a model in API & models first'}</span></label>`,
    `<button class="button" data-close>Cancel</button><button class="button primary" id="submit-import">${icon('upload')} Import</button>`);
}
async function submitImport(button) {
  button.disabled = true;
  try {
    const files = await Promise.all([...$('#import-files').files].map(async f => ({name: f.name, content: await f.text()})));
    if ($('#import-text').value.trim()) files.push({name: $('#import-name').value, content: $('#import-text').value});
    const parent = $('#import-parent').value || null, useAI = $('#import-ai').checked;
    const r = await mutate('/api/import', {files, parent_id: parent});
    closeModal();
    if (r.result.added.length) { S.selected = r.result.added[0]; S.edit = false; renderAll(); }
    toast(`Imported ${r.result.added.length} document${r.result.added.length === 1 ? '' : 's'}${r.result.skipped.length ? `; skipped ${r.result.skipped.length} already imported` : ''}.`);
    if (useAI && r.result.added.length) await startBuild(parent, true, r.result.added);
  } finally { if (button.isConnected) button.disabled = false; }
}

// Compares the draft with the published version by node ID.
async function draftChanges() {
  const published = await api('/api/tree?version=' + encodeURIComponent(S.tree.published_version));
  const before = new Map(published.nodes.map(n => [n.id, n])), after = new Map(S.tree.nodes.map(n => [n.id, n]));
  const changes = {added: [], edited: [], moved: [], deleted: []};
  after.forEach((n, id) => {
    const old = before.get(id);
    if (!old) return changes.added.push(n);
    if (['title', 'kind', 'slug', 'content', 'summary', 'overview'].some(k => old[k] !== n[k])) changes.edited.push(n);
    if (old.parent_id !== n.parent_id || old.sort_order !== n.sort_order) changes.moved.push(n);
  });
  before.forEach((n, id) => { if (!after.has(id)) changes.deleted.push(n); });
  return {changes, label: published.versions.find(v => v.version === S.tree.published_version)?.label || S.tree.published_version};
}
async function publishDialog() {
  const issues = S.tree.issues, {changes, label} = await draftChanges();
  const total = Object.values(changes).reduce((sum, list) => sum + list.length, 0);
  const group = (name, list, cls) => list.length ? `<div class="change-group ${cls}"><h3>${name} · ${list.length}</h3><ul>${list.slice(0, 8).map(n => `<li>${cls === 'deleted' ? esc(n.title) : `<button class="text-button" data-issue-node="${esc(n.id)}">${esc(n.title)}</button>`}</li>`).join('')}${list.length > 8 ? `<li class="muted">and ${list.length - 8} more</li>` : ''}</ul></div>` : '';
  modal('Publish a version', 'Apps and the API read published versions. Your draft stays editable.', `
    <label for="publish-label">Version name</label><input id="publish-label" placeholder="e.g. November support runbooks" maxlength="200">
    <h3 class="dialog-section">Changes since “${esc(label)}”</h3>
    ${total ? `<div class="change-summary">${group('Added', changes.added, 'added')}${group('Edited', changes.edited, 'edited')}${group('Moved or reordered', changes.moved, 'moved')}${group('Deleted', changes.deleted, 'deleted')}</div>` : '<p class="field-note">The draft matches the published version.</p>'}
    ${issues.length ? `<div class="note warning">${icon('info')}<p>${issues.length} broken link${issues.length === 1 ? '' : 's'} must be fixed first.</p></div>${issues.slice(0, 10).map(i => `<div class="result-row"><div><h3>${esc(i.title)}</h3><p>Not linked: ${esc(i.mention)}</p></div><button class="text-button" data-issue-node="${esc(i.node_id)}">Open</button></div>`).join('')}`
      : `<div class="note">${icon('check')}<p>All links point to existing nodes.</p></div>`}`,
    `<button class="button" data-close>Cancel</button><button class="button primary" id="submit-publish"${issues.length ? ' disabled' : ''}>${icon('publish')} Publish</button>`);
}

function aiDialog(status) {
  const job = status.jobs.find(j => j.id === status.last?.id), running = job && ['running', 'queued'].includes(job.status);
  modal('AI summaries', 'Summaries are what the AI router reads. Your instructions are never rewritten.', `
    <div class="note">${icon('spark')}<p>${esc(aiStatusText(status))}</p></div>
    ${running ? `<div class="progress-bar"><span style="width:${job.progress}%"></span></div>` : ''}
    <div class="ai-actions">
      <button class="button" id="refresh-summaries"${!status.configured || running ? ' disabled' : ''}>${icon('reset')} Refresh all summaries</button>
      <button class="button" id="organize-area"${!status.configured || running ? ' disabled' : ''}>${icon('folder')} Organise an area…</button>
      <button class="button" id="toggle-auto">${status.settings.enabled ? 'Pause automatic refresh' : 'Refresh automatically after edits'}</button>
    </div>
    ${!status.configured ? '<p class="field-note">Connect a model in <a class="text-button" href="/settings">API & models</a> to use AI features.</p>' : ''}
    <h3 class="dialog-section">Recent builds</h3>
    ${status.jobs.slice(0, 6).map(j => `<div class="result-row"><div><h3>${esc(j.status)} · ${j.progress}%</h3><p>${esc(j.message)}</p><small class="muted">${esc(j.created_at)}</small></div></div>`).join('') || '<p class="field-note">No builds yet.</p>'}
    <p class="field-note"><a class="text-button" href="/settings#summary-settings">Change the token budget per layer</a></p>`,
    '<button class="button" data-close>Close</button>');
}
function aiStatusText(r) {
  const job = r.jobs.find(j => j.id === r.last?.id);
  if (job && ['running', 'queued'].includes(job.status)) return job.message;
  if (!r.configured) return 'No model connected';
  if (!r.settings.enabled) return 'Automatic refresh paused';
  if (r.pending) return 'Waiting for edits to settle…';
  if (r.last?.status === 'failed') return 'Last build failed: ' + r.last.message;
  if (r.last?.status === 'complete') return 'Summaries up to date';
  return 'Ready';
}
function organizeDialog() {
  modal('Organise an area', 'The AI writes summaries for everything in the area and can group its documents into folders.', `
    <label for="build-scope">Area</label><select id="build-scope">${parentOptions(S.selected)}</select>
    <label class="check-label"><input type="checkbox" id="build-organize"><span>Also group the documents directly inside it into topic folders</span></label>
    <div class="note">${icon('info')}<p>If the draft changes while this runs, the result is not applied. Review the draft before publishing.</p></div>`,
    `<button class="button" data-close>Cancel</button><button class="button primary" id="submit-build">${icon('spark')} Start</button>`);
}
async function startBuild(id, organize, root_ids = []) {
  const job = await api('/api/build', {id, organize, root_ids, revision: S.tree.revision});
  closeModal(); toast('AI build started. You can keep working; the draft refreshes when it finishes.');
  pollJob(job.id);
}
async function pollJob(id) {
  try {
    const job = await api('/api/jobs/' + encodeURIComponent(id));
    $('#ai-status').textContent = job.status === 'running' || job.status === 'queued' ? `AI · ${job.progress}%` : 'AI summaries';
    if (job.status === 'complete') { if (!S.edit) await loadTree('draft'); toast(job.message); return; }
    if (job.status === 'failed') { toast(job.message, true); return; }
    setTimeout(() => pollJob(id), 2000);
  } catch (e) { toast(e.message, true); }
}
let autoLoading = false, autoCompleted = null;
async function refreshAuto() {
  if (autoLoading) return; autoLoading = true;
  try {
    const r = await api('/api/summary-settings'), job = r.jobs.find(j => j.id === r.last?.id);
    const running = job && ['running', 'queued'].includes(job.status);
    $('#ai-status').textContent = running ? `AI · ${job.progress}%` : r.last?.status === 'failed' ? 'AI · failed' : 'AI summaries';
    $('#ai-button').title = aiStatusText(r);
    if (r.last?.status === 'complete' && autoCompleted !== r.last.id) {
      if (autoCompleted !== null && !S.edit && isDraft()) await loadTree('draft');
      autoCompleted = r.last.id;
    }
  } finally { autoLoading = false; }
}

function guideDialog() {
  const row = (title, text) => `<div class="guide-row"><h3>${title}</h3><p>${text}</p></div>`;
  modal('How the editor works', 'A short guide for writing runbooks the API can follow.', `
    ${row('1 · The tree', 'Collections hold folders, folders hold documents, and documents hold steps. Each node has a path such as <code>viking://troubleshooting/network/dropout/step1</code>. Apps receive that path with every instruction.')}
    ${row('2 · Add and edit', 'Use <b>+</b> on a row, <b>New</b>, or <b>Add step</b> on a document. Steps are numbered for you and added last. Click <b>Edit</b>, change the text, then <b>Save</b> (Ctrl S).')}
    ${row('3 · Link steps', 'In Edit, <b>Add decision</b> writes “If …, go to [step]”. <b>Insert link</b> points to any node. The panel under the editor shows whether each link works before you save. Links keep working when the target is renamed or moved.')}
    ${row('4 · Order matters', 'The API starts every runbook at its first step. Reorder with ↑ ↓ on the document page.')}
    ${row('5 · Navigation summary', 'The AI router reads only titles and summaries to pick a branch. Say which problems belong here, e.g. “Customer reports dropouts or intermittent loss of service”.')}
    ${row('6 · Publish', 'Edits stay in the draft until you <b>Publish</b>. The publish dialog lists what changed. Broken links must be fixed first.')}
    <p class="field-note">Shortcuts: <kbd>/</kbd> search the tree · <kbd>N</kbd> add inside the selected node · <kbd>E</kbd> edit · <kbd>Ctrl S</kbd> save · <kbd>Esc</kbd> cancel editing</p>`,
    '<button class="button primary" data-close>Got it</button>');
}

// ---------- render & selection ----------
function renderAll() {
  if (!S.tree) return;
  renderToolbar(); renderOutline();
  if (E.view === 'page') renderPage(); else renderGraph();
}
async function selectNode(id) {
  if (id === S.selected) { if (E.view !== 'page') setView('page'); return; }
  if (!(await ensureSaved())) return;
  S.selected = id; S.edit = false; E.dirty = false;
  ancestors(nodeById(id) || {}).forEach(a => S.collapsed.delete(a.id));
  if (E.view !== 'page') setView('page'); else renderAll();
  $(`.tree-row[data-node-id="${CSS.escape(id)}"]`)?.scrollIntoView({block: 'nearest'});
  $('#node-page').scrollTop = 0;
}
function setView(view) {
  E.view = view;
  $$('[data-view]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.view === view)));
  $('#node-page').hidden = view !== 'page'; $('#map-pane').hidden = view !== 'map';
  renderAll(); if (view === 'map') renderGraph(true);
}
async function reorder(id, dir) {
  const node = nodeById(id), ids = children(node.parent_id).map(c => c.id), i = ids.indexOf(id), j = i + dir;
  if (j < 0 || j >= ids.length) return;
  [ids[i], ids[j]] = [ids[j], ids[i]];
  await mutate('/api/nodes/reorder', {parent_id: node.parent_id, ids});
}

S.hooks = {
  select: selectNode,
  flush: async () => { if (!(await ensureSaved())) throw new Error('Save or discard your edits first.'); },
  tree: preserve => { if (!nodeById(S.selected)) { S.selected = S.tree.nodes[0]?.id || null; S.edit = false; } if (preserve && S.edit) { renderToolbar(); renderOutline(); } else renderAll(); },
  init: async () => {
    const node = new URLSearchParams(location.search).get('node');
    if (node && nodeById(node)) S.selected = node;
    renderAll(); await refreshAuto(); setInterval(() => refreshAuto().catch(() => {}), 4000);
  },
};

// ---------- events ----------
document.addEventListener('click', async event => {
  const b = event.target.closest('button,a'); if (!b) return;
  try {
    if (b.dataset.addChild !== undefined) { event.stopPropagation(); if (await ensureSaved()) addDialog(b.dataset.addChild); return; }
    if (b.dataset.toggle) { S.collapsed.has(b.dataset.toggle) ? S.collapsed.delete(b.dataset.toggle) : S.collapsed.add(b.dataset.toggle); renderOutline(); return; }
    if (b.dataset.view) { if (b.dataset.view !== E.view && (await ensureSaved())) setView(b.dataset.view); return; }
    if (b.dataset.reorder) { b.disabled = true; await reorder(b.dataset.reorder, Number(b.dataset.dir)); return; }
    if (b.dataset.linkTarget) { E.linkTarget = b.dataset.linkTarget; renderLinkOptions(); $('#submit-link').disabled = false; updateLinkSample(); return; }
    if (b.dataset.copy !== undefined) { await navigator.clipboard.writeText(b.dataset.copy); toast('Copied.'); return; }
    switch (b.id) {
      case 'unsaved-save': case 'unsaved-discard': case 'unsaved-cancel': E.unsaved?.(b.id.slice(8)); break;
      case 'add-root': if (await ensureSaved()) addDialog(null); break;
      case 'submit-add': b.disabled = true; try { await submitAdd(); } finally { if (b.isConnected) b.disabled = false; } break;
      case 'edit-node': S.edit = true; E.dirty = false; renderPage(); break;
      case 'cancel-edit': if (!E.dirty || (await ensureSaved())) { S.edit = false; E.dirty = false; renderAll(); } break;
      case 'insert-link': E.linkTarget = null; linkDialog(); break;
      case 'insert-decision': E.linkTarget = null; linkDialog(true); break;
      case 'guide-button': guideDialog(); break;
      case 'submit-link': insertLink(); break;
      case 'move-node': moveDialog(); break;
      case 'submit-move': { const id = S.selected, parent = $('#move-parent').value || null; await mutate('/api/nodes', {id, parent_id: parent, layout: null}); closeModal(); if (parent) S.collapsed.delete(parent); renderAll(); toast('Moved. Links to it were updated.'); break; }
      case 'delete-node': deleteDialog(); break;
      case 'confirm-delete': { const n = nodeById(S.selected); b.disabled = true; await mutate('/api/nodes/' + encodeURIComponent(n.id), {}, 'DELETE'); closeModal(); S.selected = n.parent_id && nodeById(n.parent_id) ? n.parent_id : S.tree.nodes[0]?.id; renderAll(); toast('Deleted from the draft.'); break; }
      case 'copy-path': { const n = nodeById(S.selected); await navigator.clipboard.writeText(JSON.stringify({pathway: nodePath(n), id: n.id, version: S.tree.version}, null, 2)); toast('API arguments copied.'); break; }
      case 'go-draft': await loadTree('draft'); break;
      case 'issues-button': issuesDialog(); break;
      case 'import-button': if (await ensureSaved()) importDialog(); break;
      case 'submit-import': await submitImport(b); break;
      case 'publish-button': if (await ensureSaved()) await publishDialog(); break;
      case 'submit-publish': b.disabled = true; try { const r = await mutate('/api/publish', {label: $('#publish-label').value}); closeModal(); toast('Published ' + r.result.version + '. The draft stays editable.'); } finally { if (b.isConnected) b.disabled = false; } break;
      case 'ai-button': aiDialog(await api('/api/summary-settings')); break;
      case 'refresh-summaries': if (await ensureSaved()) await startBuild(null, false); break;
      case 'organize-area': organizeDialog(); break;
      case 'submit-build': b.disabled = true; try { await startBuild($('#build-scope').value || null, $('#build-organize').checked); } finally { if (b.isConnected) b.disabled = false; } break;
      case 'toggle-auto': { const s = await api('/api/summary-settings'); await api('/api/summary-settings', {enabled: !s.settings.enabled}); aiDialog(await api('/api/summary-settings')); await refreshAuto(); break; }
    }
  } catch (e) { if ($('#modal').open) modalError(e); else toast(e.message, true); }
});
document.addEventListener('input', event => {
  const t = event.target;
  // Only the live form counts: a removed textarea can still fire events after a save re-renders the page.
  if (S.edit && $('#node-form')?.contains(t)) { E.dirty = true; $('#save-state').textContent = 'Unsaved changes'; updateEditorHints(); if (t.name === 'content') { previewLinks(); growTextarea(); } }
  if (t.id === 'decision-if' || t.id === 'link-label') updateLinkSample();
  if (t.id === 'tree-filter') renderOutline();
  if (t.id === 'add-title') updateAddPreview();
  if (t.id === 'link-search') renderLinkOptions();
});
document.addEventListener('change', async event => {
  const t = event.target;
  try {
    if (S.edit && $('#node-form')?.contains(t)) { E.dirty = true; renderToolbar(); if (t.name === 'kind') previewLinks(true); }
    if (t.name === 'add-kind' || t.id === 'add-parent' || t.id === 'add-number') updateAddPreview();
    if (t.id === 'move-parent') updateMovePreview();
    if (t.id === 'workspace-version') { if (await ensureSaved()) { S.edit = false; S.scope = null; S.zoom = null; await loadTree(t.value); } else t.value = S.tree.version; }
  } catch (e) { toast(e.message, true); }
});
document.addEventListener('submit', event => { if (event.target.id === 'node-form') { event.preventDefault(); saveEdit(); } }, true);
document.addEventListener('keydown', event => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && $('#node-form')) { event.preventDefault(); saveEdit(); }
  if (event.key === 'Enter' && event.target.id === 'add-title') { event.preventDefault(); $('#submit-add')?.click(); }
  if (event.key === 'Enter' && event.target.id === 'link-search') { event.preventDefault(); $('[data-link-target]')?.click(); }
  if (event.key === 'Enter' && event.target.id === 'decision-if') { event.preventDefault(); $('#link-search').focus(); }
  // Single-key shortcuts only when nothing is being typed and no dialog is open.
  if ($('#modal').open) return;
  if (event.key === 'Escape' && S.edit) { event.preventDefault(); $('#cancel-edit')?.click(); return; }
  const typing = event.target.closest('input,textarea,select,[contenteditable]');
  if (typing || S.edit || event.ctrlKey || event.metaKey || event.altKey) return;
  if (event.key === '/') { event.preventDefault(); $('#tree-filter').focus(); }
  if (event.key.toLowerCase() === 'e' && isDraft() && $('#edit-node')) { event.preventDefault(); $('#edit-node').click(); }
  if (event.key.toLowerCase() === 'n' && isDraft() && S.selected) { event.preventDefault(); addDialog(S.selected); }
  if (event.key === '?') { event.preventDefault(); guideDialog(); }
});
$('#outline').addEventListener('keydown', event => {
  const label = event.target.closest('.tree-label'); if (!label) return;
  const id = label.closest('.tree-row').dataset.nodeId, labels = $$('.tree-label', $('#outline')), index = labels.indexOf(label);
  const focus = nodeId => $(`[data-select="${CSS.escape(nodeId)}"]`, $('#outline'))?.focus();
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); labels[Math.max(0, Math.min(labels.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)))]?.focus(); }
  if (event.key === 'ArrowRight' && children(id).length) { event.preventDefault(); S.collapsed.delete(id); renderOutline(); focus(id); }
  if (event.key === 'ArrowLeft') { event.preventDefault(); if (children(id).length && !S.collapsed.has(id)) { S.collapsed.add(id); renderOutline(); focus(id); } else if (nodeById(id)?.parent_id) focus(nodeById(id).parent_id); }
});
window.addEventListener('beforeunload', event => { if (S.edit && E.dirty) { event.preventDefault(); event.returnValue = ''; } });

// Drag a row onto another row to move it inside that node.
let draggedRow = null;
document.addEventListener('dragstart', event => { const row = event.target.closest('.tree-row'); if (!row || !isDraft()) return; draggedRow = row.dataset.nodeId; event.dataTransfer.setData('text/plain', draggedRow); event.dataTransfer.effectAllowed = 'move'; });
document.addEventListener('dragover', event => { const row = event.target.closest('.tree-row'); if (row && draggedRow && !descendants(draggedRow).has(row.dataset.nodeId)) { event.preventDefault(); row.classList.add('drag-over'); } });
document.addEventListener('dragleave', event => event.target.closest('.tree-row')?.classList.remove('drag-over'));
document.addEventListener('drop', async event => {
  const row = event.target.closest('.tree-row'); if (!row || !draggedRow) return;
  event.preventDefault(); row.classList.remove('drag-over');
  const id = draggedRow, parent = row.dataset.nodeId; draggedRow = null;
  if (nodeById(id).parent_id === parent) return;
  try { if (!(await ensureSaved())) return; await mutate('/api/nodes', {id, parent_id: parent, layout: null}); S.collapsed.delete(parent); renderAll(); toast(`Moved inside ${nodeById(parent).title}. Links to it were updated.`); } catch (e) { toast(e.message, true); }
});
document.addEventListener('dragend', () => { draggedRow = null; $$('.drag-over').forEach(e => e.classList.remove('drag-over')); });
