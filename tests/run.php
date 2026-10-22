<?php
declare(strict_types=1);
// Each run uses a disposable database; the user's workspace is never touched.
$testDir = sys_get_temp_dir() . '/arbor-test-' . bin2hex(random_bytes(5)); mkdir($testDir, 0700);
putenv('ARBOR_DB=' . $testDir . '/test.sqlite');
require __DIR__ . '/../src/bootstrap.php';
use Arbor\{Repository, Tools, Models, ModelGateway, Importer, Mcp, ApiError};
use function Arbor\{state, setState, db, encode, now};
$passed = 0;
function check(bool $value, string $message): void { global $passed; if (!$value) throw new RuntimeException('FAIL: ' . $message); $passed++; echo "✓ $message\n"; }
function fails(callable $fn, string $code, string $message): void { try { $fn(); } catch (ApiError $e) { check($e->kind === $code, $message . ' [' . $e->kind . ']'); return; } throw new RuntimeException('FAIL: ' . $message . ' (no exception)'); }
function revision(): int { return state('draft')['revision']; }
final class FakeModels implements ModelGateway {
    public int $calls = 0; public bool $invalid = false; public bool $noAnswer = false; public bool $rejectFinal = false; public bool $conflict = false;
    public function json(string $instruction, array $data, int $tokens = 3000): array {
        $this->calls++;
        if ($this->conflict) { $d = state('draft'); $d['revision']++; setState('draft', $d); $this->conflict = false; }
        if (isset($data['candidates'])) return ['selected_ids' => $this->invalid ? ['fabricated_id'] : array_slice(array_column($data['candidates'], 'id'), 0, 2)];
        if (isset($data['navigation_review'])) return ['issues' => []];
        if (isset($data['sources'])) return ['answer' => $this->noAnswer ? null : 'Read the incident log before escalating.', 'citation_ids' => $this->noAnswer ? [] : [$data['sources'][0]['id']]];
        if (isset($data['documents'])) return ['groups' => [['title' => 'Generated folder', 'summary' => 'Example grouping.', 'document_ids' => array_column($data['documents'], 'id')]]];
        return ['summary' => 'A concise abstract of ' . ($data['title'] ?? 'the content') . '.', 'overview' => 'An overview based on supplied source content.'];
    }
    public function jev(string $question, array $candidates): array { return array_fill_keys(array_column($candidates, 'id'), ($candidates[0]['id'] ?? '') === 'final_answer' && $this->rejectFinal ? .1 : .9); }
}
try {
    $repo = new Repository(); $tools = new Tools($repo); $importer = new Importer($repo);
    check(count($repo->snapshot()['nodes']) === 14, 'Seed contains a complete sample tree');
    check($repo->issues($repo->snapshot()['nodes']) === [], 'Seed references resolve without inventing targets');
    $root = $tools->call('list_collections', []);
    check(count($root['data']['items']) === 2 && $root['version'] === 'sample_v1', 'Discovery returns menus and a pinned version');
    $read = $tools->call('read', ['pathway' => 'viking://troubleshooting//network/dropout', 'getNodeContent' => 'step1']);
    check(str_contains($read['data']['items'][0]['content'], 'go to step2'), 'Path-based reads return exact source wording');
    $ref = $read['data']['items'][0]['references'][0]['target']['read_args'];
    check($tools->call('read', $ref)['data']['items'][0]['id'] === 'demo_step2', 'Reference arguments directly read the correct next step');
    check($read['meta']['model_used'] === false, 'Direct reads never require an LLM');
    check(count($tools->call('read', ['ids' => ['demo_step1', 'demo_step2']])['data']['items']) === 2, 'Small batch reads preserve requested sources');
    $first = $tools->call('browse', ['id' => 'demo_dropout', 'limit' => 2]);
    $seen = array_column($first['data']['items'], 'id'); $cursor = $first['data']['next_cursor'];
    while ($cursor) { $page = $tools->call('browse', ['id' => 'demo_dropout', 'limit' => 2, 'cursor' => $cursor, 'version' => $first['version']]); $seen = array_merge($seen, array_column($page['data']['items'], 'id')); $cursor = $page['data']['next_cursor']; }
    check(count($seen) === 6 && count(array_unique($seen)) === 6, 'Pagination covers every child exactly once');
    fails(fn() => $tools->call('browse', ['id' => 'demo_network', 'cursor' => $first['data']['next_cursor']]), 'stale_cursor', 'Cursor cannot silently switch scopes');
    fails(fn() => $tools->call('read', ['id' => 'demo_step1', 'version' => 'missing']), 'version_not_found', 'Unknown versions never fall back to latest');
    fails(fn() => $tools->call('browse', ['depth' => 5]), 'invalid_argument', 'Browse depth is bounded');
    fails(fn() => $tools->call('read', ['ids' => array_fill(0, 11, 'demo_step1')]), 'batch_limit', 'Read batches are bounded');
    fails(fn() => $tools->call('search', ['query' => 'service', 'mode' => 'llm']), 'model_not_configured', 'Unconfigured AI returns an explicit error, not a fake answer');
    $search = $tools->call('search', ['query' => 'connection', 'mode' => 'lexical']);
    check($search['data']['items'] && !$search['meta']['model_used'] && $search['data']['answer'] === null, 'Lexical search is honest about its engine and answer');
    $scope = $tools->call('search', ['query' => 'incident', 'id' => 'demo_operations']);
    check(!array_diff(array_column($scope['data']['items'], 'id'), ['demo_operations', 'demo_handover']), 'Search cannot escape its subtree');
    check($tools->call('search', ['query' => 'zxqvzzunfindable'])['status'] === 'no_results', 'No-match keyword search is explicit');
    $rev = revision();
    fails(fn() => $repo->saveNode(['revision' => $rev, 'id' => 'demo_network', 'parent_id' => 'demo_step1']), 'tree_cycle', 'Moves cannot create ancestor cycles');
    check(revision() === $rev, 'Failed mutation rolls back without changing the revision');
    fails(fn() => $repo->saveNode(['revision' => $rev - 1, 'id' => 'demo_network', 'title' => 'Stale']), 'revision_conflict', 'Concurrent draft edits are rejected instead of overwritten');
    $repo->saveNode(['revision' => revision(), 'id' => 'demo_step2', 'title' => 'Incident lookup', 'slug' => 'incident-lookup']);
    $changed = $tools->call('read', ['id' => 'demo_step1', 'version' => 'draft']);
    check($changed['data']['items'][0]['references'][0]['target']['id'] === 'demo_step2', 'Reference IDs survive target renames');
    check(str_ends_with($changed['data']['items'][0]['references'][0]['target']['pathway'], '/incident-lookup'), 'Reference paths reflect the selected version');
    check($tools->call('read', ['id' => 'demo_step2', 'version' => 'sample_v1'])['data']['items'][0]['title'] !== 'Incident lookup', 'Published snapshots are immutable');
    $doc = "# Imported runbook\n\nStart with step1.\n\n## Step 1: Inspect\nCheck the cable. If connected, go to step2.\n\n## Step 2: Verify\nRepeat the test.\n\n```text\n## This is code, not a heading\n```";
    $import = $importer->import(['revision' => revision(), 'parent_id' => 'demo_operations', 'files' => [['name' => 'runbook.md', 'content' => $doc]]]);
    $docId = $import['result']['added'][0]; $snapshot = $repo->snapshot('draft');
    $sections = $repo->children($snapshot['nodes'], $docId);
    check(count($sections) === 2 && $sections[0]['slug'] === 'step1', 'Markdown headings create steps; fenced headings remain source content');
    check($repo->issues($snapshot['nodes']) === [], 'Imported step links resolve in their document scope');
    check($sections[0]['references'][0]['target_id'] === $sections[1]['id'], 'Imported explicit links point to exact sibling IDs');
    $q = db()->prepare('SELECT original FROM sources WHERE id=?'); $q->execute([$sections[0]['source']['id']]);
    check($q->fetchColumn() === $doc, 'Original imported source is preserved byte for byte');
    $again = $importer->import(['revision' => revision(), 'files' => [['name' => 'duplicate.md', 'content' => $doc]]]);
    check(count($again['result']['skipped']) === 1 && !$again['result']['added'], 'Identical source imports are deduplicated');
    $repo->saveNode(['revision' => revision(), 'id' => $sections[0]['id'], 'content' => 'Go to step99.']);
    fails(fn() => $repo->publish(revision()), 'unresolved_references', 'Unresolved steps block publication');
    $repo->saveNode(['revision' => revision(), 'title' => 'Step 99', 'slug' => 'step99', 'kind' => 'step', 'parent_id' => $docId]);
    check($repo->issues($repo->snapshot('draft')['nodes']) === [], 'Adding the exact missing step repairs unresolved mentions');
    $pub = $repo->publish(revision(), 'Test snapshot')['result']['version'];
    check($tools->call('list_collections', [])['version'] === $pub, 'Latest points at the newly published snapshot');
    $cursor = $tools->call('browse', ['id' => 'demo_dropout', 'limit' => 1, 'version' => 'draft'])['data']['next_cursor'];
    $repo->saveNode(['revision' => revision(), 'id' => 'demo_step3', 'summary' => 'Changed abstract.']);
    fails(fn() => $tools->call('browse', ['id' => 'demo_dropout', 'limit' => 1, 'version' => 'draft', 'cursor' => $cursor]), 'stale_cursor', 'Draft pagination detects intervening changes');
    $repo->deleteNode($docId, revision());
    check(!array_filter($repo->snapshot('draft')['nodes'], fn($n) => $n['id'] === $sections[0]['id']), 'Deleting a menu removes its descendants atomically');
    check($repo->resolve($repo->snapshot($pub), $docId)['id'] === $docId, 'Published content survives draft deletion');
    Models::save(['api_key' => 'fixture-key', 'model' => 'fixture-model', 'jev_key' => 'fixture-jev']);
    $config = Models::config(true);
    check(!isset($config['api_key'], $config['jev_key']) && $config['api_key_set'], 'Settings responses redact credentials');
    Models::save(['api_key' => '']); check(Models::config()['api_key'] === 'fixture-key', 'Empty key updates keep the stored credential');
    fails(fn() => Models::save(['endpoint' => 'http://remote.invalid/v1']), 'insecure_endpoint', 'Remote model endpoints require TLS');
    $fake = new FakeModels(); $ai = new Tools($repo, $fake);
    $r = $ai->call('search', ['query' => 'Connection outage', 'mode' => 'llm', 'id' => 'demo_dropout']);
    check($r['data']['answer'] && $r['data']['citations'], 'AI traversal returns a supported answer with valid source IDs');
    check(in_array('route', array_column($r['trace'], 'action')) && in_array('read_evidence', array_column($r['trace'], 'action')), 'AI trace records actual routing and evidence reads');
    $fake->invalid = true;
    fails(fn() => $ai->call('search', ['query' => 'Outage', 'mode' => 'llm']), 'invalid_model_output', 'Invented model IDs are rejected'); $fake->invalid = false;
    $fake->noAnswer = true; check($ai->call('search', ['query' => 'Outage', 'mode' => 'llm'])['status'] === 'no_answer', 'Insufficient model evidence produces no_answer'); $fake->noAnswer = false;
    $fake->rejectFinal = true;
    $r = $ai->call('search', ['query' => 'Outage', 'mode' => 'jev_llm']);
    check($r['status'] === 'no_answer' && $r['data']['answer'] === null, 'Jev can withhold an irrelevant final answer');
    check(in_array('jev_rank', array_column($r['trace'], 'action')) && in_array('verify_relevance', array_column($r['trace'], 'action')), 'Jev routing and final checks appear separately in the trace');
    $build = new Importer($repo, $fake);
    $makeJob = function (string $id): void { $q=db()->prepare('INSERT INTO jobs(id,status,payload,created_at,updated_at) VALUES(?,?,?,?,?)');$q->execute([$id,'queued',encode(['revision'=>revision(),'id'=>'demo_dropout','organize'=>false]),now(),now()]); };
    $makeJob('job_test'); $before = $repo->resolve($repo->snapshot('draft'), 'demo_step1')['content']; $build->work('job_test');
    check($build->job('job_test')['status'] === 'complete', 'Background build commits generated abstracts and overviews');
    check(state('navigation_review')['revision'] === revision(), 'LLM navigation review is associated with the exact draft revision');
    check($repo->resolve($repo->snapshot('draft'), 'demo_step1')['content'] === $before, 'AI builds do not rewrite original instructions');
    $makeJob('job_conflict'); $fake->conflict = true; $build->work('job_conflict');
    check($build->job('job_conflict')['result']['code'] === 'revision_conflict', 'AI build cannot overwrite edits made while the model was running');
    $mcp = new Mcp($tools, $repo);
    check($mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize'])['result']['protocolVersion'] === '2025-11-25', 'MCP initializes with an explicit protocol version');
    check(count($mcp->handle(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list'])['result']['tools']) === 4, 'MCP exposes all four navigation tools');
    $mcpRead = $mcp->handle(['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'read','arguments'=>['id'=>'demo_step1']]])['result'];
    check(!$mcpRead['isError'] && isset($mcpRead['structuredContent']['data']['items']), 'MCP returns both structured content and text fallback');
    check($mcp->handle(['jsonrpc'=>'2.0','id'=>4,'method'=>'tools/call','params'=>['name'=>'read','arguments'=>['id'=>'missing']]])['result']['isError'], 'MCP tool failures are explicit isError responses');
    check($mcp->handle(['jsonrpc'=>'2.0','method'=>'notifications/initialized']) === null, 'MCP notifications receive no JSON-RPC response');
    $proc = proc_open([PHP_BINARY, __DIR__.'/../bin/mcp.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], encode(['jsonrpc'=>'2.0','id'=>9,'method'=>'tools/list'])."\n"); fclose($pipes[0]); $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
    check($exit===0 && $err==='' && count(json_decode($out,true)['result']['tools'])===4, 'Real stdio transport produces clean JSON-RPC without log pollution');
    echo "\n$passed checks passed. No live model credentials were used.\n";
} finally {
    foreach (glob($testDir . '/*') as $file) unlink($file); rmdir($testDir);
}
