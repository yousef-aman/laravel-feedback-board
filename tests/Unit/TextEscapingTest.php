<?php

use YousefAman\FeedbackBoard\Support\{MailText, SlackText};

it('escapes the Markdown that starts a link, image, code span or emphasis', function (string $text, string $escaped) {
    expect(MailText::escape($text))->toBe($escaped);
})->with([
    'link' => ['see [a](https://evil.example) now', 'see \[a\](https://evil.example) now'],
    'image' => ['![x](https://evil.example/p.png)', '!\[x\](https://evil.example/p.png)'],
    'emphasis and code' => ['**bold** _it_ `code`', '\*\*bold\*\* \_it\_ \`code\`'],
    'code fence' => ['~~~ x', '\~\~\~ x'],
    'backslashes' => ['App\Http\X', 'App\\\\Http\\\\X'],
    'arabic' => ['مرحبا [رابط](https://x)', 'مرحبا \[رابط\](https://x)'],
]);

it('folds the text onto one line first, as MailMessage::line() does', function (string $text, string $escaped) {
    expect(MailText::escape($text))->toBe($escaped);
})->with([
    'leading spaces' => ['   # Security notice', '\# Security notice'],
    'leading newlines' => ["\n\n# Security notice", '\# Security notice'],
    'reference definition' => ["[1]: https://evil.example\n[a][1]", '\[1\]: https://evil.example \[a\]\[1\]'],
]);

it('escapes a block marker only at the start', function (string $text, string $escaped) {
    expect(MailText::escape($text))->toBe($escaped);
})->with([
    'heading' => ['# x', '\# x'],
    'bullet' => ['- item', '\- item'],
    'plus' => ['+ item', '\+ item'],
    'setext' => ['= x', '\= x'],
    'ordered list' => ['1. first', '1\. first'],
    'ordered list with a parenthesis' => ['12) first', '12\) first'],
    'markers mid-line' => ['a # b - c 1. d', 'a # b - c 1. d'],
]);

it('leaves characters Blade already escapes alone', function () {
    expect(MailText::escape('&lt;b&gt; R&D <b>x</b> > quote'))->toBe('&lt;b&gt; R&D <b>x</b> > quote');
});

it('escapes the three Slack control characters once', function () {
    expect(SlackText::escape('<!channel> R&D <https://evil.example|Open> &amp;'))
        ->toBe('&lt;!channel&gt; R&amp;D &lt;https://evil.example|Open&gt; &amp;amp;');
});
