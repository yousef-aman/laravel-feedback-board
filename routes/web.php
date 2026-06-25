<?php

// Feedback Board routes

use Illuminate\Support\Facades\Route;
use YousefAman\FeedbackBoard\Http\Controllers\AttachmentController;

Route::middleware(config('feedback-board.route_middleware', ['web', 'auth']))
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->group(function () {
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    });
