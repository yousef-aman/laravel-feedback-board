<?php

declare(strict_types=1);

// Minimal HMAC-verifying receiver. Deploy it on YOUR infrastructure: it turns the package's signed
// task POST into a GitHub repository_dispatch that runs your agent workflow. It is NOT part of the
// package runtime, and it runs with YOUR GitHub token (scoped to repository dispatch), never in the app.

$secret = (string) getenv('FEEDBACK_RUNNER_SECRET');
$ghToken = (string) getenv('GITHUB_TOKEN');
$repo = (string) getenv('GITHUB_REPO'); // "owner/name"
$api = rtrim((string) (getenv('GITHUB_API_URL') ?: 'https://api.github.com'), '/'); // GitHub Enterprise: https://HOST/api/v3

// Fail closed: with an empty secret, anyone could compute the expected signature.
if ($secret === '' || $ghToken === '' || $repo === '') {
    http_response_code(500);
    exit('receiver misconfigured');
}

$raw = (string) file_get_contents('php://input');
$ts = (string) ($_SERVER['HTTP_X_FEEDBACK_TIMESTAMP'] ?? '');
$sig = (string) ($_SERVER['HTTP_X_FEEDBACK_SIGNATURE'] ?? '');

if (! ctype_digit($ts) || abs(time() - (int) $ts) > 300
    || ! hash_equals(hash_hmac('sha256', $ts.'.'.$raw, $secret), $sig)) {
    http_response_code(403);
    exit('bad signature');
}

$ch = curl_init("{$api}/repos/{$repo}/dispatches");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$ghToken}",
        'Accept: application/vnd.github+json',
        'Content-Type: application/json',
        'User-Agent: feedback-runner',
    ],
    CURLOPT_POSTFIELDS => json_encode(
        ['event_type' => 'feedback_task', 'client_payload' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR)],
        JSON_THROW_ON_ERROR,
    ),
    CURLOPT_RETURNTRANSFER => true,
    // Below the package's runner.timeout (8 s), so the package never gives up first. If GitHub
    // accepts the dispatch but answers after 6 s, this receiver answers 502 and the package's retry
    // can start the agent a second time for the same task.
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 6,
]);
$sent = curl_exec($ch) !== false;
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

if ($sent && $status === 204) {
    http_response_code(202);
    exit('queued');
}

// GitHub refused the dispatch (bad token, wrong repo, payload too large): a retry cannot help, so
// answer 422, which the package records as a failed task. A network or GitHub server error is 502,
// which the package retries and then records as failed. Either way the task never stays "dispatched".
http_response_code($sent && $status >= 400 && $status < 500 ? 422 : 502);
exit('dispatch failed');
