<?php
// Local test double for provider wire contracts. Never used by the application.
$body = json_decode(file_get_contents('php://input'), true);
header('Content-Type: application/json');
if (!is_array($body)) { http_response_code(400); echo '{}'; exit; }
$uri = $_SERVER['REQUEST_URI'];
$messages = $body['messages'] ?? []; $last = end($messages); $input = json_decode($last['content'] ?? '{}', true) ?: [];
$answer = ['ok' => true];
if (isset($input['title'])) $answer = ['summary' => 'Fixture abstract for ' . $input['title'], 'overview' => 'Fixture overview based on supplied content.'];
if (isset($input['navigation_review'])) $answer = ['issues' => []];
if ($uri === '/v1/chat/completions') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fixture-key' || !isset($body['messages'][0]['content'], $body['max_completion_tokens'])) { http_response_code(400); echo '{}'; exit; }
    echo json_encode(['choices' => [['message' => ['content' => json_encode($answer)]]]]);
} elseif ($uri === '/v1/messages') {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'fixture-key' || !isset($body['system'], $body['max_tokens'])) { http_response_code(400); echo '{}'; exit; }
    echo json_encode(['content' => [['type' => 'text', 'text' => json_encode($answer)]]]);
} elseif ($uri === '/v1/systemone') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fixture-jev' || ($body['model'] ?? '') !== 'jev-latest') { http_response_code(400); echo '{}'; exit; }
    $answers = []; foreach ($body['questions'] ?? [] as $id => $q) $answers[$id] = ['noul' => 0.86];
    echo json_encode(['answers' => $answers]);
} else { http_response_code(503); echo json_encode(['error' => 'Fixture provider unavailable']); }
