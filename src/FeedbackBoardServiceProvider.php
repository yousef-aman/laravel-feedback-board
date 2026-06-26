<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FeedbackBoardServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('feedback-board')
            ->hasConfigFile()
            ->hasMigrations([
                'create_feedback_items_table',
                'create_feedback_comments_table',
                'create_feedback_attachments_table',
                'create_feedback_activities_table',
            ])
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web');
    }

    public function packageBooted(): void
    {
        \Illuminate\Support\Facades\Gate::define('feedback-board.manage', function ($user) {
            $resolver = config('feedback-board.authorization.developer_resolver');
            return is_callable($resolver) ? (bool) $resolver($user) : false;
        });

        \Illuminate\Support\Facades\Gate::policy(
            \YousefAman\FeedbackBoard\Models\FeedbackItem::class,
            \YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy::class,
        );

        \Illuminate\Support\Facades\Event::subscribe(\YousefAman\FeedbackBoard\Listeners\SendFeedbackNotifications::class);
    }
}
