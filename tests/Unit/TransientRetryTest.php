<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;

function reqException(int $status): RequestException {
    return new RequestException(new Response(new \GuzzleHttp\Psr7\Response($status)));
}

it('retries connection errors, 429 and 5xx but not permanent 4xx', function () {
    $when = TransientRetry::when();
    expect($when(new ConnectionException('x')))->toBeTrue()
        ->and($when(reqException(429)))->toBeTrue()
        ->and($when(reqException(503)))->toBeTrue()
        ->and($when(reqException(422)))->toBeFalse()
        ->and($when(reqException(404)))->toBeFalse()
        ->and($when(reqException(401)))->toBeFalse();
});
