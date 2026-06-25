<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemFactory extends Factory
{
    protected $model = FeedbackItem::class;

    public function definition(): array
    {
        return [
            'project' => 'default',
            'reporter_name' => $this->faker->name(),
            'reporter_email' => $this->faker->safeEmail(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'type' => FeedbackType::Bug,
            'status' => FeedbackStatus::New,
            'meta' => [],
        ];
    }
}
