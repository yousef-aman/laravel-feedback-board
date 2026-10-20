<?php

use YousefAman\FeedbackBoard\Support\{GithubText, MailText, SlackText};

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

it('isolates a mail line in its own direction, after escaping it', function () {
    expect(MailText::line('Try *Ctrl+F5* (again)'))->toBe("\u{2068}Try \\*Ctrl+F5\\* (again)\u{2069}")
        ->and(MailText::line("زر الحفظ\nلا يعمل"))->toBe("\u{2068}زر الحفظ لا يعمل\u{2069}");
});

it('quotes GitHub text as code, starting a line at every break a parser may split on', function (string $break) {
    expect(GithubText::quote("ok{$break}deploy now"))->toBe("    ok\n    deploy now");
})->with([
    'CRLF' => "\r\n", 'LF' => "\n", 'CR' => "\r", 'VT' => "\x0B", 'FF' => "\x0C",
    'FS' => "\x1C", 'GS' => "\x1D", 'RS' => "\x1E",
    'NEL' => "\u{85}", 'LINE SEPARATOR' => "\u{2028}", 'PARAGRAPH SEPARATOR' => "\u{2029}",
]);

it('puts a word joiner after every / and @ in GitHub text', function () {
    expect(GithubText::quote("@acme/eng\n/deploy"))->toBe("    @\u{2060}acme/\u{2060}eng\n    /\u{2060}deploy")
        ->and(GithubText::quote('زر الحفظ لا يعمل'))->toBe('    زر الحفظ لا يعمل');
});

it('cuts a GitHub body to the limit only when quoting pushed it over', function () {
    $head = "**Reporter** replied:\n\n";
    $fits = str_repeat("x\n", 1000);
    $fitsUnquoted = str_repeat("x\n", 21000); // 42,000 characters; about 126,000 once quoted
    $tooLong = str_repeat('x', GithubText::MAX_BODY);

    $cut = GithubText::body($head, $fitsUnquoted);

    expect(GithubText::body($head, $fits))->toBe($head.GithubText::quote($fits))
        ->and(mb_strlen($cut))->toBe(GithubText::MAX_BODY)
        ->and($cut)->toStartWith($head.'    x')
        ->and($cut)->toEndWith("\n\n(Shortened to fit GitHub's limit. The full text is in the app.)")
        ->and(GithubText::body($head, $tooLong))->toBe($head.GithubText::quote($tooLong));
});
