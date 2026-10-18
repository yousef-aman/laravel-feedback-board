<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use YousefAman\FeedbackBoard\FeedbackBoardServiceProvider;

// The command publishes into the Testbench skeleton, which every test app shares:
// a stale config/feedback-board.php there would be loaded by later tests.
// Not safe under `pest --parallel` (the suite does not use it).
function removePublishedInstallFiles(): void
{
    File::delete(config_path('feedback-board.php'));
    File::delete(publishedFeedbackMigrations());
}

function publishedFeedbackMigrations(): array
{
    $files = [
        ...(glob(database_path('migrations/*_create_feedback_*_table.php')) ?: []),
        ...(glob(database_path('migrations/*_update_feedback_items_add_reporter_columns.php')) ?: []),
    ];
    sort($files);

    return $files;
}

beforeEach(fn () => removePublishedInstallFiles());
afterEach(fn () => removePublishedInstallFiles());

it('publishes the config and the six migrations, then prints the next steps', function () {
    $this->artisan('feedback-board:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->expectsOutputToContain('<x-feedback-board::launcher />')
        ->expectsOutputToContain('use YousefAman\\FeedbackBoard\\Facades\\FeedbackBoard;')
        ->expectsOutputToContain('FeedbackBoard::resolveDevelopersUsing')
        ->expectsOutputToContain('make:notifications-table')
        ->expectsOutputToContain('\\YousefAman\\FeedbackBoard\\Filament\\FeedbackBoardPlugin::make()')
        ->assertSuccessful();

    expect(File::exists(config_path('feedback-board.php')))->toBeTrue()
        ->and(publishedFeedbackMigrations())->toHaveCount(6)
        ->and(basename(last(publishedFeedbackMigrations())))->toEndWith('_create_feedback_reads_table.php')
        ->and(Artisan::all()['feedback-board:install']->isHidden())->toBeTrue();
});

it('is safe to re-run: keeps an edited config and does not duplicate migrations', function () {
    $this->artisan('feedback-board:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->assertSuccessful();
    File::append(config_path('feedback-board.php'), "\n// edited by the host app\n");

    // A later run recomputes the publish map with new timestamps; without filename
    // reuse it would publish a second set of migrations.
    $this->travel(2)->minutes();
    $this->app->register(FeedbackBoardServiceProvider::class, force: true);

    $this->artisan('feedback-board:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->assertSuccessful();

    expect(publishedFeedbackMigrations())->toHaveCount(6)
        ->and(File::get(config_path('feedback-board.php')))->toContain('// edited by the host app');
});

it('points apps whose reporters use another guard at auth.guards', function () {
    $this->artisan('feedback-board:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->expectsOutputToContain('feedback-board.auth.guards')
        ->assertSuccessful();
});
