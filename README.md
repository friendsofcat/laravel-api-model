# laravel-api-model

Ability to use Model from third-party Laravel app via API with eloquent support.

Goes hand in hand with [Laravel API model server package.](https://github.com/friendsofcat/laravel-api-model-server)
<br></br>
## Supported features:

### Use your model like you would normally do:

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
 

### Supported where types (easily extended):
```
Basic
Column
In, InRaw
NotIn, NotInRaw
Between
Null
NotNull
Date
Day
Time
Year
FullText
Nested
Exists
NotExists
Raw
```

### Local / Global Scopes


### External scopes (server-defined scope)
```PHP
User::externalScope('isActive')
```

### Eager loading of external relations
```PHP
User::with([
  'details',        // relation defined in our local model
  'undefined',      // relation NOT defined in our local model
  'external',       // relation defined in our local model but related to model with same API connection 
])

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

### Another features:
- Support for `::insert()`, `::create()`, `->save()`

- Support for `->update()`

- Support for `->delete()`

- Multiple orderBy

- Aggregates (i.e. `->count()`, `->avg('price')`...)

- Support for `->exists()`

- in case of client / server timezone differences, dateType values are converted

- Configurable as a database connection so ability to use multiple servers for different models

- paginator - wip

- Access token authorization support

---
```
By default there is a limit of 2048 characters for query string (can be overridden via config)
```
