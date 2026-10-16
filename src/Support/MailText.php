<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

/**
 * Makes untrusted text inert in a Laravel Markdown mail line (MailMessage::line()).
 *
 * Laravel echoes each line HTML-escaped (so <, >, & and quotes are already safe) and then runs the
 * whole body through CommonMark. The text is folded first, exactly as MailMessage::line() does, so
 * block syntax can only start the one line. Then every character that starts a link, an image, a
 * code span, emphasis or a code fence is backslash-escaped, and so is a leading block marker.
 * `]` is escaped as well as `[`: under Markdown::withSecuredEncoding() Laravel turns `\[` into `\\[`.
 *
 * - The backslashes show in the plain-text part of the mail; the HTML part shows the text as typed,
 *   except that Laravel's Markdown::render() itself turns a typed `\[` into `[`.
 * - <, > and & are left alone: a backslash before Blade's entity would print the entity text.
 * - The rule assumes Laravel's default CommonMark extensions. A host that adds the Autolink
 *   extension (mail.markdown.extensions) gets live bare URLs, which no escaping can stop.
 */
final class MailText
{
    public static function escape(string $text): string
    {
        $line = trim(implode(' ', array_map(trim(...), preg_split('/\r\n|\r|\n/', $text) ?: [$text])));

        $line = (string) preg_replace('/[\\\\`*_\[\]~]/', '\\\\$0', $line);
        $line = (string) preg_replace('/^([#\-+=])/', '\\\\$1', $line);

        return (string) preg_replace('/^(\d+)([.)])/', '$1\\\\$2', $line);
    }
}
