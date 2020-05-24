# laravel-filtering

The package allows you to make model filter by user request in 1 line, you just need to use the MarksIhor\LaravelFiltering\Filterable trait in your Controller.php (or whatever your controller you need the filter to work).


## Usage

### Use trait on Controller

```php
<?php

namespace App\Http\Controllers;

use MarksIhor\LaravelMessaging\Filterable;

class User extends Authenticatable
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

public $filterable = []; // to define columns that use wil be able to filter

```

### Usage examples (in url)

```http request
https://yoursite.com/path?status=active
https://yoursite.com/path?price=100,75
https://yoursite.com/path?price=100,75&status=paid
https://yoursite.com/path?price[from]=100&price[to]=500
https://yoursite.com/path?price[orderBy]=asc
```

As you can see you can combine parameters in the request (order of parameters is doesn't matter).

## License

MIT