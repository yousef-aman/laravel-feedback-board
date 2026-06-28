<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;

final class TriagePrompt
{
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

    public static function system(): string
    {
        $types = implode(', ', self::typeValues());
        $priorities = implode(', ', self::priorityValues());

        return implode("\n", [
            'You triage user feedback for a software team.',
            "Choose the single best `type` from: {$types}.",
            "Choose the single best `priority` from: {$priorities}.",
            'If a field is genuinely unclear, omit it rather than guessing.',
            'Write a concise one-or-two sentence `summary` in the SAME language as the input.',
            'Always respond by calling the submit_triage tool.',
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
        // never exceeds $maxChars (the spec's combined budget).
        $descLabel = "\n\nDescription: ";
        $remaining = max(0, $maxChars - mb_strlen($titleLine) - mb_strlen($descLabel));
        $description = Str::limit($description, $remaining, '');

        return $titleLine.$descLabel.$description;
    }

    /** @return array<string, mixed> */
    public static function tool(): array
    {
        return [
            'name' => 'submit_triage',
            'description' => 'Submit the triage classification for one feedback item.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => self::typeValues()],
                    'priority' => ['type' => 'string', 'enum' => self::priorityValues()],
                    'summary' => ['type' => 'string', 'description' => 'Concise summary in the input language.'],
                ],
                'required' => ['summary'],
            ],
        ];
    }
}
