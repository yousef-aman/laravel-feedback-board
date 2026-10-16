<?php

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Facades\{Auth, Log};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\Guards\ClientExtendingStaff;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    // Like Laragear WebAuthn's `eloquent-webauthn`: a custom driver that still returns Eloquent models.
    Auth::provider('eloquent-webauthn', fn ($app, array $config) => new EloquentUserProvider($app['hash'], $config['model']));
});

it('keeps a client that extends the staff model out of developer access on a custom driver', function () {
    config()->set('auth.providers.subclients', ['driver' => 'eloquent-webauthn', 'model' => ClientExtendingStaff::class]);
    config()->set('auth.guards.subclient', ['driver' => 'session', 'provider' => 'subclients']);
    config()->set('feedback-board.auth.guards', ['web', 'subclient']);
    config()->set('feedback-board.auth.developer_guards', ['web']);
    FilamentTestUser::create(['name' => 'Staff', 'email' => 'staff@x.com']);
    $client = ClientExtendingStaff::create(['name' => 'Client', 'email' => 'client@x.com']); // id 1, like the staff member
    $seen = [];
    FeedbackBoard::resolveDevelopersUsing(function ($user) use (&$seen) {
        $seen[] = $user::class;

        return $user->getKey() === 1;
    });

    $this->signIn($client, 'subclient');

    expect(FeedbackBoard::isDeveloper($client))->toBeFalse()
        ->and($seen)->toBe([]);
    $this->get(route('feedback-board.board.page'))->assertForbidden();
});

it('accepts a developer guard on a custom driver whose model is an Eloquent class', function () {
    config()->set('auth.providers.users', ['driver' => 'eloquent-webauthn', 'model' => TestUser::class]);
    config()->set('feedback-board.auth.guards', ['web']);
    $staff = FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    FeedbackBoard::resolveDevelopersUsing(fn () => true);

    expect(FeedbackBoard::isDeveloper($staff))->toBeTrue();
});

it('has no developers on a guard whose provider model is not an Eloquent class, and warns once', function () {
    Log::spy();
    // LdapRecord's shape: `model` names its own (non-Eloquent) class.
    config()->set('auth.providers.users', ['driver' => 'ldap', 'model' => stdClass::class]);
    config()->set('feedback-board.auth.guards', ['web']);
    $staff = FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    FeedbackBoard::resolveDevelopersUsing(fn () => true);

    expect(FeedbackBoard::isDeveloper($staff))->toBeFalse()
        ->and(FeedbackBoard::isDeveloper($staff))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message) => str_contains($message, '"web" guard') && str_contains($message, 'names no Eloquent model class'));
});

it('warns once about a listed non-developer guard whose users cannot be told apart by type', function () {
    Log::spy();
    config()->set('auth.guards.api', ['driver' => 'token', 'provider' => null]);
    config()->set('feedback-board.auth.guards', ['web', 'api']);
    config()->set('feedback-board.auth.developer_guards', ['web']);
    $staff = FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    FeedbackBoard::resolveDevelopersUsing(fn () => true);

    expect(FeedbackBoard::isDeveloper($staff))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($staff))->toBeTrue();
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message) => str_contains($message, '"api" guard'));
});

it('does not warn about a listed database-driver guard', function () {
    Log::spy();
    config()->set('auth.providers.legacy', ['driver' => 'database', 'table' => 'users']);
    config()->set('auth.guards.legacy', ['driver' => 'session', 'provider' => 'legacy']);
    config()->set('feedback-board.auth.guards', ['web', 'legacy']);
    config()->set('feedback-board.auth.developer_guards', ['web']);
    $staff = FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    FeedbackBoard::resolveDevelopersUsing(fn () => true);

    expect(FeedbackBoard::isDeveloper($staff))->toBeTrue();
    Log::shouldNotHaveReceived('warning');
});
