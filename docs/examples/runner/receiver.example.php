<?php
// Minimal HMAC-verifying receiver. Deploy on YOUR infra. It bridges the package's
// signed task POST to a GitHub repository_dispatch that runs your CI agent.
// NOT part of the package runtime — runs with YOUR GitHub token, never in production app context.

$secret = getenv('FEEDBACK_RUNNER_SECRET');
$ghToken = getenv('GITHUB_TOKEN');           // scoped, repo dispatch only
$repo = getenv('GITHUB_REPO');               // "owner/name"

$raw = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_FEEDBACK_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_FEEDBACK_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $ts.'.'.$raw, $secret);
if (! hash_equals($expected, $sig) || abs(time() - (int) $ts) > 300) {
    http_response_code(403); exit('bad signature');
}

$envelope = json_decode($raw, true);
$ch = curl_init("https://api.github.com/repos/{$repo}/dispatches");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$ghToken}", 'Accept: application/vnd.github+json', 'User-Agent: feedback-runner'],
    CURLOPT_POSTFIELDS => json_encode(['event_type' => 'feedback_task', 'client_payload' => $envelope]),
    CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch);
http_response_code(202); echo 'queued';
