<?php

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Notifications\{FeedbackResolvedNotification, NewFeedbackNotification, ReporterRepliedNotification};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('feedback-board.notifications.channels', ['mail']);
});

/** @return array{0: string, 1: string} the HTML and the plain-text part of the mail the notification sends */
function hMail(Illuminate\Notifications\Notification $notification): array
{
    Notification::route('mail', 'dev@team.test')->notify($notification);
    $email = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    return [(string) $email->getHtmlBody(), (string) $email->getTextBody()];
}

dataset('reporter markup', [
    // text as typed, what the HTML must show, a pattern the HTML must not contain
    'link' => ['see [a](https://evil.example) now', 'see [a](https://evil.example) now', '#href="https://evil\.example#'],
    'image' => ['![x](https://evil.example/p.png)', '![x](https://evil.example/p.png)', '#src="https://evil\.example#'],
    'reference link' => ["[1]: https://evil.example\n[a][1]", '[1]: https://evil.example [a][1]', '#href="https://evil\.example#'],
    'code fence' => ['~~~ x', '~~~ x', '#<pre[\s>]#'],
    'heading' => ['# Security notice', '# Security notice', '#<h1[^>]*>\s*Security notice#'],
    'heading after spaces' => ['   # Security notice', '# Security notice', '#<h1[^>]*>\s*Security notice#'],
    'heading after newlines' => ["\n\n# Security notice", '# Security notice', '#<h1[^>]*>\s*Security notice#'],
    'ordered list' => ['1. first', '1. first', '#<ol[\s>]#'],
]);

it('keeps a reporter title inert in the new-report mail', function (string $text, string $shown, string $forbidden) {
    $item = FeedbackItem::factory()->create(['title' => $text]);

    [$html] = hMail(new NewFeedbackNotification($item));

    expect(html_entity_decode($html))->toContain($shown)
        ->and(preg_match($forbidden, $html))->toBe(0);
})->with('reporter markup');

it('keeps a reporter reply inert in the mail to developers', function (string $text, string $shown, string $forbidden) {
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create(['title' => 'Checkout']);
    $comment = app(ReplyToFeedback::class)->handle($item, $text, $client, AuthorRole::Reporter);

    [$html] = hMail(new ReporterRepliedNotification($comment));

    expect(html_entity_decode($html))->toContain($shown)
        ->and(preg_match($forbidden, $html))->toBe(0);
})->with('reporter markup');

it('shows the escaping as backslashes in the plain-text part', function () {
    $item = FeedbackItem::factory()->create(['title' => 'see [a](https://evil.example) now']);

    [, $text] = hMail(new NewFeedbackNotification($item));

    expect($text)->toContain('see \[a\](https://evil.example) now');
});

it('keeps a link inert when the host turns on secured Markdown encoding', function () {
    if (! method_exists(Markdown::class, 'withSecuredEncoding')) {
        test()->markTestSkipped('This Laravel has no secured Markdown encoding.');
    }
    Markdown::withSecuredEncoding();

    try {
        $item = FeedbackItem::factory()->create(['title' => 'see [a](https://evil.example) ![x](https://evil.example/p.png)']);
        [$html] = hMail(new NewFeedbackNotification($item));
    } finally {
        Markdown::withoutSecuredEncoding();
    }

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('src="https://evil.example');
});

it('shows ordinary special characters as typed, without double escaping', function () {
    $item = FeedbackItem::factory()->create(['title' => 'R&D <b>x</b> مرحبا']);

    [$html] = hMail(new NewFeedbackNotification($item));

    expect($html)->toContain('R&amp;D')
        ->and($html)->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->and($html)->not->toContain('<b>x</b>')
        ->and($html)->not->toContain('&amp;amp;')
        ->and(html_entity_decode($html))->toContain('مرحبا');
});

it('puts the escaped title in the Filament bell and the raw title in feedback_title', function () {
    if (! class_exists(\Filament\Notifications\Notification::class)) {
        test()->markTestSkipped('Filament is not installed.');
    }
    $title = '<a href="https://evil.example" style="position:fixed">x</a> R&D';
    $item = FeedbackItem::factory()->create(['title' => $title]);

    $comment = FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Reporter]);

    $new = (new NewFeedbackNotification($item))->toArray(new AnonymousNotifiable);
    $resolved = (new FeedbackResolvedNotification($item))->toArray(new AnonymousNotifiable);
    $reply = (new ReporterRepliedNotification($comment))->toArray(new AnonymousNotifiable);

    expect($new['title'])->toContain('&lt;a href=')->not->toContain('<a href=')
        ->and($new['feedback_title'])->toBe($title)
        ->and($resolved['title'])->toContain('&lt;a href=')->not->toContain('<a href=')
        ->and($reply['title'])->toContain('&lt;a href=')->not->toContain('<a href=')
        ->and($reply['kind'])->toBe('reporter_reply');
});
