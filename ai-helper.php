<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '[]', true);

// Load API key from environment or local config file (not committed)
$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) {
  $cfgPath = __DIR__ . '/ai-config.php';
  if (file_exists($cfgPath)) {
    $cfg = include $cfgPath;
    if (is_array($cfg) && isset($cfg['GEMINI_API_KEY'])) {
      $apiKey = $cfg['GEMINI_API_KEY'];
    }
  }
}

if (!$apiKey) {
  http_response_code(500);
  echo json_encode(['error' => 'Server not configured: missing API key']);
  exit;
}

// Build payload in two compatible ways:
// 1) If frontend sent { text }, create our own prompt
// 2) If frontend sent a full Gemini payload, forward it as-is
if (isset($input['text'])) {
  $userText = trim($input['text']);
  if ($userText === '' || mb_strlen($userText) > 800) {
    http_response_code(400);
    echo json_encode(['error' => 'Provide 1–800 characters of text']);
    exit;
  }

  $systemPrompt = "You are a friendly and professional assistant. Your task is to help a visitor write a contact message to Bui Tan Thanh, a talented student specializing in English and front-end development. Take the user's keywords or draft and expand it into a polished, concise, and friendly message. Make it sound natural, not overly corporate. Start the message with 'Hi Thanh,'.";

  $payload = [
    'contents' => [[ 'parts' => [[ 'text' => 'Here are my notes, please help me write a full message: "' . $userText . '"' ]] ]],
    'systemInstruction' => [ 'parts' => [[ 'text' => $systemPrompt ]] ],
  ];
} else if (isset($input['contents'])) {
  // Basic validation
  $payload = [
    'contents' => $input['contents'],
  ];
  if (isset($input['systemInstruction'])) {
    $payload['systemInstruction'] = $input['systemInstruction'];
  }
} else {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid request body']);
  exit;
}

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-05-20:generateContent?key=' . rawurlencode($apiKey);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_TIMEOUT => 20,
]);
$result = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($result === false) {
  http_response_code(502);
  echo json_encode(['error' => 'Upstream request failed', 'details' => $curlErr]);
  exit;
}

$data = json_decode($result, true);
if ($status >= 400) {
  http_response_code($status ?: 502);
  echo json_encode(['error' => 'Gemini error', 'details' => $data]);
  exit;
}

$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
echo json_encode(['text' => $text]);
