# RemoteEloquent

A Laravel package that extends the Eloquent ORM to seamlessly interact with remote APIs (REST and gRPC) as if they were
local databases.

## Features

- **REST & gRPC Support:** Use Eloquent models to communicate with remote RESTful or gRPC APIs.
- **Familiar Eloquent Syntax:** Continue using Eloquent's expressive syntax for remote data sources.
- **Easy Integration:** Plug-and-play with Laravel 9, 10, and 11.
- **Extensible & Configurable:** Easily customize endpoints, authentication, and serialization.

## Requirements

- PHP >= 8.0
- Laravel 9.x, 10.x, or 11.x, or 12.x
- guzzlehttp/guzzle ^7.0
- illuminate/support ^9.0|^10.0|^11.0|^12.x

## Installation

```bash
composer require esanj/remote-eloquent
```

## Configuration

Publish the package configuration (if available):

```bash
php artisan vendor:publish --provider="Esanj\\RemoteEloquent\\Providers\\RemoteEloquentServiceProvider"
```

Edit the configuration file in `config/remote-eloquent.php` to set your API endpoints, authentication, and other
options.

## Usage

### Creating a Remote Model

Extend your model from `Esanj\RemoteEloquent\RemoteModel` instead of the default Eloquent Model:

```php
use Esanj\RemoteEloquent\RemoteModel;

class UserModel extends RemoteModel
{
     public string $address = '127.0.0.1:50051';
     
     protected string $clientType = 'grpc';
}
```

### Querying Remote Data

```php
// Paginate Users List
$users = UserModel::where('is_active',1)->where('age', '>', 10)->paginate();

// Find a user by ID
$user = UserModel::find(1);
```

## 🧩 Supported Methods

`RemoteEloquent` supports commonly used Eloquent query builder methods for interacting with remote gRPC APIs.

| Method | Description |
|:--------|:-------------|
| `where` | Apply a basic “where” condition to the query. |
| `whereIn` | Filter results where a column’s value is within a given array of values. |
| `whereBetween` | Filter results between two values for a specific column. |
| `find` | Retrieve a model by its primary key. |
| `findOrFail` | Retrieve a model by its primary key or throw an exception if not found. |
| `get` | Execute the query and return all matching records as a collection. |
| `paginate` | Retrieve paginated results from the remote API. |
| `count` | Retrieve the total number of matching records. |
| `avg` | Calculate the average of a given column. |
| `sum` | Calculate the sum of a given column. |

> 🔧 *More Eloquent query methods are planned for future releases.*


## License

MIT © Esanj 
