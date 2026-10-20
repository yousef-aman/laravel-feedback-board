<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\{BlockQuote, Heading, ListItem};
use League\CommonMark\Extension\CommonMark\Node\Inline\{Image, Link};
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;

/**
 * Renders what the AI assistant wrote (a runner's analysis, an AI reply), which is usually Markdown.
 * Raw HTML is escaped; only an http(s) or mailto link stays a link, and it opens in a new tab
 * without a referrer; an image becomes its alt text, so nothing is loaded from elsewhere.
 * Each block takes its direction from its own text, and code stays left to right.
 */
final class Markdown
{
    /** Longer text is shown plain: formatting it costs time and memory on every render. */
    private const MAX_CHARS = 20000;

    private static ?MarkdownConverter $converter = null;

    public static function html(string $markdown): HtmlString
    {
        if (mb_strlen($markdown) > self::MAX_CHARS) {
            return new HtmlString('<p dir="auto">'.nl2br(e($markdown), false).'</p>');
        }

        $html = self::converter()->convert($markdown)->getContent();

        // A code block's attributes go on its <code>, so <pre> gets its direction here. The converter
        // wrote every tag (the text's own < is escaped), so this only adds an attribute.
        return new HtmlString((string) preg_replace('/<pre(?=[\s>])/', '<pre dir="ltr"', $html));
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

                self::direct($node);
            }

            foreach ($nodes as $node) {
                self::unwrap($node);
            }
        });

        return self::$converter = new MarkdownConverter($environment);
    }

    /**
     * A mailto link, or an http(s) one that names a site: "https:/admin" or "https:///x" would
     * resolve against the app's own address. A relative one is dropped too: the assistant means a
     * file in its repository.
     */
    private static function allowed(string $url): bool
    {
        if (preg_match('/^mailto:/i', $url) === 1) {
            return true;
        }

        return preg_match('#^https?://[^/?\#\s]#i', $url) === 1 && filled(parse_url($url, PHP_URL_HOST));
    }

    /**
     * Gives each block dir="auto", so it takes its direction from its own text. A paragraph in a list
     * item or a quote gets none: dir="auto" skips text inside elements with their own dir, so the
     * item or quote reads it. A task list item is marked: its checkbox replaces the bullet (the
     * stylesheet cannot use :has()).
     */
    private static function direct(Node $node): void
    {
        $inItem = $node instanceof Paragraph && ($node->parent() instanceof ListItem || $node->parent() instanceof BlockQuote);

        if (! $inItem && ($node instanceof Paragraph || $node instanceof Heading || $node instanceof ListItem || $node instanceof BlockQuote || $node instanceof TableCell)) {
            $node->data->set('attributes/dir', 'auto');
        }

        if ($node instanceof ListItem && $node->firstChild()?->firstChild() instanceof TaskListItemMarker) {
            $node->data->append('attributes/class', 'fb-md__task');
        }
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
