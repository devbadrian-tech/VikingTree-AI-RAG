<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title><?= $page === 'lab' ? 'Traversal Lab' : ($page === 'settings' ? 'API & models' : 'Workspace') ?> · Arbor</title>
    <link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
    <?php if ($page === 'workspace' && $sessionAuth): ?><script src="/assets/editor.js" defer></script><?php endif; ?>
</head>
<body data-page="<?= $page ?>" data-locked="<?= $sessionAuth ? 'false' : 'true' ?>">
<a class="skip-link" href="#main">Skip to workspace</a>
<header class="app-header">
    <a href="/" class="brand"><img src="/assets/mark.svg" alt="" width="31" height="31"><span>arbor<span class="brand-period">.</span></span></a>
    <nav aria-label="Main navigation">
        <a href="/" <?= $page === 'workspace' ? 'aria-current="page"' : '' ?>><span data-icon="tree"></span> Workspace</a>
        <a href="/lab" <?= $page === 'lab' ? 'aria-current="page"' : '' ?>><span data-icon="route"></span> Traversal Lab</a>
        <a href="/settings" <?= $page === 'settings' ? 'aria-current="page"' : '' ?>><span data-icon="plug"></span> API & models</a>
    </nav>
    <div class="local-label"><span class="status-dot"></span> Local workspace</div>
</header>
<?php if (!$sessionAuth): ?>
<main class="login-view" id="main"><img src="/assets/mark.svg" width="44" alt=""><h1>Open your workspace</h1><p>Enter the access token configured for this Arbor instance.</p><form id="login-form"><label>Access token<input name="token" type="password" required autocomplete="current-password"></label><button class="button primary">Unlock workspace <span data-icon="arrow"></span></button></form></main>
<?php elseif ($page === 'workspace'): ?>
<main id="main" class="editor-main">
    <div class="editor-toolbar">
        <div class="toolbar-group">
            <span class="draft-dot" id="version-dot"></span><label class="sr-only" for="workspace-version">Content version</label><select id="workspace-version" class="version-select"><option value="draft">Working draft</option></select>
            <span id="save-state" class="save-state" role="status">Loading…</span>
            <span class="status-chip pending" id="publish-state" hidden title="The API still serves the last published version">Not published yet</span>
            <button class="status-chip" id="issues-button" hidden></button>
        </div>
        <div class="toolbar-group">
            <button class="button ghost" id="guide-button" title="How the editor works (?)"><span data-icon="info"></span> Guide</button>
            <button class="button ghost" id="ai-button" title="AI summaries and organisation"><span data-icon="spark"></span> <span id="ai-status">AI summaries</span></button>
            <button class="button ghost" id="import-button"><span data-icon="upload"></span> Import</button>
            <a class="button ghost" href="/api/export?version=draft" id="export-link"><span data-icon="download"></span> Export</a>
            <button class="button primary" id="publish-button"><span data-icon="publish"></span> Publish</button>
        </div>
    </div>
    <div class="editor-shell">
        <aside class="tree-pane" aria-label="Knowledge tree">
            <div class="pane-heading"><h2>Knowledge tree</h2><button class="button small-button" id="add-root"><span data-icon="plus"></span> New</button></div>
            <div class="filter-wrap"><span data-icon="search"></span><input id="tree-filter" type="search" placeholder="Find by title or path…" aria-label="Find a node"></div>
            <div id="outline" class="outline" role="tree" aria-label="Knowledge tree"></div>
            <div class="tree-hint"><span id="filter-count"></span>Drag a row onto another to move it inside · <kbd>/</kbd> search · <kbd>N</kbd> add · <kbd>E</kbd> edit</div>
        </aside>
        <section class="node-pane">
            <div class="view-tabs" role="tablist" aria-label="View"><button role="tab" aria-selected="true" data-view="page"><span data-icon="document"></span> Page</button><button role="tab" aria-selected="false" data-view="map"><span data-icon="tree"></span> Map</button></div>
            <div id="node-page" class="node-page"><div class="empty-state"><span data-icon="document"></span><h2>Pick something from the tree</h2><p>Select a node on the left to read, edit or link it.</p></div></div>
            <div id="map-pane" class="canvas-panel map-pane" hidden><div class="canvas-heading"><div><h2>Tree map</h2><span id="canvas-count" class="small muted"></span></div><div class="canvas-tools"><button class="icon-button" id="graph-root" aria-label="Show whole tree" title="Show whole tree"><span data-icon="tree"></span></button><button class="icon-button" id="graph-focus" aria-label="Focus selected subtree" title="Focus selected subtree"><span data-icon="focus"></span></button></div></div><div id="graph" class="graph-viewport"></div><div class="canvas-bottom"><span>Click a node to open it · drag to arrange the map (does not change the tree)</span><div class="zoom-controls"><button class="icon-button" data-zoom="out" aria-label="Zoom out">−</button><button class="text-button" id="fit-graph">Fit</button><button class="icon-button" data-zoom="in" aria-label="Zoom in">+</button></div></div></div>
        </section>
    </div>
