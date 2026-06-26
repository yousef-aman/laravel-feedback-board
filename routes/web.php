<?php

// Feedback Board routes

use Illuminate\Support\Facades\Route;
use YousefAman\FeedbackBoard\Http\Controllers\AttachmentController;
use YousefAman\FeedbackBoard\Http\Controllers\IngestController;
use YousefAman\FeedbackBoard\Http\Middleware\VerifyIngestSignature;

Route::middleware(config('feedback-board.route_middleware', ['web', 'auth']))
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->group(function () {
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    });

Route::middleware(['throttle:60,1', VerifyIngestSignature::class])
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->post('ingest', IngestController::class)->name('ingest');
