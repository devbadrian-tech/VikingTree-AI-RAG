<?php
declare(strict_types=1);
namespace Arbor;

/** Portable knowledge only: never exports credentials, sessions, jobs or server settings. */
final class Transfer
{
    public function __construct(private Repository $repo) {}

    public function export(string $version = 'draft'): array
    {
        $snapshot = $this->repo->snapshot($version);
        $ids = array_unique(array_filter(array_map(fn($n) => $n['source']['id'] ?? null, $snapshot['nodes'])));
        $sources = [];
        $q = db()->prepare('SELECT id,name,original,hash,created_at FROM sources WHERE id=?');
        foreach ($ids as $id) { $q->execute([$id]); if ($s = $q->fetch()) $sources[] = $s; }
        return ['format' => 'arbor.v1', 'exported_at' => now(), 'snapshot' => $snapshot, 'sources' => $sources];
    }

    public function import(array $input): array
    {
        $package = $input['package'] ?? null;
        if (!is_array($package) || ($package['format'] ?? null) !== 'arbor.v1') throw new ApiError('invalid_package', 'Choose an arbor.v1 knowledge JSON export.');
        $nodes = $package['snapshot']['nodes'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || !$nodes || count($nodes) > 10000) throw new ApiError('invalid_package', 'The package must contain 1–10,000 nodes.');
        $ids = [];
        foreach ($nodes as $n) {
            if (!is_array($n) || !is_string($n['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $n['id']) || isset($ids[$n['id']])) throw new ApiError('invalid_package', 'Package node IDs must be unique strings.');
            if (!array_key_exists('parent_id', $n) || ($n['parent_id'] !== null && !is_string($n['parent_id']))) throw new ApiError('invalid_package', 'Invalid package parent ID.');
            foreach (['title','slug','kind','summary','overview','content'] as $f) if (!is_string($n[$f] ?? null)) throw new ApiError('invalid_package', 'Missing or invalid node field: ' . $f);
            if (!is_int($n['sort_order'] ?? null) || !is_array($n['references'] ?? null) || !array_is_list($n['references'])) throw new ApiError('invalid_package', 'Invalid order or references.');
            if (isset($n['source']) && (!is_array($n['source']) || !is_string($n['source']['name'] ?? null) || (isset($n['source']['id']) && !is_string($n['source']['id'])))) throw new ApiError('invalid_package', 'Invalid source provenance.');
            $ids[$n['id']] = id();
        }
        foreach ($nodes as $n) {
            if ($n['parent_id'] !== null && !isset($ids[$n['parent_id']])) throw new ApiError('invalid_package', 'Package parents must be inside the package.');
            foreach ($n['references'] as $r) {
                if (!is_array($r) || !array_key_exists('target_id', $r) || ($r['target_id'] !== null && (!is_string($r['target_id']) || !isset($ids[$r['target_id']])))) throw new ApiError('invalid_package', 'References must target package nodes, or be unresolved.');
                foreach (['label','mention','origin'] as $f) if (!is_string($r[$f] ?? null)) throw new ApiError('invalid_package', 'Invalid reference field.');
                if (!in_array($r['origin'], ['explicit_text','explicit_link'], true)) throw new ApiError('invalid_package', 'Unsupported reference origin.');
            }
        }
        $this->repo->validate(array_map(fn($n) => $n + ['layout' => null], $nodes));
        $sources = $package['sources'] ?? []; $sourceIds = [];
        if (!is_array($sources) || !array_is_list($sources)) throw new ApiError('invalid_package', 'Sources must be a list.');
        foreach ($sources as $s) {
            if (!is_array($s) || !is_string($s['id'] ?? null) || !is_string($s['name'] ?? null) || !is_string($s['original'] ?? null) || isset($sourceIds[$s['id']])) throw new ApiError('invalid_package', 'Invalid or duplicate source.');
            $sourceIds[$s['id']] = true;
        }
        return $this->repo->mutate((int)($input['revision'] ?? -1), function (&$draft) use ($nodes, $ids, $sources) {
            $sourceMap = [];
            foreach ($sources as $s) {
                $hash = hash('sha256', $s['original']);
                $q = db()->prepare('SELECT id FROM sources WHERE hash=?'); $q->execute([$hash]);
                $sid = $q->fetchColumn();
                if (!$sid) {
                    $sid = id('src'); $q = db()->prepare('INSERT INTO sources VALUES(?,?,?,?,?)');
                    $q->execute([$sid, basename($s['name']), $hash, $s['original'], now()]);
                }
                $sourceMap[$s['id']] = $sid;
            }
            $used = array_column($this->repo->children($draft['nodes'], null), 'slug');
            $orders = array_column($this->repo->children($draft['nodes'], null), 'sort_order');
            $order = $orders ? max($orders) + 1 : 0; $added = []; $imported = [];
            foreach ($nodes as $n) {
                $old = $n['id']; $n['id'] = $ids[$old];
                if ($n['parent_id'] === null) {
                    $base = $n['slug']; $i = 2;
                    while (in_array($n['slug'], $used, true)) $n['slug'] = substr($base, 0, 90) . '-' . $i++;
                    $used[] = $n['slug']; $n['sort_order'] = $order++; $added[] = $n['id'];
                } else $n['parent_id'] = $ids[$n['parent_id']];
                foreach ($n['references'] as &$r) if ($r['target_id'] !== null) $r['target_id'] = $ids[$r['target_id']];
                unset($r);
                // Older exports lack source bodies. Keep citation metadata without dangling source IDs.
                if (isset($n['source']['id'])) { $sid = $n['source']['id']; unset($n['source']['id']); if (isset($sourceMap[$sid])) $n['source']['id'] = $sourceMap[$sid]; }
                $n['updated_at'] = now(); $n['layout'] = null;
                $n['summary_origin'] = is_string($n['summary_origin'] ?? null) ? $n['summary_origin'] : 'manual';
                $imported[] = $n;
            }
            $draft['nodes'] = array_merge($draft['nodes'], $imported);
            return ['added' => $added, 'node_count' => count($imported), 'source_count' => count($sourceMap), 'id_map' => $ids];
        }, false); // Curated summaries are ready to use. Import never starts a paid model build.
    }
}
