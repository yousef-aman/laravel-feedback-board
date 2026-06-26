<?php

// Feedback Board routes — filled in Task 4

use Illuminate\Support\Facades\Route;
use YousefAman\FeedbackBoard\Http\Controllers\AttachmentController;
use YousefAman\FeedbackBoard\Http\Controllers\IngestController;
use YousefAman\FeedbackBoard\Http\Middleware\VerifyIngestSignature;

Route::middleware(config('feedback-board.route_middleware', ['web', 'auth']))
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->group(function () {
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::view('feedback', 'feedback-board::pages.feedback')->name('page');
        Route::view('feedback/board', 'feedback-board::pages.board')
            ->middleware('can:viewAny,YousefAman\\FeedbackBoard\\Models\\FeedbackItem')
            ->name('board.page');
    });

Route::middleware(['throttle:60,1', VerifyIngestSignature::class])
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->post('ingest', IngestController::class)->name('ingest');
