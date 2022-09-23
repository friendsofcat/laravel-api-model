<?php

namespace Tests\Model;

use FriendsOfCat\LaravelApiModel\Models\ApiModel;

class Category extends ApiModel
{
    protected $table = 'categories';

    protected $connection = 'api_model';
}