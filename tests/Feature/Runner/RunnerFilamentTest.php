<?php

use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\DispatchToRunner;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

it('hard-authorizes sendToRunner inside the action body (not just UI hiding)', function () {
    // Invoke the same action the Filament button calls, with a non-developer.
    $item = FeedbackItem::factory()->create();
    expect(fn () => app(DispatchToRunner::class)->handle($item, TestUser::create(['name' => 'User'])))
        ->toThrow(HttpException::class);
});

it('renders the runner-result modal view escaped', function () {
    $view = view('feedback-board::filament.runner-result', ['runner' => [
        'status' => 'completed', 'diff' => '<script>x</script>', 'pr_url' => 'https://github.com/o/r/pull/1', 'error_code' => null,
    ]])->render();
    expect($view)->toContain('https://github.com/o/r/pull/1')
        ->and($view)->toContain('&lt;script&gt;')        // escaped
        ->and($view)->not->toContain('<script>x</script>');
});
