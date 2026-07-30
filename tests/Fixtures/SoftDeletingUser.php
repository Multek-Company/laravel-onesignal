<?php

namespace Multek\OneSignal\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Multek\OneSignal\Concerns\HasOneSignal;

class SoftDeletingUser extends Model
{
    use HasOneSignal, SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
