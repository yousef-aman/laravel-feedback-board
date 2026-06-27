<?php

use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;

$signer = fn () => new class { use SignsPayloads; public function s($t,$b,$k){return $this->sign($t,$b,$k);} public function v($e,$a){return $this->verifySignature($e,$a);} };

it('produces a bare hex hmac identical to the ingest formula', function () use ($signer) {
    $ts = '1700000000'; $body = '{"a":1}'; $secret = 'shhh';
    $expected = hash_hmac('sha256', $ts.'.'.$body, $secret);
    expect($signer()->s($ts, $body, $secret))->toBe($expected)
        ->and($signer()->s($ts, $body, $secret))->not->toStartWith('sha256=');
});

it('verifies with constant-time comparison', function () use ($signer) {
    $sig = $signer()->s('1', 'x', 'k');
    expect($signer()->v($sig, $sig))->toBeTrue()
        ->and($signer()->v($sig, 'deadbeef'))->toBeFalse();
});
