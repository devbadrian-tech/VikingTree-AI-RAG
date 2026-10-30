<?php
// Synthetic portable fixture for CI, where the private PDF-derived package is absent.
$fixtureRepo = new Arbor\Repository();
$fixtureNodes = [$fixtureRepo->makeNode(['id'=>'nbn_troubleshooting','slug'=>'nbn-troubleshooting','title'=>'Synthetic transfer fixture','kind'=>'collection'])];
$fixtureSources = [];
foreach (['fibre','fttn','fttc','hfc','wireless'] as $i => $tech) {
    $body = $tech === 'fttc' ? 'Synthetic DPU Port fixture. Wait 3-4 minutes.' : ($tech === 'fttn' ? 'Synthetic MLT fixture only available for FTTN; threshold ≥85V.' : 'Synthetic source '.$tech);
    $fixtureSources[] = ['id'=>'src_'.$tech,'name'=>$tech.'.txt','original'=>$body];
    $nodeId = match ($tech) { 'fttc'=>'nbn_fttc_outage_test_4', 'fttn'=>'nbn_ref_fttn_mlt', default=>'fixture_'.$tech };
    $fixtureNodes[] = $fixtureRepo->makeNode(['id'=>$nodeId,'parent_id'=>'nbn_troubleshooting','slug'=>$tech,'title'=>'Synthetic '.$tech,'kind'=>'section','content'=>$body,'source'=>['id'=>'src_'.$tech,'name'=>$tech.'.txt','revision'=>$tech==='fttc'?'8':'14','pdf_sha256'=>'synthetic-fixture-hash']]);
}
$fixtureNodes[3]['content'] .= ' [Next](viking://nbn-troubleshooting/fttn)';
$fixtureNodes[] = $fixtureRepo->makeNode(['id'=>'nbn_ref_fttc_outage','parent_id'=>'nbn_troubleshooting','slug'=>'reference','title'=>'Synthetic linked reference','content'=>'[Next](viking://nbn-troubleshooting/fttc)','source'=>['id'=>'src_fttc','name'=>'fttc.txt','revision'=>'8','pdf_sha256'=>'synthetic-fixture-hash']]);
foreach ($fixtureNodes as &$n) $n['references'] = $fixtureRepo->extractReferences($n,$fixtureNodes);
unset($n);
return ['format'=>'arbor.v1','snapshot'=>['nodes'=>$fixtureNodes],'sources'=>$fixtureSources];
