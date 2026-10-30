<?php
declare(strict_types=1);
namespace Arbor;

final class Summaries
{
    public static function config(): array
    {
        return array_replace(['enabled' => filter_var(getenv('ARBOR_AUTO_SUMMARIZE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            'debounce' => max(2, min(300, (int)(getenv('ARBOR_SUMMARY_DEBOUNCE') ?: 15))),
            'tokens' => array_map('intval', explode(',', getenv('ARBOR_SUMMARY_TOKENS') ?: '256,512,768,1024'))], state('summary_settings', []));
    }
    public static function save(array $input): array
    {
        $c = self::config();
        foreach (['enabled','debounce','tokens'] as $key) if (array_key_exists($key, $input)) $c[$key] = $input[$key];
        if (!is_bool($c['enabled']) || !is_int($c['debounce']) || $c['debounce'] < 2 || $c['debounce'] > 300 || !is_array($c['tokens']) || count($c['tokens']) !== 4 || !array_is_list($c['tokens'])) throw new ApiError('invalid_budget', 'Choose four layer budgets and a delay of 2–300 seconds.');
        $last = 0;
        foreach ($c['tokens'] as $t) { if (!is_int($t) || $t < 128 || $t > 4096 || $t < $last) throw new ApiError('invalid_budget', 'Layer budgets must increase or stay equal, between 128 and 4096 tokens.'); $last = $t; }
        setState('summary_settings', $c);
        if ($c['enabled']) { self::dirty(); self::wake(); }
        return self::status();
    }
    public static function budget(int $depth): array
    {
        $values = self::config()['tokens'];
        $tokens = max(128, min(4096, (int)($values[min(3, max(0, $depth - 1))] ?? 1024)));
        return ['layer' => $depth, 'output_tokens' => $tokens, 'summary_characters' => min(1200, (int)($tokens * 0.625)), 'overview_characters' => min(4000, (int)($tokens * 1.5))];
    }
    public static function fingerprint(array $nodes): string
    {
        return hash('sha256', encode(array_map(fn($n) => array_intersect_key($n, array_flip(['id','parent_id','title','slug','kind','content','summary','overview','references','sort_order'])), $nodes)));
    }
    // Called inside the same transaction as an edit, so no content change can lose its pending marker.
    public static function dirty(): void { setState('summary_pending', ['nonce' => id('change'), 'due' => time() + self::config()['debounce']]); }
    public static function wake(): void
    {
        if (!self::config()['enabled'] || getenv('ARBOR_EXTERNAL_WORKER') || !function_exists('exec')) return;
        exec(escapeshellarg(PHP_BINDIR . '/php') . ' ' . escapeshellarg(ROOT . '/bin/auto.php') . ' > /dev/null 2>&1 &');
    }
    public static function status(): array
    {
        return ['settings' => self::config(), 'pending' => state('summary_pending'), 'last' => state('summary_last'), 'configured' => Models::config()['configured'],
            'jobs' => db()->query('SELECT id,status,progress,message,created_at,updated_at FROM jobs ORDER BY rowid DESC LIMIT 20')->fetchAll()];
    }
    // The worker holds an OS lock across this operation. A revision conflict retries the new draft; provider failures do not spin.
    public static function tick(?Importer $importer = null): void
    {
        $pending = state('summary_pending');
        if (!$pending || $pending['due'] > time() || !self::config()['enabled'] || !Models::config()['configured']) return;
        $repo = new Repository(); $importer ??= new Importer($repo);
        $job = $importer->startBuild(['revision' => $repo->snapshot('draft')['revision'], 'organize' => false], false);
        setState('summary_last', ['id' => $job['id'], 'status' => 'running']);
        $importer->work($job['id']); $result = $importer->job($job['id']);
        $db = db(); $db->exec('BEGIN IMMEDIATE');
        try {
            if ((state('summary_pending')['nonce'] ?? null) === $pending['nonce']) {
                if (($result['result']['code'] ?? '') === 'revision_conflict') self::dirty();
                else setState('summary_pending', null);
            }
            setState('summary_last', $result); $db->exec('COMMIT');
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }
}
