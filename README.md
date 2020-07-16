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

public $filterable = []; // to define columns that you wil be able to filter (not required)

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
```

As you can see you can combine parameters in the request (order of parameters is doesn't matter).

## Release notes

2.00 - model relationships filtering.

## License

MIT