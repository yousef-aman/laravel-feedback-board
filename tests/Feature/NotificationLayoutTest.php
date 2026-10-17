<?php

use Illuminate\Support\Facades\{Notification, View};
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification, NewFeedbackNotification, ReporterRepliedNotification};

beforeEach(function () {
    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('feedback-board.notifications.channels', ['mail']);
});

/** @return array{0: string, 1: string} the HTML and the plain-text part of the mail the notification sends */
function hLayoutMail(Illuminate\Notifications\Notification $notification): array
{
    Notification::route('mail', 'dev@team.test')->notify($notification);
    $email = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    return [(string) $email->getHtmlBody(), (string) $email->getTextBody()];
}

/**
 * The element in the mail body that carries a dir attribute: its direction, the text-align of
 * each paragraph and heading inside it, and its text.
 *
 * @return array{dir: ?string, aligns: list<string>, text: string}
 */
function hBody(string $html): array
{
    $doc = new DOMDocument;
    $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);
    $wrapper = $xpath->query("//td[contains(@class, 'content-cell')]//*[@dir]")->item(0);

    if ($wrapper === null) {
        return ['dir' => null, 'aligns' => [], 'text' => ''];
    }

    $aligns = [];
    foreach ($xpath->query('.//p | .//h1', $wrapper) as $block) {
        preg_match_all('/text-align:\s*([a-z]+)/', $block->getAttribute('style'), $m);
        $aligns[] = $m[1] === [] ? 'none' : end($m[1]);
    }

    return [
        'dir' => $wrapper->getAttribute('dir'),
        'aligns' => array_values(array_unique($aligns)),
        'text' => trim((string) preg_replace('/\s+/u', ' ', $wrapper->textContent)),
    ];
}

function hLayoutComment(AuthorRole $role): FeedbackComment
{
    $item = FeedbackItem::factory()->create(['title' => 'زر الحفظ (Orders) رقم 15']);

    return FeedbackComment::factory()->for($item, 'item')->create(['author_role' => $role, 'body' => 'جرّب (Ctrl+F5) أولًا.']);
}

dataset('feedback mails', [
    'new report' => fn () => new NewFeedbackNotification(FeedbackItem::factory()->create(['title' => 'زر الحفظ (Orders) رقم 15'])),
    'reply to the reporter' => fn () => new FeedbackRepliedNotification(hLayoutComment(AuthorRole::Developer)),
    'reporter reply' => fn () => new ReporterRepliedNotification(hLayoutComment(AuthorRole::Reporter)),
    'resolved' => fn () => new FeedbackResolvedNotification(FeedbackItem::factory()->create(['title' => 'زر الحفظ (Orders) رقم 15'])),
]);

it('lays the e-mail out right to left in Arabic', function (Illuminate\Notifications\Notification $notification) {
    app()->setLocale('ar');

    [$html] = hLayoutMail($notification);

    expect(hBody($html))->toMatchArray(['dir' => 'rtl', 'aligns' => ['right']]);
})->with('feedback mails');

it('greets and signs off in Arabic, in both parts of the e-mail', function (Illuminate\Notifications\Notification $notification) {
    app()->setLocale('ar');
    config()->set('app.name', 'متجري');

    [$html, $text] = hLayoutMail($notification);

    expect(hBody($html)['text'])->toStartWith('مرحبًا!')->toEndWith('مع التحية، متجري')
        ->and($text)->toContain('مرحبًا!')->toContain('مع التحية،')
        ->and($html.$text)->not->toContain('Hello!')->not->toContain('Regards,');
})->with('feedback mails');

it('keeps the English e-mail left to right, with its English greeting and sign-off', function (Illuminate\Notifications\Notification $notification) {
    config()->set('app.name', 'Shop');

    [$html] = hLayoutMail($notification);
    $body = hBody($html);

    expect($body['dir'])->toBe('ltr')
        ->and($body['aligns'])->not->toContain('right')
        ->and($body['text'])->toStartWith('Hello!')->toEndWith('Regards, Shop');
})->with('feedback mails');

it('keeps the markup out of the plain-text part', function (Illuminate\Notifications\Notification $notification) {
    app()->setLocale('ar');

    [, $text] = hLayoutMail($notification);

    expect($text)->not->toContain('<div')->not->toContain('dir=');
})->with('feedback mails');

it("applies the app's own mail theme along with the right-to-left alignment", function () {
    $views = sys_get_temp_dir().'/fb-host-views-'.getmypid();
    @mkdir($views.'/mail', 0777, true);
    file_put_contents($views.'/mail/fb-host.css', ".content-cell { color: #123456; }\np { text-align: left; }\n");
    View::addLocation($views);
    config()->set('mail.markdown.theme', 'fb-host');
    app()->setLocale('ar');

    try {
        [$html] = hLayoutMail(new NewFeedbackNotification(FeedbackItem::factory()->create()));
    } finally {
        @unlink($views.'/mail/fb-host.css');
        @rmdir($views.'/mail');
        @rmdir($views);
    }

    expect($html)->toContain('color: #123456')
        ->and(hBody($html)['aligns'])->toBe(['right']);
});
