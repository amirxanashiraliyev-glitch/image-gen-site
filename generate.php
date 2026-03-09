<?php
// ─── PROXY: hides the real image generation API URL ───────────────────────
session_start();

// Only authenticated users can generate
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$prompt = trim($_GET['prompt'] ?? '');
if ($prompt === '') {
    http_response_code(400);
    exit('Prompt required');
}

// Sanitize prompt — strip any control chars
$prompt = preg_replace('/[\x00-\x1F\x7F]/', '', $prompt);

$apiUrl = 'https://img-gen.wwiw.uz/?prompt=' . urlencode($prompt);

// Fetch image from real API using cURL
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AIRasmProxy/1.0)',
]);

$imageData = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200 || !$imageData) {
    http_response_code(502);
    exit('Image generation failed');
}

// Detect content type from response
// Default to jpeg; wwiw.uz returns jpeg images
$contentType = 'image/jpeg';

// Stream image to browser — real URL never exposed to client
header('Content-Type: ' . $contentType);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $imageData;