</main>
<?php elseif ($page === 'lab'): ?>
<main id="main">
    <section class="page-heading"><div><div class="title-line"><h1>Traversal Lab</h1><span class="pill">Live API</span></div><p>Send a command. Follow the path. See exactly what comes back.</p></div><div class="heading-actions"><label class="sr-only" for="lab-version">Content version</label><select id="lab-version" class="button"></select><button class="button" id="reset-lab"><span data-icon="reset"></span> Clear session</button></div></section>
    <div class="lab-layout">
        <aside class="command-panel"><div class="panel-heading"><h2>Command console</h2><span class="small muted">01 → …</span></div><div class="command-body"><details open class="guided-tools"><summary>Use buttons, no JSON needed</summary><label for="guided-node">Node</label><select id="guided-node"></select><div class="form-actions"><button class="button" id="guided-collections">Collections</button><button class="button" id="guided-browse">Browse children</button><button class="button" id="guided-read">Read node</button></div><label for="guided-query">Question or keywords</label><input id="guided-query" placeholder="What should I check?"><label for="guided-mode">Search method</label><select id="guided-mode"><option value="navigate">Instructions only · navigate (cheapest AI)</option><option value="lexical">Keywords · no model</option><option value="llm">LLM search</option><option value="jev_llm">Jev + LLM search</option></select><label class="check-label" id="guided-answer-wrap" hidden><input type="checkbox" id="guided-answer"><span>Also write an answer (one larger model call)</span></label><button class="button primary" id="guided-search">Search selected area</button><p class="field-note">Select All collections to search everywhere. Advanced JSON commands remain available below.</p></details><label for="tool-name">Tool</label><select id="tool-name"><option>list_collections</option><option>browse</option><option>read</option><option>search</option><option>navigate</option></select><div class="field-heading"><label for="command-json">Arguments</label><span class="small muted">JSON</span></div><textarea id="command-json" class="code-editor" spellcheck="false" aria-describedby="command-hint">{}</textarea><p class="field-note" id="command-hint">The returned version is pinned to each request.</p><button class="button primary full-width" id="run-command"><span data-icon="play"></span> Send command <kbd>⌘ ↵</kbd></button><div class="divider"></div><div class="field-heading"><h3>Try a guided traversal</h3><span class="small muted" id="demo-progress">0 / 6</span></div><p class="field-note">Browse to a runbook, read step 1, then explicitly request step 2.</p><button class="button full-width" id="demo-next">Run next example <span data-icon="arrow"></span></button><div id="demo-description" class="demo-description">Start by discovering the root collections.</div></div><div class="panel-heading history-heading"><h2>Request history</h2><span class="small muted" id="history-count">0 calls</span></div><ol id="history" class="history"><li class="history-empty">Your requests will appear here.<br>Every entry is a real API call.</li></ol></aside>
        <div class="lab-main"><section class="canvas-panel lab-canvas" aria-label="Traversal tree"><div class="canvas-heading"><div><h2>Traversal map</h2><span class="small muted" id="canvas-count">Loading tree…</span></div><div class="trace-key"><button class="icon-button" id="graph-root" aria-label="Show whole tree" title="Show whole tree"><span data-icon="tree"></span></button><button class="icon-button" id="graph-focus" aria-label="Focus current branch" title="Focus current branch"><span data-icon="focus"></span></button><span><i class="legend-dot visited"></i> Visited</span><span><i class="legend-dot active"></i> Current</span></div></div><div id="graph" class="graph-viewport"></div><div class="canvas-bottom"><span id="trace-status">Select a node to prepare a read command.</span><div class="zoom-controls"><button class="icon-button" data-zoom="out" aria-label="Zoom out">−</button><button class="text-button" id="fit-graph">Fit</button><button class="icon-button" data-zoom="in" aria-label="Zoom in">+</button></div></div></section>
        <section class="response-panel"><div class="panel-heading"><div class="response-heading"><h2>Response</h2><span id="response-meta" class="small muted">Waiting for your first command</span></div><div class="tabs" role="tablist" aria-label="Response view"><button role="tab" aria-selected="true" data-response-tab="readable">Readable</button><button role="tab" aria-selected="false" data-response-tab="json">JSON</button><button role="tab" aria-selected="false" data-response-tab="trace">Trace</button></div></div><div id="response" class="response-body"><div class="response-empty"><span data-icon="route"></span><div><h3>A clear trail, from question to source.</h3><p>Browse menus without AI, read exact content, or watch a configured model explore the tree.</p></div></div></div></section></div>
    </div>
