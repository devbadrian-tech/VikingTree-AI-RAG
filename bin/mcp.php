<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');
require __DIR__ . '/../src/bootstrap.php';
$repo = new Arbor\Repository(); $mcp = new Arbor\Mcp(new Arbor\Tools($repo), $repo);
while (($line = fgets(STDIN)) !== false) {
    try { $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR); $result = is_array($message) ? $mcp->handle($message) : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request.']]; }
    catch (JsonException) { $result = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']]; }
    catch (Throwable) { $result = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error.']]; }
    if ($result !== null) { fwrite(STDOUT, Arbor\encode($result) . "\n"); fflush(STDOUT); }
}
