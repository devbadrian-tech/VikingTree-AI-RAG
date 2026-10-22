<?php
declare(strict_types=1);
namespace Arbor;

final class Tools
{
    public function __construct(private Repository $repo, private ModelGateway $models = new Models()) {}

    public static function definitions(): array
    {
        $version = ['type' => 'string', 'description' => 'Published version returned by a previous call. Defaults to latest; draft is for preview only.'];
        $ref = ['id' => ['type' => 'string', 'description' => 'Stable node ID.'], 'pathway' => ['type' => 'string', 'description' => 'Readable viking:// path; omit for root.'], 'version' => $version];
        $specs = [
            'list_collections' => ['List the root menus. Use browse_args to explore a menu. No LLM call.', ['version' => $version, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => ['type' => 'string']], []],
            'browse' => ['List children of a node without interpreting its instructions. Results are paginated; follow next_cursor. No LLM call.', $ref + ['depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => ['type' => 'string']], []],
            'read' => ['Read exact source content and explicit references. Use the returned read_args for the next step; the caller chooses the branch. No LLM call.', $ref + ['getNodeContent' => ['type' => 'string', 'description' => 'Child slug, e.g. step2, relative to pathway.'], 'ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10], 'representation' => ['type' => 'string', 'enum' => ['content', 'abstract', 'overview']]], []],
            'search' => ['Find relevant content within an optional subtree. lexical needs no model; llm and jev_llm explore the hierarchy and return a trace. Only AI modes can synthesize a cited answer.', $ref + ['query' => ['type' => 'string'], 'mode' => ['type' => 'string', 'enum' => ['lexical', 'llm', 'jev_llm']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], 'answer' => ['type' => 'boolean']], ['query']],
        ];
        $out = [];
        foreach ($specs as $name => [$description, $properties, $required]) $out[] = ['name' => $name, 'description' => $description, 'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false], 'outputSchema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'version' => ['type' => 'string'], 'data' => ['type' => 'object'], 'trace' => ['type' => 'array']], 'required' => ['status', 'version', 'data', 'trace']], 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => $name !== 'search', 'openWorldHint' => $name === 'search']];
        return $out;
    }

    public function call(string $tool, array $args): array
    {
        $start = microtime(true);
        $allowed = array_column(self::definitions(), null, 'name');
        if (!isset($allowed[$tool])) throw new ApiError('unknown_tool', 'Unknown tool. Use list_collections, browse, read or search.', 404);
        foreach ($args as $k => $v) if (!isset($allowed[$tool]['inputSchema']['properties'][$k])) throw new ApiError('invalid_argument', 'Unknown argument: ' . $k);
        foreach ($args as $k => $v) {
            $p = $allowed[$tool]['inputSchema']['properties'][$k];
            $ok = match($p['type']) { 'string' => is_string($v), 'integer' => is_int($v), 'boolean' => is_bool($v), 'array' => is_array($v) && array_is_list($v), default => true };
            if (!$ok || (isset($p['enum']) && !in_array($v, $p['enum'], true)) || (isset($p['minimum']) && $v < $p['minimum']) || (isset($p['maximum']) && $v > $p['maximum'])) throw new ApiError('invalid_argument', 'Invalid argument: ' . $k);
        }
        $snapshot = $this->repo->snapshot($args['version'] ?? null); $trace = [];
        $data = match ($tool) {
            'list_collections' => $this->browse($snapshot, array_diff_key($args, array_flip(['id', 'pathway'])) + ['depth' => 1], $trace),
            'browse' => $this->browse($snapshot, $args, $trace),
            'read' => $this->read($snapshot, $args, $trace),
            'search' => $this->search($snapshot, $args, $trace),
        };
        return ['status' => $data['status'] ?? 'ok', 'version' => $snapshot['version'], 'revision' => $snapshot['revision'], 'data' => $data,
            'trace' => $trace, 'meta' => ['tool' => $tool, 'duration_ms' => (int)round((microtime(true) - $start) * 1000), 'model_used' => $tool === 'search' && ($args['mode'] ?? 'lexical') !== 'lexical']];
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
                $decision = $this->models->json('Select up to 3 candidate IDs worth exploring for the question. Return {"selected_ids":["id"]}. Return an empty list if none are relevant. You may select multiple branches. Only choose from supplied candidates.', ['question' => $query, 'candidates' => $candidates], 1000);
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
        if (($args['answer'] ?? true) && $sources) {
            $result = $this->models->json('Answer the question using only the source content. Return {"answer":string|null,"citation_ids":["id"]}. Cite the exact supplied source IDs that support the answer. If evidence is insufficient return null and []. Do not infer that a hypothetical condition has occurred. Preserve exact step references and wording when explaining instructions.', ['question' => $query, 'sources' => $sources], 2500);
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
        $items = array_map(fn($n) => $this->repo->card($n, $s) + ['snippet' => excerpt($n['content'], 260)], array_slice($evidence, 0, $limit));
        return ['status' => $status, 'engine' => $mode, 'items' => $items, 'answer' => $answer,
            'citations' => array_map(fn($id) => $this->repo->card($this->repo->resolve($s, $id), $s), $citations),
            'coverage' => $limited ? 'budget_limited' : 'selected_branches', 'limits' => ['max_depth' => 8, 'branches_per_level' => 3, 'max_candidates_per_level' => 60, 'max_evidence_nodes' => 10]];
    }
}
