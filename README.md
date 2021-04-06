# laravel-filtering

The package allows you to make model filter by user request in 1 line, you just need to use the MarksIhor\LaravelFiltering\Filterable trait in your Controller.php (or whatever your controller you need the filter to work).

Since 2.00 filter by model relationships.

## Installing

```shell
$ composer require marksihor/laravel-filtering -vvv
```

## Usage

### Use the Filterable trait in your Controller

```php
<?php

namespace App\Http\Controllers;

use MarksIhor\LaravelFiltering\Filterable;

class Controller
{
    use Filterable;
}
```

### Usage examples (in Controller)

```php

$collection = $this->filter(Model::query())->get(); // give user ability to filter model without any constraints
$collection = $this->filter(Model::where('price', 100))->get(); // predefined filter that user cannot override in request parameters
$collection = $this->rawParams(['status' => 'paid'])->filter(Model::query())->get(); // also a way to predefine parameters that user cannot override
$collection = $this->filterable(['status','paid'])->filter(Model::query())->get(); // allows you to define columns that user can filter

```

### Usage examples (in Model)

```php

public static $filterable = []; // to define columns that you will be able to filter (not required)

public static $filterableRelations = []; // to define relations that you will be able to filter (otherwise it won't work)

public static $filterablePivot = [
        'role_id' => 'sites',
    ]; // key = key, value = relation name (to filter by pivot table columns, otherwise it won't work)
```

### Usage examples (in url)

```http request
https://yoursite.com/path?status=active
https://yoursite.com/path?price=100,75
https://yoursite.com/path?price=100,75&status=paid
https://yoursite.com/path?price[from]=100&price[to]=500
https://yoursite.com/path?price[orderBy]=asc
https://yoursite.com/path?with=metas,tags
https://yoursite.com/path?data->key=value // to filter json column
https://yoursite.com/path?data__key=value // to filter json column
https://yoursite.com/path?users[id]=1 // to filter relationships
https://yoursite.com/path?select[accounts]=id,user_id,link // to select column from related table when use ?with=accounts, note that the relational column should be in the list
https://yoursite.com/path?select[current_model_table_name]=id,name // to select column from curent model
https://yoursite.com/path?deleted=1 // get only softDeleted records
https://yoursite.com/path?withCount=relationName // get count of specified relation
https://yoursite.com/path?has=relationName // get only records that has specified relations (1 or more)
https://yoursite.com/path?column1=null&column2=notNull // for null and not null values
https://yoursite.com/path?tags[name]=tagname // to filter by relationship, the model should have public static $filterableRelations = ['tags'];
https://yoursite.com/path?order=asc&orderBy=column // new ordering way
```

As you can see you can combine parameters in the request (order of parameters is doesn't matter).

## Release notes

2.00 - model relationships filtering.

## License

MIT
