<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

/**
 * Quotes untrusted text for a GitHub issue or comment, which is posted under the token's account.
 * An indented code block: GitHub makes no mention, reference, link, image, HTML or heading of it.
 * Every line break a parser may split on starts a new indented line, and a word joiner (U+2060)
 * follows each / and @, so a bot reading the raw body finds no /command or @name anywhere.
 */
final class GithubText
{
    /** GitHub's limit on an issue or comment body, in characters. */
    public const MAX_BODY = 65536;

    private const INDENT = '    ';

    private const SHORTENED = "\n\n(Shortened to fit GitHub's limit. The full text is in the app.)";

    public static function quote(string $text): string
    {
        // Bytes, not /u: invalid UTF-8 cannot make the match fail. NEL, LS and PS are matched as
        // their UTF-8 sequences, whose lead bytes never occur inside another character.
        $text = (string) preg_replace('/\r\n|[\n\r\x0B\x0C\x1C-\x1E]|\xC2\x85|\xE2\x80[\xA8\xA9]/', "\n", $text);
        $text = strtr($text, ['/' => "/\u{2060}", '@' => "@\u{2060}"]);

        return self::INDENT.str_replace("\n", "\n".self::INDENT, $text);
    }

    /**
     * $head, the quoted $text and $tail, cut to GitHub's limit only when quoting is what pushed
     * it over. A text already too long as written stays whole, and GitHub refuses it as before.
     */
    public static function body(string $head, string $text, string $tail = ''): string
    {
        $body = $head.self::quote($text).$tail;

        if (mb_strlen($body) <= self::MAX_BODY || mb_strlen($head.$text.$tail) > self::MAX_BODY) {
            return $body;
        }

        $room = self::MAX_BODY - mb_strlen($head.self::SHORTENED.$tail);

        return $head.mb_substr(self::quote($text), 0, $room).self::SHORTENED.$tail;
    }
}
