<?php

use Illuminate\Support\Facades\Route;
use YousefAman\FeedbackBoard\Http\Controllers\AssetController;
use YousefAman\FeedbackBoard\Http\Controllers\AttachmentController;
use YousefAman\FeedbackBoard\Http\Controllers\IngestController;
use YousefAman\FeedbackBoard\Http\Middleware\AcceptJson;
use YousefAman\FeedbackBoard\Http\Middleware\AuthenticateFeedbackUser;
use YousefAman\FeedbackBoard\Http\Middleware\VerifyIngestSignature;

// Never left unauthenticated: feedback-board.auth is appended when a host's list lacks it.
Route::middleware(AuthenticateFeedbackUser::ensureIn(config('feedback-board.route_middleware', ['web', AuthenticateFeedbackUser::ALIAS])))
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->group(function () {
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::view('feedback', 'feedback-board::pages.feedback')->name('page');
        Route::view('feedback/board', 'feedback-board::pages.board')
            ->middleware('can:viewAny,YousefAman\\FeedbackBoard\\Models\\FeedbackItem')
            ->name('board.page');
    });

Route::middleware([AcceptJson::class, 'throttle:60,1', VerifyIngestSignature::class])
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->post('ingest', IngestController::class)->name('ingest');

// Public, static and cookie-less: deliberately outside route_middleware (no session, no auth).
// No ".css" extension: common nginx configs serve *.css from disk and 404 before PHP runs.
Route::prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->get('assets/stylesheet', [AssetController::class, 'css'])->name('assets.css');
