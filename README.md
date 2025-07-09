# RemoteEloquent

A Laravel package that extends the Eloquent ORM to seamlessly interact with remote APIs (REST and gRPC) as if they were local databases.

## Features
- **REST & gRPC Support:** Use Eloquent models to communicate with remote RESTful or gRPC APIs.
- **Familiar Eloquent Syntax:** Continue using Eloquent's expressive syntax for remote data sources.
- **Easy Integration:** Plug-and-play with Laravel 9, 10, and 11.
- **Extensible & Configurable:** Easily customize endpoints, authentication, and serialization.

## Requirements
- PHP >= 8.0
- Laravel 9.x, 10.x, or 11.x
- guzzlehttp/guzzle ^7.0
- illuminate/support ^9.0|^10.0|^11.0

## Installation

```bash
composer require esanj/remote-eloquent
```

## Configuration

Publish the package configuration (if available):

```bash
php artisan vendor:publish --provider="Esanj\\RemoteEloquent\\Providers\\RemoteEloquentServiceProvider"
```

Edit the configuration file in `config/remote-eloquent.php` to set your API endpoints, authentication, and other options.

## Usage

### Creating a Remote Model

Extend your model from `Esanj\RemoteEloquent\RemoteModel` instead of the default Eloquent Model:

```php
use Esanj\RemoteEloquent\RemoteModel;

class UserModel extends RemoteModel
{
    // Define table, fillable, etc.
    protected $fillable = ['first_name', 'last_name', 'age'];
}
```

### Querying Remote Data

```php
// Paginate Users List
$users = UserModel::where('is_active',1)->where('age', '>', 10)->paginate();

// Find a user by ID
$user = UserModel::find(1);

```

## License

MIT © Esanj 