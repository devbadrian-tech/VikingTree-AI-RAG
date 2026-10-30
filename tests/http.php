<?php
declare(strict_types=1);
// Never inherit live provider configuration or launch automatic workers in tests.
foreach (['OPENAI_API_KEY','OPENAI_BASE_URL','ARBOR_LLM_PROVIDER','ARBOR_LLM_MODEL','ARBOR_ROUTER_MODEL','AWS_REGION','AWS_BEARER_TOKEN_BEDROCK','AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_SESSION_TOKEN','ARBOR_AUTO_SUMMARIZE','ARBOR_SUMMARY_TOKENS'] as $key) putenv($key . '=');
putenv('ARBOR_EXTERNAL_WORKER=1');
$dir = sys_get_temp_dir() . '/arbor-http-' . bin2hex(random_bytes(5)); mkdir($dir, 0700);
putenv('ARBOR_DB=' . $dir . '/workspace.sqlite'); putenv('ARBOR_API_TOKEN=fixture-local-token');
$port = random_int(18000, 22000); $providerPort = $port + 1; $root = realpath(__DIR__ . '/..');
$processes = [];
function startServer(int $port, string $router, string $docroot, string $log): mixed {
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $router], [['pipe','r'], ['file',$log,'a'], ['file',$log,'a']], $pipes);
    fclose($pipes[0]);
    for ($i=0;$i<50;$i++) { $socket = @fsockopen('127.0.0.1',$port,$errno,$error,.05); if($socket){fclose($socket);return $proc;} usleep(50000); }
    throw new RuntimeException('Could not start test server. ' . file_get_contents($log));
}
$count = 0;
function ok(bool $condition, string $message): void { global $count; if(!$condition)throw new RuntimeException('FAIL: '.$message);$count++;echo "✓ $message\n"; }
function http(string $path, ?array $body = null, array $headers = [], ?string $method = null): array {
    global $port,$dir;
    $c=curl_init('http://127.0.0.1:'.$port.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method??($body===null?'GET':'POST'),CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$headers),CURLOPT_COOKIEJAR=>$dir.'/cookies',CURLOPT_COOKIEFILE=>$dir.'/cookies']);
    if($body!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$error=curl_error($c);unset($c);if($raw===false)throw new RuntimeException($error);return ['status'=>$status,'raw'=>$raw,'json'=>json_decode($raw,true)];
}
try {
    $processes[]=startServer($port,$root.'/public/index.php',$root.'/public',$dir.'/app.log');
    $processes[]=startServer($providerPort,__DIR__.'/provider-fixture.php',__DIR__,$dir.'/provider.log');
    $page=http('/');preg_match('/name="csrf-token" content="([a-f0-9]+)"/',$page['raw'],$m);$csrf=$m[1]??'';
    ok($page['status']===200 && str_contains($page['raw'],'Open your workspace') && $csrf!=='','Configured instances show the token unlock page');
    ok(http('/api/tools/read',['id'=>'demo_step1'])['status']===401,'External API requests require the configured token');
    $auth=['Authorization: Bearer fixture-local-token'];
    $read=http('/api/tools/read',['id'=>'demo_step1'],$auth);
    ok($read['status']===200 && $read['json']['data']['items'][0]['references'][0]['target']['id']==='demo_step2','Real HTTP reads expose actionable references');
    ok(http('/api/nodes',['title'=>'No token'])['status']===401,'Unauthenticated mutations are denied');
    ok(http('/api/login',['token'=>'fixture-local-token'],['X-CSRF-Token: '.$csrf])['status']===200,'Browser session can authenticate through CSRF-protected login');
    ok(http('/api/nodes',['revision'=>1,'title'=>'No CSRF'])['status']===403,'Authenticated browser mutations still require CSRF');
    ok(http('/api/summary-settings',['enabled'=>true])['status']===403,'Automatic summary settings require browser CSRF');
    $summary=http('/api/summary-settings',['enabled'=>false,'debounce'=>15,'tokens'=>[256,512,768,1024]],['X-CSRF-Token: '.$csrf]);
    ok($summary['status']===200 && $summary['json']['settings']['tokens']===[256,512,768,1024],'Summary settings persist validated layer budgets');
    ok(http('/api/summary-settings')['json']['settings']['enabled']===false,'Summary status exposes the saved automatic setting');
    $node=http('/api/nodes',['revision'=>1,'title'=>'HTTP fixture','kind'=>'collection'],['X-CSRF-Token: '.$csrf]);
    ok($node['status']===200 && $node['json']['revision']===2,'Authorized browser mutations persist');
    ok(count(http('/api/tree?version=draft')['json']['nodes'])===15,'HTTP tree reads reflect the saved draft');
    ok(count(http('/api/tree?version=sample_v1')['json']['nodes'])===14,'HTTP published snapshots remain unchanged');
    ok(http('/api/nodes',['revision'=>1,'title'=>'Stale'],['X-CSRF-Token: '.$csrf])['status']===409,'HTTP revision conflicts return 409');
    ok(http('/api/tools/list_collections',[],['Origin: https://untrusted.invalid'])['status']===403,'Cross-origin requests are rejected');
    ok(http('/.env')['status']===404 && http('/data/arbor.sqlite')['status']===404,'Credentials and database files are outside public routing');
    ok(http('/assets/app.css')['status']===200,'Static frontend assets are served');
    $schema=http('/api/openapi.json')['json'];
    ok($schema['openapi']==='3.1.0' && $schema['paths']['/api/tools/read']['post']['operationId']==='read','OpenAPI publishes the actual consumer tool schemas');
    $mcp=http('/mcp',['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list']);
    ok($mcp['status']===200 && count($mcp['json']['result']['tools'])===5,'HTTP MCP exposes the same navigation tools');
    ok(http('/mcp',['jsonrpc'=>'2.0','method'=>'notifications/initialized'])['status']===202,'HTTP MCP notifications return 202 without a body');
    ok(http('/mcp')['status']===405,'Unsupported MCP streaming GET returns 405');
    require $root.'/src/bootstrap.php';
    Arbor\Models::save(['provider'=>'openai','endpoint'=>'http://127.0.0.1:'.$providerPort.'/v1','model'=>'fixture-model','api_key'=>'fixture-key','jev_key'=>'fixture-jev','jev_endpoint'=>'http://127.0.0.1:'.$providerPort.'/v1/systemone']);
    $models=new Arbor\Models();
    ok($models->json('Return JSON.',['test'=>true])['ok']===true,'OpenAI-compatible adapter sends and parses the expected HTTP contract');
    Arbor\Models::save(['provider'=>'anthropic']);
    ok($models->json('Return JSON.',['test'=>true])['ok']===true,'Anthropic adapter sends and parses the expected HTTP contract');
    ok($models->jev('Find a runbook',[['id'=>'n_fixture','title'=>'Runbook']])['n_fixture']===.86,'Jev adapter parses the Noul score contract');
    $job=http('/api/build',['revision'=>2,'id'=>'demo_dropout','organize'=>false],['X-CSRF-Token: '.$csrf]);
    ok($job['status']===202,'AI build starts a real background CLI job');
    for($i=0;$i<60;$i++){usleep(100000);$status=http('/api/jobs/'.$job['json']['id'])['json'];if(in_array($status['status'],['complete','failed']))break;}
    ok($status['status']==='complete','Background worker completes through the real provider adapter');
    $tree=http('/api/tree?version=draft')['json'];
    ok($tree['revision']===3 && $tree['navigation_review']['revision']===3,'Background summaries and navigation review commit together');
    $settings=http('/api/settings');ok(!str_contains($settings['raw'],'fixture-key') && !str_contains($settings['raw'],'fixture-jev'),'HTTP settings never return stored credentials');
    Arbor\Models::save(['endpoint'=>'http://127.0.0.1:'.$providerPort.'/unavailable']);
    $error=http('/api/model-test',[],['X-CSRF-Token: '.$csrf]);
    ok($error['status']===502 && $error['json']['error']['code']==='provider_error','Provider failures remain distinguishable from a knowledge no-answer result');
    // Tree packages use the same auth, CSRF and revision checks as other mutations.
    $packagePath=getenv('ARBOR_TEST_PACKAGE')?:$root.'/exports/nbn-troubleshooting.arbor.json';
    $pack=is_file($packagePath)?json_decode(file_get_contents($packagePath),true):require __DIR__.'/transfer-fixture.php';
    $revision=http('/api/tree?version=draft')['json']['revision'];
    ok(http('/api/import-tree',['revision'=>$revision,'package'=>$pack])['status']===403,'Tree package import requires browser CSRF');
    $import=http('/api/import-tree',['revision'=>$revision,'package'=>$pack],['X-CSRF-Token: '.$csrf]);
    ok($import['status']===200 && $import['json']['result']['node_count']===count($pack['snapshot']['nodes']),'Knowledge JSON package imports through HTTP');
    ok(http('/api/import-tree',['revision'=>$revision,'package'=>$pack],$auth)['status']===409,'Package import rejects stale revisions');
    $idMap=$import['json']['result']['id_map'];
    $sourceRead=http('/api/tools/read',['id'=>$idMap['nbn_ref_fttc_outage'],'version'=>'draft'])['json'];
    ok($sourceRead['data']['items'][0]['source']['revision']==='8','HTTP reads return imported PDF provenance');
    $source=http('/api/sources/'.$sourceRead['data']['items'][0]['source']['id']);
    ok($source['status']===200 && str_contains($source['json']['original'],'DPU Port'),'Imported source text is readable on the destination server');
    $export=http('/api/export?version=draft');
    ok($export['status']===200 && count($export['json']['sources'])===5 && !str_contains($export['raw'],'fixture-key'),'HTTP export includes source bodies and excludes credentials');
    ok(http('/api/tree?version=sample_v1')['json']['nodes'][0]['id']==='demo_troubleshooting','Tree package import preserves the published version');
    echo "\n$count HTTP/provider checks passed. Providers were local fixtures; no live API calls.\n";
} finally {
    foreach($processes as $proc){proc_terminate($proc);proc_close($proc);}
    foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);
}
