<?php

use Illuminate\Support\Facades\{Gate, Log};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\Guards\{ClientExtendingStaff, TestClient};

// Staff and client share a name, an e-mail and the id 1: a rule keyed on any of them matches both.
beforeEach(function () {
    $this->staff = FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    $this->client = TestClient::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->email === 'dev@x.com');
});

it('changes nothing without configuration: the resolver alone decides', function () {
    expect(FeedbackBoard::isDeveloper($this->staff))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($this->client))->toBeTrue();
});

it('needs no developer_guards when one guard is listed', function () {
    config()->set('feedback-board.auth.guards', ['web']);

    expect(FeedbackBoard::isDeveloper($this->staff))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($this->client))->toBeFalse();
});

it('has no developers when several guards are listed without developer_guards, and warns once', function (mixed $developerGuards) {
    Log::spy();
    config()->set('feedback-board.auth.guards', ['web', 'client']);
    config()->set('feedback-board.auth.developer_guards', $developerGuards);

    expect(FeedbackBoard::isDeveloper($this->staff))->toBeFalse()
        ->and(FeedbackBoard::isDeveloper($this->client))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message) => str_contains($message, 'feedback-board.auth.developer_guards'));
})->with(['null' => [null], 'an empty list' => [[]]]);

it('never makes a client a developer when developers sign in on web, whatever the resolver matches', function () {
    $this->staffAndClients();
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->getKey() === 1 || $user->email === 'dev@x.com');

    expect(FeedbackBoard::isDeveloper($this->staff))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($this->client))->toBeFalse()
        ->and(Gate::forUser($this->client)->allows('viewAny', FeedbackItem::class))->toBeFalse();
});

it('never hands a client to the developer resolver', function () {
    $this->staffAndClients();
    $seen = [];
    FeedbackBoard::resolveDevelopersUsing(function ($user) use (&$seen) {
        $seen[] = $user::class;

        return true;
    });

    FeedbackBoard::isDeveloper($this->client);
    FeedbackBoard::isDeveloper($this->staff);

    expect($seen)->toBe([FilamentTestUser::class]);
});

it('skips developer_guards entries that are not in guards, and warns once', function () {
    Log::spy();
    config()->set('feedback-board.auth.guards', ['web', 'client']);
    config()->set('feedback-board.auth.developer_guards', ['web', 'admin', 3]);

    expect(FeedbackBoard::isDeveloper($this->staff))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($this->client))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'feedback-board.auth.developer_guards')
            && $context['skipped'] === ['admin', 'int']);
});

it('has no developers when developer_guards is not a list, and warns once', function () {
    Log::spy();
    config()->set('feedback-board.auth.guards', ['web', 'client']);
    config()->set('feedback-board.auth.developer_guards', 'web');

    expect(FeedbackBoard::isDeveloper($this->staff))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message) => str_contains($message, 'feedback-board.auth.developer_guards must be null or an array'));
});

it('skips a developer guard whose provider is not eloquent, and warns once', function () {
    Log::spy();
    config()->set('auth.providers.legacy', ['driver' => 'database', 'table' => 'users']);
    config()->set('auth.guards.legacy', ['driver' => 'session', 'provider' => 'legacy']);
    config()->set('feedback-board.auth.guards', ['web', 'legacy']);
    config()->set('feedback-board.auth.developer_guards', ['legacy', 'web']);

    expect(FeedbackBoard::isDeveloper($this->staff))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($this->staff))->toBeTrue();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message) => str_contains($message, '"legacy" guard'));
});

it('keeps a client model that extends the staff model out of developer access', function () {
    config()->set('auth.providers.subclients', ['driver' => 'eloquent', 'model' => ClientExtendingStaff::class]);
    config()->set('auth.guards.subclient', ['driver' => 'session', 'provider' => 'subclients']);
    config()->set('feedback-board.auth.guards', ['web', 'subclient']);
    config()->set('feedback-board.auth.developer_guards', ['web']);
    $client = ClientExtendingStaff::create(['name' => 'Dev', 'email' => 'dev@x.com']);

    expect(FeedbackBoard::isDeveloper($this->staff))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($client))->toBeFalse();
});
