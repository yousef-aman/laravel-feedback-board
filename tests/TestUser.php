<?php

namespace YousefAman\FeedbackBoard\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class TestUser extends Authenticatable
{
    use Notifiable;
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}
