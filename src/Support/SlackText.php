<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

/**
 * Escapes untrusted text for Slack `text` and `mrkdwn` objects. Slack treats &, < and > as control
 * characters there (<!channel>, <@U…>, <url|label>). One strtr pass, so & is never escaped twice.
 */
final class SlackText
{
    public static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    /** A mrkdwn link. A | would end the URL early, so it is percent-encoded. */
    public static function link(string $url, string $label): string
    {
        return '<'.self::escape(str_replace('|', '%7C', $url)).'|'.self::escape($label).'>';
    }
}
