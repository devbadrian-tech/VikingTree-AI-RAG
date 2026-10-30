<?php
declare(strict_types=1);
namespace Arbor;

final class Tools
{
    private int $routeCalls = 0;

    public function __construct(private Repository $repo, private ModelGateway $models = new Models()) {}

    public static function definitions(): array
    {
        $version = ['type' => 'string', 'description' => 'Published version returned by a previous call. Defaults to latest; draft is for preview only.'];
        $ref = ['id' => ['type' => 'string', 'description' => 'Stable node ID.'], 'pathway' => ['type' => 'string', 'description' => 'Readable viking:// path; omit for root.'], 'version' => $version];
        $specs = [
            'list_collections' => ['List the root menus. Use browse_args to explore a menu. No LLM call.', ['version' => $version, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => ['type' => 'string']], []],
            'browse' => ['List children of a node without interpreting its instructions. Results are paginated; follow next_cursor. No LLM call.', $ref + ['depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => ['type' => 'string']], []],
            'read' => ['Read exact source content and explicit references. Use the returned read_args for the next step; the caller chooses the branch. No LLM call.', $ref + ['getNodeContent' => ['type' => 'string', 'description' => 'Child slug, e.g. step2, relative to pathway.'], 'ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10], 'representation' => ['type' => 'string', 'enum' => ['content', 'abstract', 'overview']]], []],
            'search' => ['Find relevant content within an optional subtree. lexical needs no model; llm and jev_llm explore the hierarchy and return a trace, and items lists only the nodes where routing ended (not the menus passed through). Set answer=true to also generate a cited answer, which costs an extra, larger model call. To get just the instructions to execute for an issue, use navigate.', $ref + ['query' => ['type' => 'string'], 'mode' => ['type' => 'string', 'enum' => ['lexical', 'llm', 'jev_llm']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], 'answer' => ['type' => 'boolean', 'description' => 'AI modes only. Default false. True adds a generated answer with citations.']], ['query']],
            'navigate' => ['Route a request (e.g. a customer issue) through the tree with the cheap router model and return only the instruction nodes the caller should follow, one per matching problem. Unrelated branches and parent nodes are not returned; each instruction carries its viking:// pathway. Runbooks with steps start at step 1 unless the request names a step. Follow instructions[].next[].read_args with read to continue.', $ref + ['query' => ['type' => 'string', 'description' => 'The issue description, e.g. "Customer 1234 has dropouts and slow speed".'], 'max_instructions' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]], ['query']],
        ];
        $out = [];
        foreach ($specs as $name => [$description, $properties, $required]) $out[] = ['name' => $name, 'description' => $description, 'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false], 'outputSchema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'version' => ['type' => 'string'], 'data' => ['type' => 'object'], 'trace' => ['type' => 'array']], 'required' => ['status', 'version', 'data', 'trace']], 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => !in_array($name, ['search', 'navigate'], true), 'openWorldHint' => in_array($name, ['search', 'navigate'], true)]];
        return $out;
    }

