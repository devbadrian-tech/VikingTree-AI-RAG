<?php
declare(strict_types=1);
namespace Arbor;

final class Mcp
{
    public function __construct(private Tools $tools, private Repository $repo) {}

    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        if (($message['jsonrpc'] ?? '') !== '2.0' || !is_string($message['method'] ?? null)) return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Invalid JSON-RPC request.']];
        if (!array_key_exists('id', $message)) return null;
        try {
            $params = $message['params'] ?? [];
            if (!is_array($params)) throw new ApiError('invalid_params', 'params must be an object.');
            $result = match($message['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object)[], 'resources' => (object)[]], 'serverInfo' => ['name' => 'arbor', 'version' => '0.1.0'], 'instructions' => 'Browse menus and read exact instructions, or call navigate with an issue description to get only the instructions to follow. The caller chooses next steps. Pin the returned version on subsequent calls.'],
                'ping' => (object)[],
                'tools/list' => ['tools' => Tools::definitions()],
                'tools/call' => $this->call($params),
                'resources/list' => ['resources' => [['uri' => 'arbor://guide', 'name' => 'Arbor navigation guide', 'mimeType' => 'text/plain', 'description' => 'How to navigate the knowledge tree.']]],
                'resources/read' => ($params['uri'] ?? '') === 'arbor://guide' ? ['contents' => [['uri' => 'arbor://guide', 'mimeType' => 'text/plain', 'text' => 'Call list_collections, then browse using browse_args. Read using read_args. Follow references[].target.read_args to read exact next steps. The caller interprets conditions. Preserve version between calls. Search supports lexical, llm and jev_llm. For an issue description, call navigate: it returns only the matching instruction nodes (runbooks start at step 1) with their viking:// pathway and next[].read_args. Every response contains actual operation traces.']]] : throw new ApiError('resource_not_found', 'Unknown resource.', 404),
                default => throw new ApiError('method_not_found', 'Method not found.', 404),
            };
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (ApiError $e) { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->kind === 'method_not_found' ? -32601 : -32602, 'message' => $e->getMessage()]]; }
    }

    private function call(array $params): array
    {
        try {
            if (!is_string($params['name'] ?? null) || !is_array($params['arguments'] ?? [])) throw new ApiError('invalid_argument', 'Tool name and arguments are required.');
            $data = $this->tools->call($params['name'], $params['arguments'] ?? []);
            return ['content' => [['type' => 'text', 'text' => encode($data)]], 'structuredContent' => $data, 'isError' => false];
        } catch (ApiError $e) { return ['content' => [['type' => 'text', 'text' => encode(['status' => 'error', 'error' => ['code' => $e->kind, 'message' => $e->getMessage(), 'details' => $e->details]])]], 'isError' => true]; }
    }
}
