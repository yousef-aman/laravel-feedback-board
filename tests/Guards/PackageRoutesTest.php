<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, Route, Storage};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Http\Middleware\AuthenticateFeedbackUser;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\Guards\{RecordsUser, TestClient};

beforeEach(function () {
    Storage::fake('local');
    config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev');
    $this->staffAndClients();
    $this->client = TestClient::create(['name' => 'Client', 'email' => 'c@x.com']);
});

it('authenticates every package page and attachment with feedback-board.auth', function (string $name) {
    expect(Route::getRoutes()->getByName($name)->gatherMiddleware())
        ->toContain('feedback-board.auth')
        ->not->toContain('auth');
})->with(['feedback-board.page', 'feedback-board.board.page', 'feedback-board.attachments.show']);

it('appends feedback-board.auth to a list without it, and leaves a list that has it', function (mixed $given, array $expected) {
    expect(AuthenticateFeedbackUser::ensureIn($given))->toBe($expected);
})->with([
    'a published list with auth' => [['web', 'auth'], ['web', 'auth', 'feedback-board.auth']],
    'a single middleware' => ['web', ['web', 'feedback-board.auth']],
    'the alias with parameters' => [['web', 'feedback-board.auth:client'], ['web', 'feedback-board.auth:client']],
    'the class' => [['web', AuthenticateFeedbackUser::class], ['web', AuthenticateFeedbackUser::class]],
    'middleware after it' => [['web', 'feedback-board.auth', 'verified'], ['web', 'feedback-board.auth', 'verified']],
]);

it('signs a client in on the standalone My reports page and shows only their reports', function () {
    FeedbackItem::factory()->for($this->client, 'reporter')->create(['title' => 'Client report']);
    FeedbackItem::factory()->for(TestClient::create(['name' => 'Other']), 'reporter')->create(['title' => 'Other report']);
    $this->signIn($this->client, 'client');

    $this->get(route('feedback-board.page'))->assertOk()->assertSee('Client report')->assertDontSee('Other report');
    expect(Auth::getDefaultDriver())->toBe('client');
});

it('answers a request with no recognised user with the standard unauthenticated response', function () {
    $this->getJson(route('feedback-board.page'))->assertUnauthorized();

    config()->set('feedback-board.auth.guards', ['client']);
    $this->signIn(FilamentTestUser::create(['name' => 'Staff']), 'web');
    $this->getJson(route('feedback-board.page'))->assertUnauthorized();
});

it('names the guards it tried on the AuthenticationException, so a host can pick the login page', function () {
    // Testbench's redirectGuestsTo callback calls route('login') while the exception is built.
    Route::get('/login', fn () => 'login')->name('login');
    $this->withoutExceptionHandling();
    $guards = null;

    try {
        $this->get(route('feedback-board.page'));
    } catch (AuthenticationException $e) {
        $guards = $e->guards();
    }

    expect($guards)->toBe(['web', 'client']);
});

it('lets can:viewAny on the board check the user it signed in, trying the guard in use first', function () {
    $this->signIn($this->client, 'client');
    $this->get(route('feedback-board.board.page'))->assertForbidden();

    $this->nextRequest()->signIn(FilamentTestUser::create(['name' => 'Dev']), 'web');
    $this->get(route('feedback-board.board.page'))->assertOk();
});

it('lets middleware listed after it see the user it signed in', function () {
    Route::middleware(['web', 'feedback-board.auth', RecordsUser::class])->get('/fb-after-auth', fn () => 'unreachable');
    $this->signIn($this->client, 'client');

    $this->get('/fb-after-auth')->assertOk()->assertSee('seen: Client');
});

it('is re-run on Livewire updates from package pages', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(AuthenticateFeedbackUser::class);
});

it('serves a client their own image and refuses it to another client', function () {
    $item = FeedbackItem::factory()->for($this->client, 'reporter')->create();
    $image = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);

    $this->signIn(TestClient::create(['name' => 'Other']), 'client');
    $this->get(route('feedback-board.attachments.show', $image))->assertForbidden();

    $this->nextRequest()->signIn($this->client, 'client');
    $this->get(route('feedback-board.attachments.show', $image))->assertOk();
});

it('serves a client their image in a browser that also holds a staff session', function () {
    $item = FeedbackItem::factory()->for($this->client, 'reporter')->create();
    $image = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);
    $this->signIn(FilamentTestUser::create(['name' => 'Staff']), 'web')->signIn($this->client, 'client');

    $this->get(route('feedback-board.attachments.show', $image))->assertOk();
});

it('serves an internal note image only to a developer session', function () {
    $item = FeedbackItem::factory()->for($this->client, 'reporter')->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true, 'author_role' => AuthorRole::Developer]);
    $secret = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('secret.png'), $item, $note);
    $this->signIn($this->client, 'client');

    $this->get(route('feedback-board.attachments.show', $secret))->assertForbidden();

    $this->nextRequest()->signIn(FilamentTestUser::create(['name' => 'Dev']), 'web');
    $this->get(route('feedback-board.attachments.show', $secret))->assertOk();
});

it('keeps a published list with auth working for the default guard and refusing other guards', function () {
    Route::middleware(AuthenticateFeedbackUser::ensureIn(['web', 'auth']))->get('/fb-legacy', fn () => 'ok');
    $this->signIn($this->client, 'client');
    $this->getJson('/fb-legacy')->assertUnauthorized();

    $this->nextRequest()->signIn(FilamentTestUser::create(['name' => 'Staff']), 'web');
    $this->getJson('/fb-legacy')->assertOk();
});
