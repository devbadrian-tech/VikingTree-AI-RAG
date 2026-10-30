<?php
declare(strict_types=1);
namespace Arbor;

final class Repository
{
    public function __construct()
    {
        if (state('draft') === null) $this->seed();
    }

    public function snapshot(?string $version = null): array
    {
        if ($version === 'draft') {
            $draft = state('draft');
            return ['version' => 'draft', 'revision' => $draft['revision'], 'nodes' => $draft['nodes']];
        }
        $version = !$version || $version === 'latest' ? state('published') : $version;
        $q = db()->prepare('SELECT payload FROM snapshots WHERE version=?');
        $q->execute([$version]);
        $data = $q->fetchColumn();
        if (!$data) throw new ApiError('version_not_found', 'That published version does not exist.', 404);
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    public function versions(): array
    {
        return db()->query('SELECT version,label,created_at FROM snapshots ORDER BY rowid DESC')->fetchAll();
    }

    public function resolve(array $snapshot, array|string $ref): array
    {
        $ref = is_string($ref) ? ['id' => $ref] : $ref;
        if (!empty($ref['id'])) {
            foreach ($snapshot['nodes'] as $node) if ($node['id'] === $ref['id']) return $node;
            throw new ApiError('node_not_found', 'The node was not found in this version.', 404);
        }
        $path = $ref['pathway'] ?? $ref['path'] ?? 'viking://';
        if (!is_string($path) || !str_starts_with($path, 'viking://')) throw new ApiError('invalid_path', 'Paths must start with viking://.');
        $parts = array_values(array_filter(explode('/', substr($path, 9)), fn($p) => $p !== ''));
        if (!empty($ref['getNodeContent'])) $parts[] = (string)$ref['getNodeContent'];
        if (in_array('..', $parts) || in_array('.', $parts)) throw new ApiError('invalid_path', 'Relative path segments are not supported.');
        $parent = null; $found = null;
        foreach ($parts as $part) {
            $found = null;
            foreach ($snapshot['nodes'] as $node) if ($node['parent_id'] === $parent && $node['slug'] === $part) { $found = $node; break; }
            if (!$found) throw new ApiError('node_not_found', 'No node exists at this path in this version.', 404);
            $parent = $found['id'];
        }
        if (!$found) throw new ApiError('root_is_menu', 'Use browse to list the root menu.');
        return $found;
    }

    public function path(array $node, array $nodes): string
    {
        $byId = array_column($nodes, null, 'id'); $parts = [$node['slug']]; $seen = [];
        while ($node['parent_id'] && isset($byId[$node['parent_id']])) {
            if (isset($seen[$node['id']])) throw new ApiError('invalid_tree', 'A cycle was found.', 500);
            $seen[$node['id']] = true; $node = $byId[$node['parent_id']]; array_unshift($parts, $node['slug']);
        }
        return 'viking://' . implode('/', $parts);
    }

    public function children(array $nodes, ?string $parent): array
    {
        $items = array_values(array_filter($nodes, fn($n) => $n['parent_id'] === $parent));
        usort($items, fn($a, $b) => [$a['sort_order'], $a['title'], $a['id']] <=> [$b['sort_order'], $b['title'], $b['id']]);
        return $items;
    }

    public function card(array $node, array $snapshot): array
    {
        return ['id' => $node['id'], 'title' => $node['title'], 'kind' => $node['kind'],
            'pathway' => $this->path($node, $snapshot['nodes']), 'summary' => $node['summary'],
            'summary_origin' => $node['summary_origin'], 'child_count' => count($this->children($snapshot['nodes'], $node['id'])),
            'read_args' => ['id' => $node['id'], 'version' => $snapshot['version']],
            'browse_args' => ['id' => $node['id'], 'version' => $snapshot['version']]];
    }

    public function mutate(int $revision, callable $fn, bool $schedule = true): mixed
    {
        $db = db(); $db->exec('BEGIN IMMEDIATE');
        try {
            $draft = state('draft');
            if ($draft['revision'] !== $revision) throw new ApiError('revision_conflict', 'This draft changed. Reload before saving.', 409, ['current_revision' => $draft['revision']]);
            $before = Summaries::fingerprint($draft['nodes']);
            $result = $fn($draft);
            $this->validate($draft['nodes']);
            $draft['revision']++; $draft['updated_at'] = now();
            $changed = $schedule && $before !== Summaries::fingerprint($draft['nodes']);
            setState('draft', $draft);
            if ($changed) Summaries::dirty();
            $db->exec('COMMIT');
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        if ($changed) Summaries::wake();
        return ['revision' => $draft['revision'], 'result' => $result];
    }

    public function makeNode(array $data): array
    {
        $title = trim((string)($data['title'] ?? 'Untitled'));
        return ['id' => $data['id'] ?? id(), 'parent_id' => $data['parent_id'] ?? null,
            'title' => $title, 'slug' => $data['slug'] ?? slug($title), 'kind' => $data['kind'] ?? 'section',
            'summary' => $data['summary'] ?? excerpt($data['content'] ?? ''), 'overview' => $data['overview'] ?? '',
            'summary_origin' => $data['summary_origin'] ?? 'extractive', 'content' => $data['content'] ?? '',
            'source' => $data['source'] ?? null, 'sort_order' => (int)($data['sort_order'] ?? 0),
            'layout' => $data['layout'] ?? null, 'references' => [], 'updated_at' => now()];
    }

    public function saveNode(array $data): array
    {
        return $this->mutate((int)($data['revision'] ?? -1), function (&$draft) use ($data) {
            $index = null;
            if (!empty($data['id'])) foreach ($draft['nodes'] as $i => $n) if ($n['id'] === $data['id']) $index = $i;
            if (!empty($data['id']) && $index === null) throw new ApiError('node_not_found', 'Node no longer exists.', 404);
            $node = $index === null ? $this->makeNode($data) : $draft['nodes'][$index];
            foreach (['title', 'slug', 'kind', 'summary', 'overview', 'content', 'parent_id', 'sort_order', 'layout'] as $key) {
                if (array_key_exists($key, $data)) $node[$key] = $data[$key];
            }
            if (isset($data['summary'])) $node['summary_origin'] = 'manual';
            $node['updated_at'] = now();
            if ($index === null) { $draft['nodes'][] = $node; $index = count($draft['nodes']) - 1; } else $draft['nodes'][$index] = $node;
            // Preserve resolved IDs when moving/renaming; re-resolve only changed prose.
            if (array_key_exists('content', $data) || !empty($data['resolve_links'])) $draft['nodes'][$index]['references'] = $this->extractReferences($node, $draft['nodes']);
            foreach ($draft['nodes'] as &$other) {
                if (array_filter($other['references'], fn($r) => !$r['target_id'])) {
                    $fresh = $this->extractReferences($other, $draft['nodes']);
                    foreach ($other['references'] as &$ref) if (!$ref['target_id']) foreach ($fresh as $candidate) if ($ref['mention'] === $candidate['mention']) $ref = $candidate;
                    unset($ref);
                }
            }
            unset($other);
            return $node['id'];
        });
    }

    public function deleteNode(string $id, int $revision): array
    {
        return $this->mutate($revision, function (&$draft) use ($id) {
            $this->resolve(['nodes' => $draft['nodes']], $id);
            $deleted = $this->descendants($draft['nodes'], $id); $deleted[] = $id;
            $draft['nodes'] = array_values(array_filter($draft['nodes'], fn($n) => !in_array($n['id'], $deleted, true)));
            return ['deleted' => $deleted];
        });
    }

    public function descendants(array $nodes, string $id): array
    {
        $found = []; $queue = [$id];
        while ($queue) { $parent = array_shift($queue); foreach ($nodes as $n) if ($n['parent_id'] === $parent && !in_array($n['id'], $found, true)) { $found[] = $n['id']; $queue[] = $n['id']; } }
        return $found;
    }

    public function validate(array $nodes): void
    {
        if (count($nodes) > 10000) throw new ApiError('node_limit', 'This local edition supports up to 10,000 nodes.');
        $ids = array_column($nodes, null, 'id'); $paths = [];
        if (count($ids) !== count($nodes)) throw new ApiError('duplicate_id', 'Node IDs must be unique.');
        foreach ($nodes as $n) {
            if (!is_string($n['title']) || !trim($n['title']) || mb_strlen($n['title']) > 200) throw new ApiError('invalid_title', 'Titles must contain 1–200 characters.');
            if (!is_string($n['slug']) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $n['slug'])) throw new ApiError('invalid_slug', 'Slugs use lowercase letters, numbers, hyphens and underscores.');
            if (!in_array($n['kind'], ['collection', 'folder', 'document', 'section', 'step'], true)) throw new ApiError('invalid_kind', 'Unsupported node kind.');
            if (!is_int($n['sort_order']) || abs($n['sort_order']) > 1000000) throw new ApiError('invalid_order', 'Sort order must be an integer between -1,000,000 and 1,000,000.');
            foreach (['summary', 'overview', 'content'] as $field) if (!is_string($n[$field]) || strlen($n[$field]) > 100000) throw new ApiError('content_limit', 'Each text field must be a string of at most 100 KB.');
            if (mb_strlen($n['summary']) > 1200) throw new ApiError('summary_limit', 'Abstracts must be at most 1,200 characters.');
            if ($n['parent_id'] !== null && !isset($ids[$n['parent_id']])) throw new ApiError('invalid_parent', 'The parent does not exist.');
            $key = ($n['parent_id'] ?? 'root') . '/' . $n['slug'];
            if (isset($paths[$key])) throw new ApiError('duplicate_slug', 'Another node in this menu has the same slug.', 409);
            $paths[$key] = true; $seen = [$n['id'] => true]; $parent = $n['parent_id']; $depth = 0;
            while ($parent) { if (isset($seen[$parent])) throw new ApiError('tree_cycle', 'A node cannot be moved inside itself or a descendant.'); $seen[$parent] = true; $parent = $ids[$parent]['parent_id']; if (++$depth > 20) throw new ApiError('depth_limit', 'The tree may be at most 20 levels deep.'); }
            if ($n['layout'] !== null && (!is_array($n['layout']) || !isset($n['layout']['x'], $n['layout']['y']) || !is_numeric($n['layout']['x']) || !is_numeric($n['layout']['y']) || abs((float)$n['layout']['x']) > 50000 || abs((float)$n['layout']['y']) > 50000)) throw new ApiError('invalid_layout', 'Invalid canvas position.');
        }
    }

    public function extractReferences(array $node, array $nodes): array
    {
        $refs = []; $byId = array_column($nodes, null, 'id');
        preg_match_all('/\bstep\s*(\d+)\b/i', $node['content'], $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = 'step' . $match[1]; $targets = [];
            $scopeParent = in_array($node['kind'], ['document', 'folder', 'collection'], true) ? $node['id'] : $node['parent_id'];
            foreach ($nodes as $n) if ($n['parent_id'] === $scopeParent && ($n['slug'] === $key || preg_match('/^step\s*' . preg_quote($match[1], '/') . '\b/i', $n['title']))) $targets[] = $n;
            if (count($targets) === 1 && $targets[0]['id'] === $node['id']) continue;
            $refs[$key] = ['label' => $match[0], 'target_id' => count($targets) === 1 ? $targets[0]['id'] : null, 'origin' => 'explicit_text', 'mention' => $match[0]];
        }
        preg_match_all('/\[([^\]]+)\]\((viking:\/\/[^\s)]+)\)/', $node['content'], $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            try { $target = $this->resolve(['nodes' => $nodes], ['pathway' => $link[2]]); } catch (ApiError) { $target = null; }
            $refs[$link[2]] = ['label' => $link[1], 'target_id' => $target['id'] ?? null, 'origin' => 'explicit_link', 'mention' => $link[2]];
        }
        return array_values($refs);
    }

    public function issues(array $nodes): array
    {
        $ids = array_column($nodes, null, 'id'); $issues = [];
        foreach ($nodes as $n) foreach ($n['references'] as $ref) if (!$ref['target_id'] || !isset($ids[$ref['target_id']])) $issues[] = ['node_id' => $n['id'], 'title' => $n['title'], 'type' => 'unresolved_reference', 'mention' => $ref['mention']];
        return $issues;
    }

    public function publish(int $revision, string $label = ''): array
    {
        return $this->mutate($revision, function (&$draft) use ($label) {
            $issues = $this->issues($draft['nodes']);
            if ($issues) throw new ApiError('unresolved_references', 'Resolve broken references before publishing.', 422, ['issues' => $issues]);
            $version = 'kb_' . bin2hex(random_bytes(5));
            $snapshot = ['version' => $version, 'revision' => $draft['revision'], 'created_at' => now(), 'nodes' => $draft['nodes']];
            $q = db()->prepare('INSERT INTO snapshots VALUES(?,?,?,?)'); $q->execute([$version, $label ?: 'Published ' . gmdate('M j, H:i'), now(), encode($snapshot)]);
            setState('published', $version); return ['version' => $version];
        });
    }

    private function seed(): void
    {
        $spec = [
            ['troubleshooting', null, 'Troubleshooting', 'collection', 'Runbooks for network and application incidents.', 'Choose a service area, then read the relevant runbook. This is a fictional sample knowledge base.'],
            ['network', 'troubleshooting', 'Network', 'folder', 'Connection loss, slow connections and network checks.', 'Use these runbooks for connectivity incidents.'],
            ['dropout', 'network', 'Connection dropout', 'document', 'Six steps for diagnosing an interrupted service connection.', '# Connection dropout\n\nStart with step1. Follow the instructions and read the referenced step. The caller decides which condition applies.'],
            ['step1', 'dropout', 'Step 1 · Check service status', 'step', 'Determine whether the service is unavailable for everyone.', 'Check the service status page and ask whether other users are affected.\n\nIf the service is down for everyone, go to step2.\nIf the issue affects only one device, go to step3.'],
            ['step2', 'dropout', 'Step 2 · Check the incident log', 'step', 'Check for a known service incident before escalating.', 'Open your company incident log. Match the service name and affected region.\n\nIf an active incident explains the outage, record its reference and go to step6.\nIf there is no matching incident, go to step4.'],
            ['step3', 'dropout', 'Step 3 · Check local connection', 'step', 'Inspect the affected device and compare another network.', 'Confirm the network cable or Wi-Fi connection is active. Try another permitted network.\n\nIf the connection recovers, go to step5.\nIf the problem continues, go to step4.'],
            ['step4', 'dropout', 'Step 4 · Collect diagnostics', 'step', 'Collect timestamps, affected services and error messages.', 'Record the time, affected service, error message and checks already performed. Do not include passwords or authentication tokens.\n\nSend the diagnostic bundle through your normal support channel, then go to step6.'],
            ['step5', 'dropout', 'Step 5 · Verify recovery', 'step', 'Confirm the original activity works again.', 'Repeat the activity that failed and observe the connection for five minutes.\n\nIf it remains stable, close the incident with the checks performed.\nIf it drops again, go to step4.'],
            ['step6', 'dropout', 'Step 6 · Track and communicate', 'step', 'Record ownership and communicate the next update.', 'Record the incident owner, reference and next update time. Tell affected users where updates will appear.\n\nContinue monitoring under the incident owner’s direction.'],
            ['slow', 'network', 'Slow connection', 'document', 'Compare network performance and isolate local causes.', '# Slow connection\n\nCompare the same service on a second device. Check whether large transfers are running. Record the affected region and time window before contacting support.'],
            ['applications', 'troubleshooting', 'Applications', 'folder', 'Sign-in problems and application-specific incidents.', 'Select the affected application or symptom.'],
            ['signin', 'applications', 'Unable to sign in', 'document', 'Check the account, identity provider and error details.', '# Unable to sign in\n\nConfirm the account name and organization. Check the identity provider status. Capture the error code without recording passwords or one-time codes. Escalate using your company support channel.'],
            ['operations', null, 'Team operations', 'collection', 'Internal handovers and working procedures.', 'Fictional example documents for daily operations.'],
            ['handover', 'operations', 'Incident handover', 'document', 'What to include when handing an incident to another owner.', '# Incident handover\n\nInclude the incident reference, impact, timeline, checks completed, unresolved questions and the next update time. The receiving owner should acknowledge the handover.'],
        ];
        $nodes = []; $i = 0;
        foreach ($spec as [$key, $parent, $title, $kind, $summary, $content]) {
            $content = str_replace('\\n', "\n", $content);
            $nodes[] = $this->makeNode(['id' => 'demo_' . $key, 'parent_id' => $parent ? 'demo_' . $parent : null,
                'title' => $title, 'slug' => $key, 'kind' => $kind, 'summary' => $summary, 'content' => $content,
                'overview' => $summary, 'summary_origin' => 'sample', 'sort_order' => $i++,
                'source' => ['name' => 'Fictional example · not an operational policy', 'type' => 'sample']]);
        }
        foreach ($nodes as &$n) $n['references'] = $this->extractReferences($n, $nodes);
        unset($n);
        db()->exec('BEGIN IMMEDIATE');
        try {
            if (state('draft') === null) {
                setState('draft', ['revision' => 1, 'nodes' => $nodes, 'updated_at' => now()]);
                $snapshot = ['version' => 'sample_v1', 'revision' => 1, 'created_at' => now(), 'nodes' => $nodes];
                $q = db()->prepare('INSERT INTO snapshots VALUES(?,?,?,?)'); $q->execute(['sample_v1', 'Sample knowledge base', now(), encode($snapshot)]); setState('published', 'sample_v1');
            }
            db()->exec('COMMIT');
        } catch (\Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
    }
}
