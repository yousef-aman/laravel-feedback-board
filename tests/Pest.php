<?php

uses(YousefAman\FeedbackBoard\Tests\TestCase::class)->in(__DIR__);

// FeedbackDetailTest asserts that Livewire::test() throws an HttpException when a
// non-developer opens the detail panel (mount calls abort_unless). Livewire v3's test
// framework normally converts abort() to a 403 response (not a throw), so we apply
// withoutExceptionHandling() only for this file to make exceptions propagate as expected.
// KanbanTest must NOT have this active because it relies on ->assertForbidden().
// We use an empty uses() to add a beforeEach hook without conflicting with the TestCase
// already set by the global uses()->in(__DIR__) above.
uses()
    ->beforeEach(fn () => $this->withoutExceptionHandling())
    ->in('Feature/FeedbackDetailTest.php');