    public function call(string $tool, array $args): array
    {
        $start = microtime(true);
        $allowed = array_column(self::definitions(), null, 'name');
        if (!isset($allowed[$tool])) throw new ApiError('unknown_tool', 'Unknown tool. Use list_collections, browse, read, search or navigate.', 404);
        foreach ($args as $k => $v) if (!isset($allowed[$tool]['inputSchema']['properties'][$k])) throw new ApiError('invalid_argument', 'Unknown argument: ' . $k);
        foreach ($args as $k => $v) {
            $p = $allowed[$tool]['inputSchema']['properties'][$k];
            $ok = match($p['type']) { 'string' => is_string($v), 'integer' => is_int($v), 'boolean' => is_bool($v), 'array' => is_array($v) && array_is_list($v), default => true };
            if (!$ok || (isset($p['enum']) && !in_array($v, $p['enum'], true)) || (isset($p['minimum']) && $v < $p['minimum']) || (isset($p['maximum']) && $v > $p['maximum'])) throw new ApiError('invalid_argument', 'Invalid argument: ' . $k);
        }
        $snapshot = $this->repo->snapshot($args['version'] ?? null); $trace = [];
        $usageBefore = $this->models instanceof Models ? $this->models->usage() : null; $this->routeCalls = 0;
        $data = match ($tool) {
            'list_collections' => $this->browse($snapshot, array_diff_key($args, array_flip(['id', 'pathway'])) + ['depth' => 1], $trace),
            'browse' => $this->browse($snapshot, $args, $trace),
            'read' => $this->read($snapshot, $args, $trace),
            'search' => $this->search($snapshot, $args, $trace),
            'navigate' => $this->navigate($snapshot, $args, $trace),
        };
        $meta = ['tool' => $tool, 'duration_ms' => (int)round((microtime(true) - $start) * 1000),
            'model_used' => $tool === 'navigate' ? $this->routeCalls > 0 : $tool === 'search' && ($args['mode'] ?? 'lexical') !== 'lexical'];
        if ($usageBefore !== null && $meta['model_used']) {
            $after = $this->models->usage();
            $meta['usage'] = ['calls' => $after['calls'] - $usageBefore['calls'], 'input_tokens' => $after['input_tokens'] - $usageBefore['input_tokens'],
                'output_tokens' => $after['output_tokens'] - $usageBefore['output_tokens'], 'model' => Models::config()['router_model']];
        }
        return ['status' => $data['status'] ?? 'ok', 'version' => $snapshot['version'], 'revision' => $snapshot['revision'], 'data' => $data,
            'trace' => $trace, 'meta' => $meta];
    }

    // Query-time model calls use the cheap router tier (Haiku by default on Bedrock).
    private function route(string $instruction, array $data, int $tokens): array
    {
        $this->routeCalls++;
        return $this->models->json($instruction, $data, $tokens, 'route');
    }

    // Walks the tree level by level. At each level the router sees only titles and abstracts of the
    // current candidates and picks the branches that match the request. A picked node with step
    // children resolves to its entry step; a picked leaf is returned as an instruction. Parents are
    // never returned: the instruction's pathway already shows where it sits.
    private function navigate(array $s, array $args, array &$trace): array
    {
        $query = trim($args['query'] ?? '');
        if ($query === '' || mb_strlen($query) > 2000) throw new ApiError('invalid_query', 'Enter a request of 1–2,000 characters.');
        if (!Models::config()['configured']) throw new ApiError('model_not_configured', 'Configure an LLM before using AI navigation.', 422);
        $nodes = $s['nodes']; $max = $args['max_instructions'] ?? 3; $limited = false; $found = [];
        $scope = (!empty($args['id']) || (!empty($args['pathway']) && rtrim($args['pathway'], '/') !== 'viking:')) ? $this->repo->resolve($s, $args) : null;
        // A scoped call starts inside the chosen node; an unscoped call starts by routing the root menus.
        $picked = $scope ? [$scope] : []; $frontier = $scope ? [] : $this->repo->children($nodes, null);
        for ($level = 1; $level <= 8; $level++) {
            if ($frontier) $picked = $this->pick($s, $query, $frontier, $level, $trace, $limited);
            $frontier = [];
            foreach ($picked as $node) {
                $children = $this->repo->children($nodes, $node['id']);
                $steps = array_values(array_filter($children, fn($c) => $c['kind'] === 'step'));
                if ($steps) { $step = $this->entryStep($query, $node, $steps, $trace); $found[$step['id']] = $step; }
                elseif ($children) array_push($frontier, ...$children);
                else $found[$node['id']] = $node;
            }
            $picked = [];
            if (!$frontier) break;
        }
        if ($frontier) $limited = true;
        if (count($found) > $max) { $limited = true; $found = array_slice($found, 0, $max, true); }
        $items = array_map(fn($n) => $this->instruction($n, $s), array_values($found));
        $trace[] = ['action' => 'return_instructions', 'node_ids' => array_column($items, 'id'), 'message' => 'Returned ' . count($items) . ' instruction nodes.'];
        return ['status' => $items ? 'ok' : ($limited ? 'budget_exhausted' : 'no_match'), 'engine' => 'navigate', 'instructions' => $items,
            'coverage' => $limited ? 'budget_limited' : 'selected_branches', 'limits' => ['max_depth' => 8, 'max_candidates_per_level' => 60, 'max_picks_per_level' => 5, 'max_instructions' => $max]];
    }

