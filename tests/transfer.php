<?php
declare(strict_types=1);
foreach (['OPENAI_API_KEY','OPENAI_BASE_URL','ARBOR_LLM_PROVIDER','ARBOR_LLM_MODEL','ARBOR_ROUTER_MODEL','AWS_REGION','AWS_BEARER_TOKEN_BEDROCK','AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_SESSION_TOKEN','ARBOR_AUTO_SUMMARIZE'] as $key) putenv($key . '=');
putenv('ARBOR_EXTERNAL_WORKER=1');
$dir = sys_get_temp_dir() . '/arbor-transfer-' . bin2hex(random_bytes(5)); mkdir($dir, 0700);
putenv('ARBOR_DB=' . $dir . '/test.sqlite');
require __DIR__ . '/../src/bootstrap.php';
use Arbor\{ApiError, Repository, Transfer, Tools, Summaries};
use function Arbor\{state, setState, db};
$count = 0;
function verify(bool $ok, string $message): void { global $count; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $count++; echo "✓ $message\n"; }
function rejected(callable $fn, string $code): void { try { $fn(); } catch (ApiError $e) { verify($e->kind === $code, 'Reject ' . $code . ' atomically'); return; } throw new RuntimeException('Expected ' . $code); }
try {
    $repo = new Repository(); $transfer = new Transfer($repo); $tools = new Tools($repo);
    $packagePath = getenv('ARBOR_TEST_PACKAGE') ?: __DIR__ . '/../exports/nbn-troubleshooting.arbor.json';
    $pack = is_file($packagePath) ? json_decode(file_get_contents($packagePath), true, 64, JSON_THROW_ON_ERROR) : require __DIR__ . '/transfer-fixture.php';
    $before = $repo->snapshot('draft'); $published = $repo->snapshot();
    setState('models', ['api_key' => 'must-not-export-this-secret']);
    $r = $transfer->import(['revision' => $before['revision'], 'package' => $pack]);
    $draft = $repo->snapshot('draft'); $map = $r['result']['id_map'];
    verify(count($draft['nodes']) === count($before['nodes']) + count($pack['snapshot']['nodes']), 'Append all package nodes while preserving existing knowledge');
    verify($repo->issues($draft['nodes']) === [], 'Every NBN pathway and reference has a valid target');
    verify($repo->snapshot() === $published, 'Import does not publish or modify published snapshots');
    verify(state('summary_pending') === null, 'Import does not queue a paid summary build');
    verify(count($map) === count(array_unique($map)), 'Every imported ID is remapped uniquely');
    $q = db()->query('SELECT COUNT(*) FROM sources'); verify((int)$q->fetchColumn() === 5, 'All five extracted source bodies are imported');
    $export = $transfer->export();
    verify(!str_contains(json_encode($export), 'must-not-export-this-secret'), 'Exports exclude stored model credentials');
    verify(count($export['sources']) === 5, 'Portable export includes referenced source bodies');
    $provenanceMatches = true;
    foreach ($pack['snapshot']['nodes'] as $n) {
        $after = $repo->resolve($draft, $map[$n['id']]);
        if ($n['source']) $provenanceMatches = $provenanceMatches && $after['source']['pdf_sha256'] === $n['source']['pdf_sha256'];
    }
    verify($provenanceMatches, 'All node PDF citations and hashes survive import');
    $fingerprint = Summaries::fingerprint($draft['nodes']);
    rejected(fn() => $transfer->import(['revision' => -1, 'package' => $pack]), 'revision_conflict');
    $bad = $pack; $bad['snapshot']['nodes'][0]['parent_id'] = $bad['snapshot']['nodes'][0]['id'];
    rejected(fn() => $transfer->import(['revision' => $draft['revision'], 'package' => $bad]), 'tree_cycle');
    $bad = $pack; $bad['snapshot']['nodes'][5]['references'][] = ['target_id' => 'outside-package'];
    rejected(fn() => $transfer->import(['revision' => $draft['revision'], 'package' => $bad]), 'invalid_package');
    $bad = $pack; $bad['snapshot']['nodes'][0]['content'] = ['not-a-string'];
    rejected(fn() => $transfer->import(['revision' => $draft['revision'], 'package' => $bad]), 'invalid_package');
    verify(Summaries::fingerprint($repo->snapshot('draft')['nodes']) === $fingerprint, 'Failed package imports leave the draft intact');
    // A second import collides with both roots; ID/path rewrites must still be internal.
    $r2 = $transfer->import(['revision' => $draft['revision'], 'package' => $pack]);
    $d2 = $repo->snapshot('draft');
    $root2 = $repo->resolve($d2, $r2['result']['id_map']['nbn_troubleshooting']);
    verify($root2['slug'] === 'nbn-troubleshooting-2', 'Root name collisions append with a unique path');
    $step = $repo->resolve($d2, $r2['result']['id_map']['nbn_fttc_outage_test_4']);
    foreach ($step['references'] as $ref) {
        $target = $repo->resolve($d2, $ref['target_id']);
        verify(str_contains($step['content'], '](' . $repo->path($target, $d2['nodes']) . ')'), 'Explicit reference body and remapped target agree');
    }
    verify((int)db()->query('SELECT COUNT(*) FROM sources')->fetchColumn() === 5, 'Repeated imports deduplicate source bodies by content hash');
    $legacy = ['format' => 'arbor.v1', 'snapshot' => $published];
    $transfer->import(['revision' => $d2['revision'], 'package' => $legacy]);
    verify($repo->issues($repo->snapshot('draft')['nodes']) === [], 'Legacy snapshot-only arbor.v1 export still imports');
    // Round-trip the complete export into an empty draft in the disposable database.
    $d = $repo->snapshot('draft'); $d['nodes'] = []; setState('draft', $d);
    $round = $transfer->import(['revision' => $d['revision'], 'package' => $export]);
    $roundtrip = $repo->snapshot('draft');
    verify(count($roundtrip['nodes']) === count($export['snapshot']['nodes']), 'Export/import round trip preserves node count');
    verify($repo->issues($roundtrip['nodes']) === [], 'Round-trip links all resolve');
    $id = $round['result']['id_map'][$map['nbn_fttc_outage_test_4']];
    $read = $tools->call('read', ['id' => $id, 'version' => 'draft'])['data']['items'][0];
    verify(str_contains($read['content'], 'Wait') && str_contains($read['content'], '3-4 minutes'), 'FTTC DPU reset retains its source-specific wait time');
    $id = $round['result']['id_map'][$map['nbn_ref_fttn_mlt']];
    $read = $tools->call('read', ['id' => $id, 'version' => 'draft'])['data']['items'][0];
    verify(str_contains($read['content'], 'only available for FTTN') && str_contains($read['content'], '≥85V'), 'FTTN-only MLT restrictions and thresholds survive');
    verify($read['source']['revision'] === '14', 'Consumer API exposes the source PDF revision');
    $v = $repo->publish($roundtrip['revision'], 'NBN transfer test')['result']['version'];
    verify($tools->call('read', ['id' => $id, 'version' => $v])['version'] === $v, 'Imported knowledge publishes and serves version-pinned reads');
    echo "\n$count transfer checks passed. No model calls.\n";
} finally {
    foreach (glob($dir . '/*') as $f) unlink($f); rmdir($dir);
}
