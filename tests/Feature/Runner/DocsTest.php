<?php

it('ships the runner contract docs and reference recipe', function () {
    $root = __DIR__.'/../../..';
    expect(file_exists($root.'/docs/runner-contract.md'))->toBeTrue()
        ->and(file_exists($root.'/docs/examples/runner/receiver.example.php'))->toBeTrue()
        ->and(file_exists($root.'/docs/examples/runner/github-actions-feedback-runner.example.yml'))->toBeTrue();

    $contract = file_get_contents($root.'/docs/runner-contract.md');
    expect($contract)->toContain('runner_status')
        ->and($contract)->toContain('runner_result')
        ->and($contract)->toContain('X-Feedback-Signature')
        ->and($contract)->toContain('task_id');

    expect(file_get_contents($root.'/README.md'))->toContain('AI Runner Contract');
});

it('ships the reference post-back script', function () {
    expect(file_exists(dirname(__DIR__, 3).'/docs/examples/runner/post-back.sh'))->toBeTrue();
});