</main>
<?php else: ?>
<main id="main" class="settings-main">
    <section class="page-heading"><div><h1>API & models</h1><p>One knowledge tree. A simple contract for apps, agents and MCP clients.</p></div><div class="pill" id="model-status">Checking configuration…</div></section>
    <section class="settings-section" id="summary-settings"><h2>Summary budgets by layer</h2><p>Every content or hierarchy edit can refresh all node summaries after a quiet period. Summaries are generated from the leaves upward using the full hierarchy as context. Original step content and references are preserved. Changing canvas positions does not call the model.</p><form id="summary-form"><label class="check-label"><input type="checkbox" name="enabled"><span>Automatically refresh after changes (sends source content to your configured LLM)</span></label><label>Wait after the last edit (seconds)<input name="debounce" type="number" min="2" max="300" required></label><div class="budget-grid"><label>Layer 1<input name="layer1" type="number" min="128" max="4096" required></label><label>Layer 2<input name="layer2" type="number" min="128" max="4096" required></label><label>Layer 3<input name="layer3" type="number" min="128" max="4096" required></label><label>Layer 4 and deeper<input name="layer4" type="number" min="128" max="4096" required></label></div><p class="field-note">Hard model output limits include the JSON abstract and overview together. Budgets must increase or stay equal with depth. Individual fields also have character limits; these are not exact per-field token counts. Source instructions are never truncated to these limits.</p><button class="button primary" type="submit">Save summary settings</button><span id="summary-setting-status" role="status"></span></form></section><div class="settings-layout"><section class="settings-section"><div class="section-intro"><span data-icon="spark"></span><div><h2>Model connections</h2><p>Direct browsing and reading work without a model. Add an LLM for summaries, organization and guided retrieval.</p></div></div>
        <form id="settings-form"><label>LLM provider<select name="provider" id="provider"><option value="openai">OpenAI-compatible API</option><option value="anthropic">Anthropic Messages</option><option value="bedrock">AWS Bedrock Converse</option></select></label><div class="provider-api"><label>API base URL<input name="endpoint" type="url" placeholder="https://api.openai.com/v1" required></label><label>API key<input name="api_key" type="password" autocomplete="new-password" placeholder="Enter an API key"></label></div><label>Build model ID <span class="muted">(summaries, organisation, review)</span><input name="model" placeholder="Your provider’s model or inference profile ID"></label><label>Query model ID <span class="muted">(navigate and AI search)</span><input name="router_model" placeholder="Leave blank for the default"></label><p class="field-note">Bedrock defaults: Claude Sonnet 5.5 builds the tree and Claude Haiku 5.5 answers queries (au. profiles in Sydney/Melbourne). Other providers use the build model for queries until you set a query model.</p><div class="provider-aws" hidden><label>AWS region<input name="region" value="us-east-1"></label><label>Bedrock bearer token <span class="muted">(short-term API key)</span><input name="bedrock_token" type="password" autocomplete="new-password"></label><p class="field-note">AWS_BEARER_TOKEN_BEDROCK in .env takes precedence. After changing Docker environment values, recreate the containers. Expired tokens need replacement; STS session tokens belong in the separate field below.</p><label>Access key ID<input name="access_key" type="password" autocomplete="new-password"></label><label>Secret access key<input name="secret_key" type="password" autocomplete="new-password"></label><label>Session token <span class="muted">(optional)</span><input name="session_token" type="password" autocomplete="new-password"></label><p class="field-note">AWS environment credentials are also supported. Credentials stay on this server.</p></div><details class="jev-settings"><summary>Optional Jev decision model <span class="pill subtle">Optional</span></summary><p class="field-note">Scores branch relevance before LLM routing and checks final-answer relevance.</p><label>Jev API key<input name="jev_key" type="password" autocomplete="new-password"></label><label>Jev endpoint<input name="jev_endpoint" type="url" value="https://api.typesafe.ai/v1/systemone"></label><label>Relevance threshold<input name="jev_threshold" type="number" min="0" max="1" step="0.05" value="0.65"></label></details><div class="form-actions"><button class="button primary" type="submit">Save configuration</button><button class="button" type="button" id="test-model">Test LLM connection</button></div><p class="field-note">Keys are stored in the local database, outside the public folder. Empty key fields keep the existing value. AI requests send the selected content to your configured provider.</p><button type="button" class="text-button danger" id="clear-secrets">Remove stored credentials</button></form>
    </section><section class="settings-section api-guide"><div class="section-intro"><span data-icon="plug"></span><div><h2>A small, predictable API</h2><p>Keep the returned version and pass it with your next request.</p></div></div><div class="api-tools"><div><code>list_collections</code><span>Discover available menus</span></div><div><code>browse</code><span>List children, with pagination</span></div><div><code>read</code><span>Get exact content and references</span></div><div><code>search</code><span>Find sources or generate a cited answer</span></div><div><code>navigate</code><span>Return only the instructions to follow for an issue</span></div></div><h3>Read the next step</h3><pre class="api-code">POST /api/tools/read
Content-Type: application/json

{
  "pathway": "viking://troubleshooting/network/dropout",
  "getNodeContent": "step2",
  "version": "sample_v1"
}</pre><p class="field-note">Prefer the exact <code>read_args</code> returned by a reference. IDs remain stable when a draft node moves or gets renamed.</p><h3>MCP over HTTP</h3><pre class="api-code" id="mcp-http"></pre><p class="field-note">Stateless JSON-RPC POST · protocol 2025-11-25 · tools and resources. For an authenticated instance, send your access token as a Bearer token.</p><h3>MCP over stdio</h3><pre class="api-code">{
  "mcpServers": {
    "arbor": {
      "command": "php",
      "args": ["/absolute/path/to/arbor/bin/mcp.php"]
    }
  }
}</pre><div class="note"><span data-icon="info"></span><p>Arbor returns instructions; your application interprets them. “If yes, go to step2” never triggers an action automatically.</p></div><a class="text-button" href="/lab">Try the contract in Traversal Lab <span data-icon="arrow"></span></a></section></div>
</main>
<?php endif; ?>
<dialog id="modal"><div id="modal-content"></div></dialog>
<div id="toast-region" role="status" aria-live="polite"></div>
</body></html>
