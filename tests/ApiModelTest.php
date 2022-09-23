<?php

namespace Tests;

use Tests\Model\Item;
use Tests\Model\Related;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class ApiModelTest extends TestCase
{
    use DatabaseMigrations;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function testItSupportsGet()
    {
        $responseData = [
            [
                'name' => 'test1',
                'color' => 'blue',
                'price' => 1000,
            ],
            [
                'name' => 'test2',
                'color' => 'blue',
                'price' => 1000,
            ]
        ];

        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response($responseData)
        ]);

        $items = $this->getBaseQuery()->get();

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && $request->method() == 'GET';
        });

        $this->assertEquals(count($responseData), $items->count());
        $this->assertEquals(Item::class, $items->first()::class);
        $this->assertEquals($responseData[0]['name'], $items->first()->name);
        $this->assertEquals($responseData[count($responseData) - 1]['name'], $items->last()->name);
    }

    public function testItSupportsCount()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'aggregate' => 2,
                ]
            ])
        ]);

        $numberOfItems = $this->getBaseQuery()->count();

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'queryType') == 'count'
                && $request->method() == 'GET';
        });

        $this->assertEquals(2, $numberOfItems);
    }

    public function testItSupportsAvg()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'aggregate' => 50.4,
                ]
            ])
        ]);

        $avgPrice = $this->getBaseQuery()->avg('price');

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'queryType') == 'avg,price'
                && $request->method() == 'GET';
        });

        $this->assertEquals(50.4, $avgPrice);
    }

    public function testItSupportsMin()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'aggregate' => 10,
                ]
            ])
        ]);

        $minPrice = $this->getBaseQuery()->min('price');

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'queryType') == 'min,price'
                && $request->method() == 'GET';
        });

        $this->assertEquals(10, $minPrice);
    }

    public function testItSupportsMax()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'aggregate' => 1000,
                ]
            ])
        ]);

        $maxPrice = $this->getBaseQuery()->max('price');

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'queryType') == 'max,price'
                && $request->method() == 'GET';
        });

        $this->assertEquals(1000, $maxPrice);
    }

    public function testItSupportsSum()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'aggregate' => 2350.5,
                ]
            ])
        ]);

        $total = $this->getBaseQuery()->sum('price');

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'queryType') == 'sum,price'
                && $request->method() == 'GET';
        });

        $this->assertEquals(2350.5, $total);
    }

    public function testItSupportsExists()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'exists' => true,
                ]
            ])
        ]);

        $exists = $this->getBaseQuery()->exists();

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'queryType') == 'exists'
                && $request->method() == 'GET';
        });

        $this->assertEquals(true, $exists);
    }

    public function testItSupportsSelects()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'title' => 'test1',
                    'color' => 'blue',
                    'price' => 1000,
                ],
            ])
        ]);

        Item::select('name as title', 'price', 'color')
            ->get();

        Http::assertSent(function (Request $request) {

            return data_get($request->data(), 'fields') == 'name as title,price,color'
                && $request->method() == 'GET';
        });
    }

    public function testItSupportsMultipleOrderBy()
    {
        Http::fake();

        Item::orderBy('name')
            ->orderBy('price', 'desc')
            ->get();

        Http::assertSent(function (Request $request) {

            return data_get($request->data(), 'sort') == 'name,-price'
                && $request->method() == 'GET';
        });
    }

    public function testItSupportsEagerLoading()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => Http::response([
                [
                    'id' => 1,
                    'name' => 'test1',
                    'color' => 'blue',
                    'price' => 1000,
                    'category' => [
                        'id' => 1,
                        'title' => 'Candy'
                    ],
                    'shops' => [
                        [
                            'name' => 'Walmart',
                            'location' => 'New York',
                        ],
                        [
                            'name' => 'Costco',
                            'location' => 'Denver',
                        ],
                    ]
                ],
            ])
        ]);

        Related::create(['item_id' => 1]);

        $item = Item::with([
            'category',             // relation with same connection, included in request and excluded from default eager loading
            'related',              // relation with different connection => default eager loading
            'shops:name,location',  // not defined relation => treated like relations with same connection
        ])->first();

        Http::assertSent(function (Request $request) {

            return data_get($request->data(), 'include') == 'category,shops:name:location'
                && $request->method() == 'GET';
        });

        $this->assertEquals('Candy', $item->category['title']);
        $this->assertCount(2, $item->shops);
        $this->assertEquals(Related::class, $item->related->first()::class);
        $this->assertCount(1, $item->related);
    }

    public function testItSupportsFirst()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => [
                [
                    'name' => 'test1',
                    'color' => 'blue',
                    'price' => 1000,
                ],
            ]
        ]);

        $item = $this->getBaseQuery()->first();

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'limit') == 1
                && $request->method() == 'GET';
        });

        $this->assertEquals(Item::class, $item::class);
        $this->assertEquals('test1', $item->name);
    }

    public function testItSupportsFind()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items*' => [
                [
                    'id' => 1,
                    'name' => 'test1',
                    'color' => 'blue',
                    'price' => 1000,
                ],
            ]
        ]);

        $item = Item::find(1);

        Http::assertSent(function (Request $request) {

            return data_get($request->data(), 'limit') == 1
                && data_get($request->data(), 'filter.and:id:in') == 1
                && $request->method() == 'GET';
        });

        $this->assertEquals(Item::class, $item::class);
        $this->assertEquals(1, $item->id);
    }

    public function testItSupportsInsert()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items' => [
                'bool' => true
            ],
        ]);

        $itemsCreated = Item::insert([
            [
                'name' => 'test1',
                'color' => 'blue',
                'price' => 1000,
            ],
            [
                'name' => 'test2',
                'color' => 'red',
                'price' => 1500,
            ]
        ]);

        Http::assertSent(function (Request $request) {

            return data_get($request->data(), 'getId') === false
                && isset($request->data()['data'])
                && $request->method() == 'POST';
        });

        $this->assertTrue($itemsCreated);
    }

    public function testItSupportsCreate()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items' => [
                'id' => 1
            ],
        ]);

        $item = Item::create([
            'name' => 'test1',
            'color' => 'blue',
            'price' => 1000,
        ]);

        Http::assertSent(function (Request $request) {

            return data_get($request->data(), 'getId') === true
                && isset($request->data()['data'])
                && $request->method() == 'POST';
        });

        $this->assertEquals(1, $item->id);
    }

    public function testItSupportsSave()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items' => [
                'id' => 1
            ],
        ]);

        $item = new Item();
        $item->title = 'test';
        $item->save();

        Http::assertSent(function (Request $request) {

            return data_get($request->data(), 'getId') === true
                && isset($request->data()['data'])
                && data_get($request->data(), 'data.0.title') == 'test'
                && $request->method() == 'POST';
        });

        $this->assertEquals(1, $item->id);
    }

    public function testItSupportsUpdate()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items' => Http::response(4)
        ]);

        $numberOfUpdatedItems = $this->getBaseQuery()->update([
            'color' => 'green',
        ]);

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request, true)
                && data_get($request->data(), 'data.color') == 'green'
                && isset($request->data()['data']['items.updated_at'])
                && data_get($request->data(), 'params.queryType') == 'update'
                && $request->method() == 'PUT';
        });

        $this->assertEquals(4, $numberOfUpdatedItems);
    }

    public function testItSupportsDelete()
    {
        Http::fake([
            config('database.connections.api_model.database') . '/items' => Http::response(4)
        ]);

        $numberOfDeletedItems = $this->getBaseQuery()->delete();

        Http::assertSent(function (Request $request) {

            return $this->checkBaseQuery($request)
                && data_get($request->data(), 'queryType') == 'delete'
                && $request->method() == 'DELETE';
        });

        $this->assertEquals(4, $numberOfDeletedItems);
    }

    public function getBaseQuery()
    {
        return Item::where('price', '>', 100)
            ->orWhere('name', 'like', 'test%')
            ->orWhere(
                fn ($q) => $q
                    ->where('color', 'red')
                    ->whereBetween('price', [50, 100])
            )
            ->isBlue()
            ->externalScope('inStock')
            ->externalScope('availableFrom', 'tomorrow')
            ->externalScope('availableIn', [
                'New York',
                'Boston',
            ]);
    }

    public function checkBaseQuery(Request $request, $wrapQueryParams = false)
    {
        $paramsKey = $wrapQueryParams
            ? 'params.'
            : '';

        $data = $request->data();

        return str_contains($request->url(), 'http://laravel-api-model-example.test/api/model/items')
        && data_get($data, $paramsKey . 'filter.0:and:price:gt') == 100
        && data_get($data, $paramsKey . 'filter.0:and:color:e') == 'blue'
        && data_get($data, $paramsKey . 'filter.0:or:name:like') == 'test%'
        && data_get($data, $paramsKey . 'filter.1:and:color:e') == 'red'
        && data_get($data, $paramsKey . 'filter.1:and:price:lt') == 100
        && data_get($data, $paramsKey . 'filter.1:and:price:gt') == 50
        && data_get($data, $paramsKey . 'filter.0:and:inStock:scope') == ''
        && data_get($data, $paramsKey . 'filter.0:and:availableFrom:scope') == 'tomorrow'
        && data_get($data, $paramsKey . 'filter.0:and:availableIn:scope') == 'New York,Boston'
        && data_get($data, $paramsKey . 'nested') == 'and,0:or';
    }
}