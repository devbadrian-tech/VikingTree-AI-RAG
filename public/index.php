<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && str_starts_with($uri, '/assets/')) {
    $file = realpath(__DIR__ . $uri);
    if ($file && str_starts_with($file, realpath(__DIR__ . '/assets') . DIRECTORY_SEPARATOR) && is_file($file)) return false;
}
require __DIR__ . '/../src/bootstrap.php';
use Arbor\{ApiError, Repository, Tools, Models, Importer, Mcp};
use function Arbor\{encode, state};

header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Cache-Control: no-store');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start(); $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); $csrf = $_SESSION['csrf'];
$configuredToken = getenv('ARBOR_API_TOKEN') ?: ''; $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$bearer = $configuredToken !== '' && str_starts_with($auth, 'Bearer ') && hash_equals($configuredToken, substr($auth, 7));
$sessionAuth = !$configuredToken || ($_SESSION['authenticated'] ?? false);
$browserCsrf = $sessionAuth && hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$json = function (mixed $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo encode($data); exit; };
try {
    $host = $_SERVER['HTTP_HOST'] ?? ''; $hostname = parse_url('http://' . $host, PHP_URL_HOST);
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$configuredToken && (!$local || !in_array($hostname, ['127.0.0.1', 'localhost', '[::1]'], true))) throw new ApiError('local_only', 'Configure ARBOR_API_TOKEN before exposing this local application.', 403);
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $origin = parse_url($_SERVER['HTTP_ORIGIN']);
        $requestOrigin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $host;
        if ($_SERVER['HTTP_ORIGIN'] !== $requestOrigin) throw new ApiError('origin_rejected', 'Cross-origin requests are not allowed.', 403);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4000000) throw new ApiError('request_limit', 'Request exceeds 4 MB.', 413);
    $raw = file_get_contents('php://input'); $body = [];
    if ($raw !== '') { try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new ApiError('invalid_json', 'Request body must be valid JSON.'); } if (!is_array($body)) throw new ApiError('invalid_json', 'Request body must be a JSON object.'); }
    if ($uri === '/api/login' && $method === 'POST') {
        if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') || !$configuredToken || !hash_equals($configuredToken, (string)($body['token'] ?? ''))) throw new ApiError('unauthorized', 'Invalid access token.', 401);
        session_regenerate_id(true); $_SESSION['authenticated'] = true; $json(['ok' => true]);
    }
    if (str_starts_with($uri, '/api/') || $uri === '/mcp') {
        if (!$bearer && !$sessionAuth) throw new ApiError('unauthorized', 'A valid Bearer token is required.', 401);
        $readOnly = str_starts_with($uri, '/api/tools/') || $uri === '/mcp';
        if ($method !== 'GET' && !$readOnly && !$bearer && !$browserCsrf) throw new ApiError('csrf_rejected', 'Refresh the page before making changes.', 403);
        session_write_close();
        $repo = new Repository(); $tools = new Tools($repo); $importer = new Importer($repo);
        if ($uri === '/api/summary-settings' && $method === 'GET') $json(Arbor\Summaries::status());
        if ($uri === '/api/summary-settings' && $method === 'POST') $json(Arbor\Summaries::save($body));
        if ($uri === '/api/tree' && $method === 'GET') {
            $s = $repo->snapshot($_GET['version'] ?? 'draft');
            // Content, hierarchy or summary differences from the latest published version (map positions do not count).
            $unpublished = $s['version'] === 'draft' ? Arbor\Summaries::fingerprint($s['nodes']) !== Arbor\Summaries::fingerprint($repo->snapshot(state('published'))['nodes']) : null;
            $json($s + ['versions' => $repo->versions(), 'published_version' => state('published'), 'unpublished_changes' => $unpublished, 'issues' => $repo->issues($s['nodes']), 'navigation_review' => $s['version'] === 'draft' ? state('navigation_review') : null]);
        }
        if ($uri === '/api/nodes' && $method === 'POST') $json($repo->saveNode($body));
        if ($uri === '/api/nodes/reorder' && $method === 'POST') $json($repo->reorder($body));
        if ($uri === '/api/references/preview' && $method === 'POST') $json($repo->previewReferences($body));
        if (preg_match('#^/api/nodes/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'DELETE') $json($repo->deleteNode($m[1], (int)($body['revision'] ?? -1)));
        if ($uri === '/api/publish' && $method === 'POST') $json($repo->publish((int)($body['revision'] ?? -1), substr((string)($body['label'] ?? ''), 0, 200)));
        if ($uri === '/api/import-tree' && $method === 'POST') $json((new Arbor\Transfer($repo))->import($body));
        if ($uri === '/api/import' && $method === 'POST') $json($importer->import($body));
        if ($uri === '/api/build' && $method === 'POST') $json($importer->startBuild($body), 202);
        if (preg_match('#^/api/jobs/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'GET') $json($importer->job($m[1]));
        if ($uri === '/api/settings' && $method === 'GET') $json(Models::config(true));
        if ($uri === '/api/settings' && $method === 'POST') $json(Models::save($body));
        if ($uri === '/api/model-test' && $method === 'POST') {
            $models = new Models(); $config = Models::config();
            $result = $models->json('Return {"ok":true}.', ['test' => 'connection'], 100);
            $router = $config['router_model'] !== $config['model'] ? $models->json('Return {"ok":true}.', ['test' => 'connection'], 100, 'route') : $result;
            $json(['ok' => ($result['ok'] ?? false) === true && ($router['ok'] ?? false) === true, 'model' => $config['model'], 'router_model' => $config['router_model']]);
        }
        if ($uri === '/api/tool-definitions' && $method === 'GET') $json(['tools' => Tools::definitions()]);
        if ($uri === '/api/openapi.json' && $method === 'GET') {
            $paths = [];
            foreach (Tools::definitions() as $definition) $paths['/api/tools/' . $definition['name']] = ['post' => ['operationId' => $definition['name'], 'description' => $definition['description'], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $definition['inputSchema']]]], 'responses' => ['200' => ['description' => 'Version-pinned result with trace.', 'content' => ['application/json' => ['schema' => $definition['outputSchema']]]], 'default' => ['description' => 'Error envelope with error.code, error.message and error.details.']]]];
            $json(['openapi' => '3.1.0', 'info' => ['title' => 'Arbor consumer API', 'version' => '0.1.0'], 'paths' => $paths, 'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]], 'security' => $configuredToken ? [['bearerAuth' => []]] : []]);
        }
        if (preg_match('#^/api/tools/([a-z_]+)$#', $uri, $m) && $method === 'POST') $json($tools->call($m[1], $body));
        if (preg_match('#^/api/sources/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'GET') { $q = Arbor\db()->prepare('SELECT id,name,original,hash,created_at FROM sources WHERE id=?'); $q->execute([$m[1]]); $source = $q->fetch(); if (!$source) throw new ApiError('source_not_found', 'Source not found.', 404); $json($source); }
        if ($uri === '/api/export' && $method === 'GET') { header('Content-Disposition: attachment; filename="arbor-knowledge.json"'); $json((new Arbor\Transfer($repo))->export($_GET['version'] ?? 'draft')); }
        if ($uri === '/mcp') {
            if ($method !== 'POST') { header('Allow: POST'); throw new ApiError('method_not_allowed', 'This stateless MCP endpoint accepts JSON-RPC POST requests.', 405); }
            $result = (new Mcp($tools, $repo))->handle($body);
            if ($result === null) { http_response_code(202); exit; } $json($result);
        }
        throw new ApiError('not_found', 'Endpoint not found.', 404);
    }
    if ($method !== 'GET' || !in_array($uri, ['/', '/lab', '/settings'])) throw new ApiError('not_found', 'Page not found.', 404);
    $page = $uri === '/lab' ? 'lab' : ($uri === '/settings' ? 'settings' : 'workspace');
    session_write_close();
    require __DIR__ . '/../views/app.php';
} catch (ApiError $e) { $json(['status' => 'error', 'error' => ['code' => $e->kind, 'message' => $e->getMessage(), 'details' => $e->details]], $e->status); }
catch (\Throwable $e) { error_log($e->__toString()); $json(['status' => 'error', 'error' => ['code' => 'internal_error', 'message' => 'An unexpected error occurred. Check the local server log.']], 500); }