    private function pick(array $s, string $query, array $frontier, int $level, array &$trace, bool &$limited): array
    {
        if (count($frontier) > 60) { $limited = true; $frontier = array_slice($frontier, 0, 60); }
        // Short handles instead of node IDs keep the prompt small and make invented IDs detectable.
        $byKey = []; $candidates = [];
        foreach ($frontier as $i => $node) {
            $key = 'c' . ($i + 1); $byKey[$key] = $node;
            $candidates[] = ['id' => $key, 'title' => $node['title'], 'about' => excerpt($node['summary'], 400)] + ($this->repo->children($s['nodes'], $node['id']) ? ['menu' => true] : []);
        }
        // Menus are broad: a menu is picked when a problem could be handled somewhere inside it.
        $r = $this->route('You route a support request down a knowledge tree, one level at a time. Candidates with "menu":true contain more specific items; the others are instructions. Pick a menu if any problem in the request could be handled inside it; when more than one menu is plausible, pick all of them, because a missed menu loses the answer while an extra menu is checked again at the next level. Pick an instruction only if it matches a problem in the request. A request may describe several problems: cover each one with its best candidate. Skip candidates unrelated to every problem. Return {"selected_ids":["c1"]}, or {"selected_ids":[]} if nothing fits.', ['request' => $query, 'candidates' => $candidates], 120);
        $selected = $r['selected_ids'] ?? null;
        if (!is_array($selected) || !array_is_list($selected) || count($selected) > 5 || array_filter($selected, fn($k) => !is_string($k) || !isset($byKey[$k]))) throw new ApiError('invalid_model_output', 'The router returned invalid candidates.', 502);
        $picked = array_map(fn($k) => $byKey[$k], array_values(array_unique($selected)));
        $trace[] = ['action' => 'route', 'node_ids' => array_column($picked, 'id'), 'candidates' => array_column($frontier, 'id'), 'message' => count($picked) . ' of ' . count($frontier) . ' branches matched at level ' . $level . '.'];
        return $picked;
    }

    // Step sequences always start at the first step. Only a request that names a step is routed
    // to another one, e.g. "already did step 1" or "what is step 3".
    private function entryStep(string $query, array $parent, array $steps, array &$trace): array
    {
        $chosen = $steps[0]; $message = 'Started at the first step of ' . $parent['title'] . '.';
        if (count($steps) > 1 && preg_match('/\bsteps?\s*\d+/i', $query)) {
            $byKey = []; $candidates = [];
            foreach ($steps as $i => $step) { $key = 's' . ($i + 1); $byKey[$key] = $step; $candidates[] = ['id' => $key, 'title' => $step['title']]; }
            $r = $this->route('The request names a specific step of this runbook. Choose the one step the caller asked for, or the step that follows the steps the request says are already done. Return {"selected_ids":["s2"]}, or {"selected_ids":[]} to start at the first step.', ['request' => $query, 'runbook' => $parent['title'], 'candidates' => $candidates], 60);
            $selected = $r['selected_ids'] ?? null;
            if (!is_array($selected) || !array_is_list($selected) || array_filter($selected, fn($k) => !is_string($k) || !isset($byKey[$k]))) throw new ApiError('invalid_model_output', 'The router returned an invalid step.', 502);
            if ($selected) { $chosen = $byKey[$selected[0]]; $message = 'The request named a step; continued at ' . $chosen['title'] . '.'; }
        }
        $trace[] = ['action' => 'entry_step', 'node_ids' => [$chosen['id']], 'parent_id' => $parent['id'], 'message' => $message];
        return $chosen;
    }

    private function instruction(array $node, array $s): array
    {
        $next = [];
        foreach ($node['references'] as $ref) {
            if (!$ref['target_id']) continue;
            try { $target = $this->repo->resolve($s, $ref['target_id']); } catch (ApiError) { continue; }
            $next[] = ['label' => $ref['label'], 'pathway' => $this->repo->path($target, $s['nodes']), 'read_args' => ['id' => $target['id'], 'version' => $s['version']]];
        }
        $item = ['id' => $node['id'], 'pathway' => $this->repo->path($node, $s['nodes']), 'title' => $node['title'], 'kind' => $node['kind'], 'content' => $node['content'], 'source' => $node['source']];
        if ($node['kind'] === 'step' && $node['parent_id']) {
            $siblings = array_values(array_filter($this->repo->children($s['nodes'], $node['parent_id']), fn($c) => $c['kind'] === 'step'));
            $item['sequence'] = ['position' => array_search($node['id'], array_column($siblings, 'id'), true) + 1, 'total' => count($siblings)];
        }
        return $item + ['next' => $next, 'read_args' => ['id' => $node['id'], 'version' => $s['version']]];
    }

