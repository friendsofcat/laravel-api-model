# laravel-api-model

Ability to use Model from third-party Laravel app via API with eloquent support.
<br>
Transfers all model actions to API calls.

Goes hand in hand with [Laravel API model server package.](https://github.com/friendsofcat/laravel-api-model-server)


## Usage
#### Define API database connection
```PHP
// config/database.php

'api_connection' => [
    'driver' => 'laravel_api_model',
    'database' => 'YOUR_ENDPOINT_URL', // https://example.com/api/model
    
    // If your API doesn't require any authentication you can remove 'auth' from your config
    'auth' => [
        'type' => 'oauth', // package supports 'oauth' and 'passport_client_credentials'
        'url' => 'YOUR_AUTH_URL',
        'client_id' => '',
        'client_secret' => '',
    ],
    
    // Headers you want to append to every request (except auth requests)
    'headers' => [
         // ...
    ],

    'array_value_separator' => ',',
    'max_url_length' => 2048,  // default: 2048
],
```
<br>

#### Create API model and set connection
```PHP
use FriendsOfCat\LaravelApiModel\Models\ApiModel

class User extends ApiModel
{
    protected $connection = 'api_connection';
    

}
```
<br>

#### Note some not fully supported features:<br>
- cross database relations (with usage of `whereHas(...)`, eager-loads are supported)
- JOIN
- upsert
- paginator - wip

# Supported features

## Retrieving data
You can use complex queries (although nested logic is not currently supported by `laravel-api-model-server`) while retrieving, updating or deleting data.
### Get method
```PHP
User::isActive()
    ->where(function($q){
      $q->where('id', '>', 1)
      ->orWhere(function ($q) {
          $q->whereId(0)
          ->orWhereIn('created_at', [3,4,5]);
      })
      ->whereIn('updated_at', [1,2,3,4,5]);
})
->orWhere(function($q) {
    $q->where('id', 3)->where(function($q){
        $q->whereDeletedAt(2)
        ->orWhereNull('type');
    })
    ->where('updated_at', 'like', '%2012%');
})
->where('type', 1)
->get()
```
Transforms to request (GET):
```
https:://example.com/api/model/users?filter[0:and:active:e]=1&filter[0:and:id:gt]=1&filter[1:and:id:e]=0&filter[1:or:created_at:in]=3%2C4%2C5&nested=and%2C0%3Aor%2C0%3Aor%2C2%3Aand&filter[0:and:updated_at:in]=1%2C2%2C3%2C4%2C5&filter[2:and:id:e]=3&filter[3:and:deleted_at:e]=2&filter[3:or:type:is_null]=1&filter[0:and:updated_at:like]=%252012%25&filter[0:and:type:e]=1
```
Example response data:

```javascript
[
  {
    "id": 1,
    "type": 1,
    "created_at": "2012-10-13T17:55:16",
    "updated_at": "2012-10-13T17:55:16"
  },
  {
    "id": 2,
    "type": 1,
    "created_at": "2012-10-13T18:55:16",
    "updated_at": "2012-10-13T18:55:16"
  }
]
```
Result is eloquent model collection, same as using traditional, non API, approach.

<br>

### First / Find method
```PHP
User::where('id', '=', 1)->first();
// OR
User::find(1);
```
Transforms to request (GET):
```
https:://example.com/api/model/users?filter[and:id:e]=1&limit=1
```
Example response data:

```javascript
[
  {
    "id": 1,
    "name": "Mark",
    "created_at": "2012-10-13T17:55:16",
    "updated_at": "2012-10-13T17:55:16"
  }
]
```
Result is eloquent model, same as using traditional, non API, approach.

<br>

### Aggregates
```PHP
User::count();
User::avg('age');
User::min('age');
User::max('age');
// you can use complex queries with any aggregate function
User::where('created_at', '>', now()->subDays(7))->sum('credit');
```
Transforms to request (GET):
```
https:://example.com/api/model/users?filter[and:created_at:gt]=2012-10-13T17:55:16&queryType=sum,credit
```
Example response data:

```javascript
[
  {
    "aggregate": 1000
  }
]
```
Eloquent expects the result to be in the format showed in an example response.<br>
Return value is the value of `aggregate` key from the response.
```PHP
// Example:
$totalCredit = User::where('created_at', '>', now()->subDays(7))->sum('credit');

$totalCredit === 1000 // true
```
<br>

### Checking for existence
```PHP
User::where('created_at', '>', now()->subDays(7))->exists();
```
Transforms to request (GET):
```
https:://example.com/api/model/users?filter[and:created_at:gt]=2012-10-13T17:55:16&queryType=exists
```
Example response data:

```javascript
[
  {
    "exists": true
  }
]
```
Eloquent expects the result to be in the format showed in an example response.<br>
Return value is the value of `exists` key from the response.
```PHP
// Example:
$totalCredit = User::where('created_at', '>', now()->subDays(7))->exists();

$totalCredit === true // true
```
<br>

## Local / Global Scopes
You can use local or global scopes like you normally do.

<br>

## External scopes (server-defined scope)
Custom functionality especially useful when server does not wish to expose its underlying logic.<br>
If the server is using `laravel-api-model-server` package, and has allowed API to use requested scope,
it will be executed on server side.
```PHP
User::externalScope('isActive')->first()
```
Transforms to request (GET):
```
https:://example.com/api/model/users?filter[and:isActive:scope]=&limit=1
```

### External scope with arguments:
```PHP
User::externalScope('isFrom', ['New York', 'Boston'])->first()
```
Transforms to request (GET):
```
https:://example.com/api/model/users?filter[and:isFrom:scope]=New+York%2CBoston&limit=1
```
Example response data:

```javascript
[
  {
    "id": 1,
    "name": "Mark",
    "created_at": "2012-10-13T17:55:16",
    "updated_at": "2012-10-13T17:55:16"
  }
]
```
<br>

## Eager loading of external relations
```PHP
User::with([
  'details',        // relation defined in our local model
  'undefined',      // relation NOT defined in our local model
  'external',       // relation defined in our local model but related to model with same API connection 
])->get()

/*
Every relation passed down to with() is inspected prior to query execution.

a) If relation is not set on our model, we assume it is external and we pass it with the query to server
   and it is excluded from default eager loading.

b) If relation is defined AND has same database connection as our API model,
   we pass it with the query to server and it is excluded from default eager loading.

From our above example, 'undefined' and 'external' are passed down to new externalWith() method.
Original with() is modified to hold only 'details' to ensure correct default eager loading.
*/
```
Transforms to request (GET):
```
https:://example.com/api/model/users?include=undefined,external
```
Example response data:

```javascript
[
  {
    "id": 1,
    "type": 1,
    "undefined": {
      "id": 1,
      "title": "test"
    },
    "external": [
      {
        "id": 1,
        "title": "test"
      },
      {
        "id": 2,
        "title": "test"
      }
    ],
    "created_at": "2012-10-13T17:55:16",
    "updated_at": "2012-10-13T17:55:16"
  },
  
  // ...
]
```


After receiving response, default eager loading take place, so query to receive `details` is executed.<br><br>
As `undefined` was not defined on our end, but we receive data with key 'undefined' in response, it will be mixed with other attributes and can cause problems while saving (if `UPDATE / CREATE` is allowed in API).<br><br>
On the other hand, `external` is defined on our end, and it is relation to another API model. This will be hydrated properly alongside other relations as proper eloquent collection in `related` bucket.

<br>

## Creating and updating
### Insert method
```PHP
User::insert([
    ['name' => 'Mark'],
    ['name' => 'Jason'],
])
```
Transforms to request (POST):

```javascript
// https:://example.com/api/model/users
// --------------------------------------
// Data:

{
  "getId": false,
  "values": [
    {
      "name": "Mark",
      "created_at": "2012-10-13T17:55:16",
      "updated_at": "2012-10-13T17:55:16"
    },
    {
      "name": "Jason",
      "created_at": "2012-10-13T17:55:16",
      "updated_at": "2012-10-13T17:55:16"
    }
  ]
}

```
Example response data:

```javascript
{
  "bool": true
}
```
Possible values of `bool`:<br>
`true` => models were successfully created<br>
`false` => models were not created<br><br>
This `Boolean` value is expected by model instance and is returned as a result of insert operation.

```PHP
// Example:
$users = User::insert([
    ['name' => 'Mark'],
    ['name' => 'Jason'],
]);

$users === true // true
```
<br>

### Create method / using `save()` for creation
```PHP
$user = new User();
$user->name = 'Mark';
$user->save();

// OR

$user = User::create(['name' => 'Mark']);
```
Transforms to request (POST):

```javascript
// https:://example.com/api/model/users
// --------------------------------------
// Data:

{
  "getId": true,
  "values": [
    {
      "name": "Mark",
      "created_at": "2012-10-13T17:55:16",
      "updated_at": "2012-10-13T17:55:16"
    }
  ]
}

```
Example response data:

```javascript
{
  "id": 1
}
```
Possible values of `id`:<br>
`mixed` => primary key value of newly created instance (real column doesn't need to be called 'id')<br>
`false` => model was not created<br>

The value of `id` is hydrated to your existing model instance as a value of a primary key.
```PHP
// Example:
$user = new User();
$user->name = 'Mark';
$user->save();
// OR
$user = User::create(['name' => 'Mark']);

$user->id === 1 // true
```
<br>

### Update method
```PHP
User::where('created_at', '>', now()->subDays(7))->update(['name' => 'Mark']);
```
Transforms to request (PUT):

```javascript
// https:://example.com/api/model/users
// --------------------------------------
// Data:

{
  "params": {
    "filter": {
      "and:created_at:gt": "2012-10-13T17:55:16"
    }
  },
  "data": {
    "name": "Mark"
  }
}

```
Example response data:

```javascript
42
```
It returns an integer that represents the number of updated entries.

```PHP
// Example:
$numberOfUpdatedUsers = User::where('created_at', '>', now()->subDays(7))->update(['name' => 'Mark']);

$numberOfUpdatedUsers === 42 // true
```
<br>

### Usage of `save()` for update
```PHP
// Example:
$user = User::find(1);
$user->name = 'Mark';
$user->save();
```
Transforms to request (PUT):

```javascript
// https:://example.com/api/model/users
// --------------------------------------
// Data:

{
  "params": {
    "filter": {
      "and:id:e": 1
    }
  },
  "data": {
    "name": "Mark"
  }
}

```
## Deleting
```PHP
$numberOfDeletedUsers = User::where('created_at', '>', now()->subDays(7))->delete();
```
Transforms to request (DELETE):

```javascript
// https:://example.com/api/model/users
// --------------------------------------
// Data:

{
  "filter": {
    "and:created_at:gt": "2012-10-13T17:55:16"
  }
}

```
Example response data:

```javascript
42
```
It returns an integer that represents the number of deleted entries.

```PHP
// Example:
$numberOfDeletedUsers = User::where('created_at', '>', now()->subDays(7))->delete();

$numberOfDeletedUsers === 42 // true
```
