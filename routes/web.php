<?php

// Feedback Board routes — filled in Task 4

use Illuminate\Support\Facades\Route;
use YousefAman\FeedbackBoard\Http\Controllers\AttachmentController;

Route::middleware(config('feedback-board.route_middleware', ['web', 'auth']))
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->group(function () {
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    });
