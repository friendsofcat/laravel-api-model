<?php

namespace Tests\Model;

use FriendsOfCat\LaravelApiModel\Models\ApiModel;

class Item extends ApiModel
{
    protected $table = 'items';

    protected $connection = 'api_model';

    protected $fillable = [
        'name',
        'color',
        'price',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function related()
    {
        return $this->hasMany(Related::class);
    }

    public function scopeIsBlue($q)
    {
        return $q->whereColor('blue');
    }
}