    private function browse(array $s, array $args, array &$trace): array
    {
        $node = !empty($args['id']) || (!empty($args['pathway']) && rtrim($args['pathway'], '/') !== 'viking:') ? $this->repo->resolve($s, $args) : null;
        $depth = $args['depth'] ?? 1; $limit = $args['limit'] ?? 30; $offset = 0;
        $scope = ($node['id'] ?? 'root') . ':' . $depth;
        if (!empty($args['cursor'])) {
            try { $cursor = json_decode(base64_decode($args['cursor'], true) ?: '', true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new ApiError('invalid_cursor', 'Invalid pagination cursor.'); }
            if (!is_array($cursor) || ($cursor['scope'] ?? '') !== $scope || ($cursor['version'] ?? '') !== $s['version'] || ($cursor['revision'] ?? -1) !== $s['revision'] || !is_int($cursor['offset'] ?? null) || $cursor['offset'] < 0) throw new ApiError('stale_cursor', 'Cursor belongs to another scope or revision. Keep version and depth fixed when paging.', 409);
            $offset = $cursor['offset'];
        }
        $all = []; $walk = function (?string $parent, int $level) use (&$walk, &$all, $s, $depth) {
            foreach ($this->repo->children($s['nodes'], $parent) as $child) { $all[] = $this->repo->card($child, $s) + ['parent_id' => $parent, 'depth' => $level]; if ($level < $depth) $walk($child['id'], $level + 1); }
        }; $walk($node['id'] ?? null, 1);
        $items = array_slice($all, $offset, $limit); $next = $offset + count($items);
        $trace[] = ['action' => 'browse', 'node_ids' => array_column($items, 'id'), 'parent_id' => $node['id'] ?? null, 'message' => 'Listed ' . count($items) . ' nodes.'];
        return ['parent' => $node ? $this->repo->card($node, $s) : null, 'items' => $items, 'total' => count($all), 'depth' => $depth,
            'next_cursor' => $next < count($all) ? base64_encode(encode(['scope' => $scope, 'version' => $s['version'], 'revision' => $s['revision'], 'offset' => $next])) : null,
            'truncated' => $next < count($all)];
    }

    private function read(array $s, array $args, array &$trace): array
    {
        if (isset($args['ids']) && (count($args['ids']) < 1 || count($args['ids']) > 10 || array_filter($args['ids'], fn($i) => !is_string($i)))) throw new ApiError('batch_limit', 'Read between 1 and 10 string node IDs.');
        $nodes = isset($args['ids']) ? array_map(fn($id) => $this->repo->resolve($s, $id), $args['ids']) : [$this->repo->resolve($s, $args)];
        $items = []; $representation = $args['representation'] ?? 'content';
        foreach ($nodes as $node) {
            $refs = [];
            foreach ($node['references'] as $ref) {
                $target = null;
                if ($ref['target_id']) try { $target = $this->repo->resolve($s, $ref['target_id']); } catch (ApiError) {}
                $refs[] = $ref + ['status' => $target ? 'resolved' : 'unresolved', 'target' => $target ? $this->repo->card($target, $s) : null];
            }
            $items[] = $this->repo->card($node, $s) + ['representation' => $representation, 'content' => match($representation) { 'abstract' => $node['summary'], 'overview' => $node['overview'], default => $node['content'] },
                'source' => $node['source'], 'references' => $refs, 'parent_id' => $node['parent_id'],
                'parent_args' => $node['parent_id'] ? ['id' => $node['parent_id'], 'version' => $s['version']] : ['version' => $s['version']]];
            $trace[] = ['action' => 'read', 'node_ids' => [$node['id']], 'message' => 'Read exact ' . $representation . ': ' . $node['title']];
        }
        return ['items' => $items];
    }

    private function search(array $s, array $args, array &$trace): array
    {
        $query = trim($args['query'] ?? '');
        if ($query === '' || mb_strlen($query) > 2000) throw new ApiError('invalid_query', 'Enter a question of 1–2,000 characters.');
        $scope = (!empty($args['id']) || (!empty($args['pathway']) && rtrim($args['pathway'], '/') !== 'viking:')) ? $this->repo->resolve($s, $args) : null;
        $nodes = $s['nodes'];
        if ($scope) { $ids = $this->repo->descendants($nodes, $scope['id']); $ids[] = $scope['id']; $nodes = array_values(array_filter($nodes, fn($n) => in_array($n['id'], $ids, true))); }
        $mode = $args['mode'] ?? 'lexical'; $limit = $args['limit'] ?? 6;
        if ($mode === 'lexical') {
            $terms = array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY))); $ranked = [];
            $stop = ['the', 'is', 'a', 'an', 'to', 'of', 'and', 'what', 'how', 'do', 'i', 'for', 'in', 'it', 'my'];
            $terms = array_values(array_diff($terms, $stop));
            foreach ($nodes as $n) {
                $score = 0;
                foreach ($terms as $term) { if (mb_stripos($n['title'], $term) !== false) $score += 5; if (mb_stripos($n['summary'], $term) !== false) $score += 2; if (mb_stripos($n['content'], $term) !== false) $score += 1; }
                if ($score > 0) $ranked[] = ['node' => $n, 'score' => $score];
            }
            usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
            $items = array_map(fn($r) => $this->repo->card($r['node'], $s) + ['snippet' => excerpt($r['node']['content'], 260), 'rank_score' => $r['score']], array_slice($ranked, 0, $limit));
            $trace[] = ['action' => 'lexical_search', 'node_ids' => array_column($items, 'id'), 'message' => 'Matched words across ' . count($nodes) . ' nodes. No model was called.'];
            return ['status' => $items ? 'ok' : 'no_results', 'engine' => 'lexical', 'items' => $items, 'answer' => null, 'answer_note' => 'Lexical mode returns source matches, not a generated answer.', 'coverage' => 'entire_scope'];
        }
        if (!Models::config()['configured']) throw new ApiError('model_not_configured', 'Configure an LLM before using AI traversal.', 422);
        $frontier = $scope ? [$scope] : $this->repo->children($nodes, null); $evidence = []; $visited = []; $limited = false;
        for ($depth = 0; $depth < 8 && $frontier; $depth++) {
            if (count($frontier) > 60) { $limited = true; $frontier = array_slice($frontier, 0, 60); }
            $candidates = array_map(fn($n) => ['id' => $n['id'], 'title' => $n['title'], 'abstract' => $n['summary'], 'has_children' => (bool)$this->repo->children($nodes, $n['id'])], $frontier);
            $scores = null;
            if ($mode === 'jev_llm') {
                $scores = $this->models->jev($query, $candidates); $threshold = (float)Models::config()['jev_threshold'];
                $candidates = array_values(array_filter($candidates, fn($c) => $scores[$c['id']] >= $threshold));
                $trace[] = ['action' => 'jev_rank', 'node_ids' => array_column($candidates, 'id'), 'scores' => $scores, 'threshold' => $threshold, 'message' => 'Kept branches at or above the configured relevance threshold.'];
            }
            if (!$candidates) { $trace[] = ['action' => 'stop', 'node_ids' => [], 'message' => 'No relevant branch passed the threshold.']; break; }
            if ($mode === 'jev_llm' && count($candidates) === 1) $selected = [$candidates[0]['id']];
            else {
                $decision = $this->route('Select up to 3 candidate IDs worth exploring for the question. Return {"selected_ids":["id"]}. Return an empty list if none are relevant. You may select multiple branches. Only choose from supplied candidates.', ['question' => $query, 'candidates' => $candidates], 150);
                $selected = $decision['selected_ids'] ?? null;
                if (!is_array($selected) || !array_is_list($selected) || count($selected) > 3 || array_filter($selected, fn($id) => !is_string($id) || !in_array($id, array_column($candidates, 'id'), true))) throw new ApiError('invalid_model_output', 'The router returned invalid node IDs.', 502);
                $selected = array_values(array_unique($selected));
            }
            $trace[] = ['action' => 'route', 'node_ids' => $selected, 'candidates' => array_column($candidates, 'id'), 'message' => count($selected) . ' branches selected at level ' . ($depth + 1) . '.'];
            if (!$selected) { $trace[] = ['action' => 'stop', 'node_ids' => [], 'message' => 'The router found no useful branch.']; break; }
            $next = [];
            foreach ($selected as $id) {
                $n = $this->repo->resolve($s, $id); $visited[] = $id;
                if (trim($n['content'])) $evidence[$id] = $n;
                foreach ($this->repo->children($nodes, $id) as $child) if (!in_array($child['id'], $visited, true)) $next[] = $child;
            }
            $frontier = $next;
            if ($depth === 7 && $frontier) $limited = true;
        }
        // Prefer deeper source nodes; keep the prompt bounded and report incomplete coverage.
        $evidence = array_reverse(array_values($evidence)); $sourceBudget = 0; $sources = [];
        foreach ($evidence as $n) { if (count($sources) >= 10 || $sourceBudget + strlen($n['content']) > 80000) { $limited = true; continue; } $sourceBudget += strlen($n['content']); $sources[] = ['id' => $n['id'], 'title' => $n['title'], 'content' => $n['content']]; }
        $trace[] = ['action' => 'read_evidence', 'node_ids' => array_column($sources, 'id'), 'message' => 'Loaded ' . count($sources) . ' source nodes as evidence.'];
        $answer = null; $citations = []; $status = $sources ? 'ok' : ($limited ? 'budget_exhausted' : 'no_answer');
        // Writing an answer is the most expensive call (all evidence in, a paragraph out), so it is opt-in.
        if (($args['answer'] ?? false) && $sources) {
            $result = $this->route('Answer the question using only the source content. Return {"answer":string|null,"citation_ids":["id"]}. Cite the exact supplied source IDs that support the answer. If evidence is insufficient return null and []. Do not infer that a hypothetical condition has occurred. Preserve exact step references and wording when explaining instructions.', ['question' => $query, 'sources' => $sources], 2500);
            $answer = $result['answer'] ?? null; $citations = $result['citation_ids'] ?? [];
            if (($answer !== null && !is_string($answer)) || !is_array($citations) || !array_is_list($citations) || array_filter($citations, fn($id) => !is_string($id) || !in_array($id, array_column($sources, 'id'), true)) || ($answer && !$citations)) throw new ApiError('invalid_model_output', 'The answer was missing valid source citations.', 502);
            if (!$answer) { $answer = null; $citations = []; $status = $limited ? 'budget_exhausted' : 'no_answer'; }
            if ($answer && $mode === 'jev_llm') {
                $check = $this->models->jev($query, [['id' => 'final_answer', 'answer' => $answer, 'supporting_sources' => array_values(array_filter($sources, fn($n) => in_array($n['id'], $citations)))]]);
                $accepted = $check['final_answer'] >= (float)Models::config()['jev_threshold'];
                $trace[] = ['action' => 'verify_relevance', 'node_ids' => $citations, 'score' => $check['final_answer'], 'message' => $accepted ? 'Answer passed the final Jev relevance threshold.' : 'Answer withheld by the final relevance check.'];
                if (!$accepted) { $answer = null; $citations = []; $status = 'no_answer'; }
            }
        }
        // Return only where routing ended: drop any node that is an ancestor of another selected node
        // (e.g. Network and Connection dropout when a dropout step was selected). Parents are visible in each pathway.
        $byId = array_column($s['nodes'], null, 'id'); $passedThrough = [];
        foreach ($visited as $id) { $p = $byId[$id]['parent_id'] ?? null; for ($i = 0; $p && $i < 25; $i++) { $passedThrough[$p] = true; $p = $byId[$p]['parent_id'] ?? null; } }
        $final = array_values(array_filter($evidence, fn($n) => !isset($passedThrough[$n['id']])));
        $items = array_map(fn($n) => $this->repo->card($n, $s) + ['snippet' => excerpt($n['content'], 260)], array_slice($final, 0, $limit));
        return ['status' => $status, 'engine' => $mode, 'items' => $items, 'answer' => $answer,
            'citations' => array_map(fn($id) => $this->repo->card($this->repo->resolve($s, $id), $s), $citations),
            'coverage' => $limited ? 'budget_limited' : 'selected_branches', 'limits' => ['max_depth' => 8, 'branches_per_level' => 3, 'max_candidates_per_level' => 60, 'max_evidence_nodes' => 10]];
    }
}
