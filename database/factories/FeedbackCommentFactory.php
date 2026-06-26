<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

class FeedbackCommentFactory extends Factory
{
    protected $model = FeedbackComment::class;

    public function definition(): array
    {
        return [
            'feedback_item_id' => FeedbackItem::factory(),
            'author_role' => AuthorRole::Reporter,
            'body' => $this->faker->sentence(),
            'is_internal' => false,
        ];
    }
}
