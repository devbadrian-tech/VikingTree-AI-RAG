<?php
require __DIR__ . '/../src/bootstrap.php';
use Arbor\{Summaries, Repository};
use function Arbor\{db, state};
new Repository();
$path = db()->query('PRAGMA database_list')->fetch()['file'];
$lock = fopen($path . '.summary.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
$daemon = in_array('--daemon', $argv, true);
$idle = 0;
do {
    try { Summaries::tick(); } catch (Throwable $e) { error_log('Automatic summary worker failed: ' . get_class($e)); }
    $idle = state('summary_pending') && Summaries::config()['enabled'] && Arbor\Models::config()['configured'] ? 0 : $idle + 1;
    if (!$daemon && $idle >= 30) break;
    sleep(2);
} while (true);
