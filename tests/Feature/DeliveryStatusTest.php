<?php

use Illuminate\Support\Facades\{Bus, Schema};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.destinations.lock_store', 'array');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
    $this->dev = TestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    $this->actingAs($this->dev);
});

function hFailure(string $code, string $event, int $minutesAgo): array
{
    return ['code' => $code, 'event' => $event, 'at' => now()->subMinutes($minutesAgo)->toIso8601String()];
}

it('links the GitHub issue the report was sent to', function () {
    $item = FeedbackItem::factory()->create(['external_refs' => ['github' => [
        'issue_number' => 7, 'html_url' => 'https://github.com/acme/app/issues/7', 'repo' => 'acme/app', 'posted' => [],
    ]]]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('href="https://github.com/acme/app/issues/7" target="_blank" rel="noopener noreferrer"')
        ->assertSee('GitHub issue #7');
});

it('does not turn a stored issue address that is not http(s) into a link', function () {
    $item = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 7, 'html_url' => 'javascript:alert(1)']]]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSeeHtml('javascript:alert(1)')
        ->assertDontSee('GitHub issue #7');
});

it('says which deliveries are failing, why and since when', function () {
    $item = FeedbackItem::factory()->create(['external_refs' => [
        'slack_last_error' => hFailure('connection_error', 'feedback.status_changed', 5),
        'github_last_error' => hFailure('auth_401', 'feedback.created', 40),
        'jira_last_error' => hFailure('something_new', 'feedback.replied', 2),
    ]]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('Delivery to Slack failed: could not connect.')
        ->assertSee('Status change, 5 minutes ago')
        ->assertSee('Delivery to GitHub failed: access was refused; check the token and its permissions.')
        ->assertSee('New report, 40 minutes ago')
        ->assertSee('Delivery to Jira failed: unknown error.')
        ->assertSee('Reply, 2 minutes ago');
});

it('says it in Arabic', function () {
    app()->setLocale('ar');
    $item = FeedbackItem::factory()->create(['external_refs' => ['slack_last_error' => hFailure('connection_error', 'feedback.replied', 5)]]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('تعذّر الإرسال إلى Slack: تعذّر الاتصال.')
        ->assertSee('رد، منذ 5 دقائق');
});

it('waits for the GitHub issue after a send, then shows the failure and offers the button again', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    Bus::fake();
    $item = FeedbackItem::factory()->create(['external_refs' => ['github_last_error' => hFailure('auth_401', 'feedback.created', 30)]]);

    $component = Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->call('sendToGithub')
        ->assertSee('Sent to GitHub; the issue is being created.')
        ->assertSeeHtml('wire:poll.5s')
        ->assertDontSee('Delivery to GitHub failed');

    $item->forceFill(['external_refs' => ['github_last_error' => hFailure('auth_401', 'feedback.created', 0)]])->save();

    $component->call('$refresh')
        ->assertDontSee('Sent to GitHub; the issue is being created.')
        ->assertDontSeeHtml('wire:poll.5s')
        ->assertSee('Delivery to GitHub failed: access was refused; check the token and its permissions.')
        ->assertSee('Send to GitHub');
});

it('stops waiting once the issue exists', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    $component = Livewire::test(FeedbackDetail::class, ['item' => $item])->call('sendToGithub');
    $item->forceFill(['external_refs' => ['github' => ['issue_number' => 9, 'html_url' => 'https://github.com/acme/app/issues/9']]])->save();

    $component->call('$refresh')
        ->assertDontSee('Sent to GitHub; the issue is being created.')
        ->assertDontSeeHtml('wire:poll.5s')
        ->assertSee('GitHub issue #9');
});

it('names the destination and the reason in the failure bell, with a link to the report', function () {
    Schema::create('notifications', function ($t) {
        $t->uuid('id')->primary();
        $t->string('type');
        $t->morphs('notifiable');
        $t->text('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });
    FeedbackBoard::notifyDevelopersUsing(fn () => [$this->dev]);
    $item = FeedbackItem::factory()->create();

    (new DeliverToDestination(new FeedbackItemCreated($item), 'github', 'dlv-1'))
        ->failed(new \Illuminate\Http\Client\ConnectionException('connection refused'));

    $data = $this->dev->notifications()->first()->data;
    expect($data['title'])->toBe('Delivery to GitHub failed')
        ->and($data['body'])->toBe('Could not connect.')
        ->and($data['actions'][0]['url'])->toBe('http://localhost/feedback-board/feedback/board?report='.$item->id);
});
