<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\{Auth, Log};
use YousefAman\FeedbackBoard\FeedbackBoard as Board;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\Guards\TestClient;

beforeEach(function () {
    $this->staff = FilamentTestUser::create(['name' => 'Staff', 'email' => 's@x.com']);
    $this->client = TestClient::create(['name' => 'Client', 'email' => 'c@x.com']);
});

// Implicit mode (auth.guards null): today's behaviour.

it('reads the app default guard outside a panel', function () {
    $this->signIn($this->client, 'client');
    expect(FeedbackBoard::user())->toBeNull();

    $this->signIn($this->staff, 'web');
    expect(FeedbackBoard::user())->toBe($this->staff);
});

it('reads the panel guard inside a panel', function () {
    $this->signIn($this->staff, 'web')->signIn($this->client, 'client');

    $this->inPanel('client');
    expect(FeedbackBoard::user())->toBe($this->client);

    $this->inPanel('admin');
    expect(FeedbackBoard::user())->toBe($this->staff);
});

it('treats null and an empty list alike, without a warning', function (mixed $guards) {
    Log::spy();
    config()->set('feedback-board.auth.guards', $guards);
    $this->signIn($this->staff, 'web');

    expect(FeedbackBoard::user())->toBe($this->staff)
        ->and(app(Board::class)->candidateGuards())->toBe(['web']);
    Log::shouldNotHaveReceived('warning');
})->with(['null' => [null], 'an empty list' => [[]]]);

// Explicit mode.

it('finds a client on a listed guard while the default guard is web', function () {
    $this->staffAndClients()->signIn($this->client, 'client');

    expect(FeedbackBoard::user())->toBe($this->client)
        ->and(app(Board::class)->resolveUser())->toBe(['guard' => 'client', 'user' => $this->client]);
});

it('tries the guard in use first, then the list in order, without duplicates', function () {
    config()->set('auth.guards.partner', ['driver' => 'session', 'provider' => 'users']);

    config()->set('feedback-board.auth.guards', ['partner', 'client', 'partner', 'web']);
    expect(app(Board::class)->candidateGuards())->toBe(['web', 'partner', 'client']);

    config()->set('feedback-board.auth.guards', ['partner', 'client', 'partner']);
    expect(app(Board::class)->candidateGuards())->toBe(['partner', 'client']);
});

it('follows the guard in use as it changes, so nothing is cached', function () {
    $this->staffAndClients()->signIn($this->staff, 'web')->signIn($this->client, 'client');
    expect(FeedbackBoard::user())->toBe($this->staff);

    Auth::shouldUse('client');
    expect(FeedbackBoard::user())->toBe($this->client);
});

it('ignores a session on a guard that is not listed', function () {
    config()->set('feedback-board.auth.guards', ['client']);
    $this->signIn($this->staff, 'web');

    expect(FeedbackBoard::user())->toBeNull();
});

it('skips entries that are not strings or name no guard, with one warning', function () {
    Log::spy();
    config()->set('feedback-board.auth.guards', ['client', 'nope', 7, null]);

    expect(app(Board::class)->candidateGuards())->toBe(['client'])
        ->and(app(Board::class)->candidateGuards())->toBe(['client']);
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'feedback-board.auth.guards')
            && $context['skipped'] === ['nope', 'int', 'null']);
});

it('recognises nobody when no listed guard exists', function () {
    config()->set('feedback-board.auth.guards', ['nope']);
    $this->signIn($this->staff, 'web');

    expect(FeedbackBoard::user())->toBeNull();
});

it('recognises nobody, with one warning, when guards is not a list', function (mixed $guards) {
    Log::spy();
    config()->set('feedback-board.auth.guards', $guards);
    $this->signIn($this->staff, 'web');

    expect(FeedbackBoard::user())->toBeNull()
        ->and(FeedbackBoard::user())->toBeNull();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message) => str_contains($message, 'feedback-board.auth.guards must be null or an array'));
})->with(['a string' => ['web'], 'an integer' => [1], 'true' => [true]]);

it('recognises nobody inside a panel whose guard is not listed, warns once, and never falls back', function () {
    Log::spy();
    config()->set('feedback-board.auth.guards', ['web']);
    $this->signIn($this->staff, 'web')->signIn($this->client, 'client')->inPanel('client');

    expect(FeedbackBoard::user())->toBeNull()
        ->and(FeedbackBoard::user())->toBeNull();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message) => str_contains($message, '"client" Filament panel')
            && str_contains($message, 'feedback-board.auth.guards'));
});

it('uses only the panel guard inside a listed panel', function () {
    $this->staffAndClients()->signIn($this->staff, 'web')->signIn($this->client, 'client')->inPanel('client');

    expect(app(Board::class)->recognisedUsers())->toBe([['guard' => 'client', 'user' => $this->client]]);
});

it('lists every recognised session outside a panel, in resolution order', function () {
    $this->staffAndClients()->signIn($this->staff, 'web')->signIn($this->client, 'client');

    expect(app(Board::class)->recognisedUsers())->toBe([
        ['guard' => 'web', 'user' => $this->staff],
        ['guard' => 'client', 'user' => $this->client],
    ]);
});

it('skips a signed-in user that is not an Eloquent model', function () {
    $this->signIn(new GenericUser(['id' => 1, 'name' => 'Generic']), 'web');

    expect(FeedbackBoard::user())->toBeNull();
});
