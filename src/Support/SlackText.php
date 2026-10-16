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
}
