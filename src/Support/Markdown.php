<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\{Image, Link};
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;

/**
 * Renders what the AI assistant wrote (a runner's analysis, an AI reply), which is usually Markdown.
 * Raw HTML is escaped; only an http(s) or mailto link stays a link, and it opens in a new tab
 * without a referrer; an image becomes its alt text, so nothing is loaded from elsewhere.
 * Each block takes its direction from its own text, and code stays left to right.
 */
final class Markdown
{
    private const SCHEMES = ['http', 'https', 'mailto'];

    private static ?MarkdownConverter $converter = null;

    public static function html(string $markdown): HtmlString
    {
        $html = self::converter()->convert($markdown)->getContent();

        // The converter wrote every tag here (the text's own < is escaped), so these only add attributes.
        $html = (string) preg_replace('/<(p|h[1-6]|li|blockquote|th|td)(?=[\s>])/', '<$1 dir="auto"', $html);
        $html = (string) preg_replace('/<pre(?=[\s>])/', '<pre dir="ltr"', $html);

        return new HtmlString($html);
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br>\n"],
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new ExternalLinkExtension());
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            $nodes = [];

            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Image || ($node instanceof Link && ! self::allowed($node->getUrl()))) {
                    $nodes[] = $node;
                }
            }

            foreach ($nodes as $node) {
                self::unwrap($node);
            }
        });

        return self::$converter = new MarkdownConverter($environment);
    }

    /**
     * An http(s) or mailto link. A relative one is dropped too: the assistant means a file in its
     * repository, which the app's own page would resolve to one of the app's addresses.
     */
    private static function allowed(string $url): bool
    {
        return preg_match('/^([^\/?#]*?):/', $url, $match) === 1
            && in_array(strtolower($match[1]), self::SCHEMES, true);
    }

    /** Puts a node's children (an image's alt text, a link's label) in its place. */
    private static function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }

        $node->detach();
    }
}
