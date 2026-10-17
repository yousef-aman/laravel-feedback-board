<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;

final class TriagePrompt
{
    /** The name of the function a provider is made to call with the triage. */
    public const TOOL = 'submit_triage';

    private const TOOL_DESCRIPTION = 'Submit the triage classification for one feedback item.';

    private const TITLE_CAP = 200;

    /** @return list<string> */
    private static function typeValues(): array
    {
        return array_map(fn (FeedbackType $t) => $t->value, FeedbackType::cases());
    }

    /** @return list<string> */
    private static function priorityValues(): array
    {
        return array_map(fn (FeedbackPriority $p) => $p->value, FeedbackPriority::cases());
    }

    /** @param  bool  $viaTool  false when the provider answers with a JSON object instead of a tool call */
    public static function system(bool $viaTool = true): string
    {
        $types = implode(', ', self::typeValues());
        $priorities = implode(', ', self::priorityValues());

        return implode("\n", [
            'You triage user feedback for a software team.',
            "Choose the single best `type` from: {$types}.",
            "Choose the single best `priority` from: {$priorities}.",
            'If a field is genuinely unclear, omit it rather than guessing.',
            'Write a concise one-or-two sentence `summary` in the SAME language as the input.',
            $viaTool ? 'Always respond by calling the '.self::TOOL.' tool.' : 'Always respond with a JSON object holding these fields.',
        ]);
    }

    public static function user(string $title, ?string $description, int $maxChars): string
    {
        $title = Str::limit(trim($title), self::TITLE_CAP, '');
        $titleLine = "Title: {$title}";

        $description = trim((string) $description);
        if ($description === '') {
            return $titleLine;
        }

        // Account for the framing labels INSIDE the budget so the final string
        // never exceeds $maxChars (the combined budget).
        $descLabel = "\n\nDescription: ";
        $remaining = max(0, $maxChars - mb_strlen($titleLine) - mb_strlen($descLabel));
        $description = Str::limit($description, $remaining, '');

        return $titleLine.$descLabel.$description;
    }

    /** The forced tool in Anthropic's shape. @return array<string, mixed> */
    public static function tool(): array
    {
        return [
            'name' => self::TOOL,
            'description' => self::TOOL_DESCRIPTION,
            'input_schema' => self::schema(),
        ];
    }

    /** The forced function in the shape of OpenAI's Chat Completions. @return array<string, mixed> */
    public static function function(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::TOOL,
                'description' => self::TOOL_DESCRIPTION,
                'parameters' => self::schema(),
            ],
        ];
    }

    /** The triage fields as a JSON Schema object. @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => self::typeValues()],
                'priority' => ['type' => 'string', 'enum' => self::priorityValues()],
                'summary' => ['type' => 'string', 'description' => 'Concise summary in the input language.'],
            ],
            'required' => ['summary'],
        ];
    }
}
