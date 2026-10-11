<?php

use YousefAman\FeedbackBoard\Services\AttachmentStorage;

it('limits composer uploads to the configured image types', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['PNG', 'jpg', 'pdf', 'svg']);
    $storage = app(AttachmentStorage::class);

    expect($storage->imageMimes())->toBe(['jpg', 'png'])
        ->and($storage->imageRules())->toBe(['file', 'mimes:jpg,png', 'max:10240']);
});

it('has no composer image types when the host allows none', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['pdf', 'txt']);

    expect(app(AttachmentStorage::class)->imageMimes())->toBe([]);
});

it('reads the file-count limit from config, never below one', function (mixed $value, int $expected) {
    config()->set('feedback-board.attachments.max_files', $value);

    expect(app(AttachmentStorage::class)->maxFiles())->toBe($expected);
})->with([[10, 10], [3, 3], [0, 1], [-4, 1], ['5', 5]]);

it('ships a ten-file default', function () {
    expect(config('feedback-board.attachments.max_files'))->toBe(10);
});
