<?php

namespace Tests\Model;

use Illuminate\Database\Eloquent\Model;

class Related extends Model
{
    protected $fillable = ['item_id'];

    protected $table = 'related';

    protected $connection = 'local_db';
}