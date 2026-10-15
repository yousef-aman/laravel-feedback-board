<?php

namespace YousefAman\FeedbackBoard\Tests\Tenancy;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $table = 'teams';
    protected $guarded = [];
    public $timestamps = false;
}
