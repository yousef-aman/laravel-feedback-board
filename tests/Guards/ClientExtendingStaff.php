<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use YousefAman\FeedbackBoard\Tests\TestUser;

/** A client model that extends the staff model (class Client extends User), on its own table and guard. */
class ClientExtendingStaff extends TestUser
{
    protected $table = 'clients';
}
