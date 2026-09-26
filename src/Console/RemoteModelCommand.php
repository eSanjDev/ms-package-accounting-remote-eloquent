<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Console;

use Esanj\RemoteEloquent\Schema\FieldDefinition;
use Esanj\RemoteEloquent\Schema\ResourceSchema;
use Esanj\RemoteEloquent\Schema\SchemaRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Write a model for a remote resource, once, from the schema the server publishes.
 */
final class RemoteModelCommand extends Command
{
    /**
     * Contract type -> Eloquent cast.
     */
    private const CASTS = [
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'int' => 'integer',
        'integer' => 'integer',
        'bigint' => 'integer',
        'float' => 'float',
        'double' => 'float',
        'datetime' => 'datetime',
        'timestamp' => 'datetime',
        'date' => 'date',
        'array' => 'array',
        'json' => 'array',
        'object' => 'array',
        'collection' => 'collection',
    ];

    /**
     * Contract type -> the type an @property line should carry.
     */
    private const PHP_TYPES = [
        'bool' => 'bool',
        'boolean' => 'bool',
        'int' => 'int',
        'integer' => 'int',
        'bigint' => 'int',
        'float' => 'float',
        'double' => 'float',
        'decimal' => 'string',
        'datetime' => '\Illuminate\Support\Carbon',
        'timestamp' => '\Illuminate\Support\Carbon',
        'date' => '\Illuminate\Support\Carbon',
        'array' => 'array',
        'json' => 'array',
        'object' => 'array',
        'collection' => '\Illuminate\Support\Collection',
        'string' => 'string',
        'uuid' => 'string',
        'ulid' => 'string',
        'email' => 'string',
        'text' => 'string',
    ];

    protected $signature = 'remote:model
                            {resource : The remote resource, for example "users"}
                            {--model= : The class name to write, by default the singular studly resource}
                            {--namespace= : The namespace to write it in, by default App\\Models}
                            {--path= : The file to write, by default app/Models/{Model}.php}
                            {--auth : Extend ApiUser, for the model behind the auth guard}';

    protected $description = 'Generate a model for a remote resource from the schema the account service publishes.';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $resource = trim((string) $this->argument('resource'));

        if ($resource === '') {
            $this->error('Name the resource: php artisan remote:model users');

            return self::FAILURE;
        }

        try {
            $schemas = $this->laravel->make(SchemaRepository::class);
        } catch (Throwable $exception) {
            $this->error('The schema repository could not be built: ' . $exception->getMessage());
            $this->line('  Run <comment>php artisan remote:doctor</comment> to check the driver, the base URL and the credentials.');

            return self::FAILURE;
        }

        $schemas->forget($resource);

        $schema = $schemas->for($resource);

        if ($schema === null) {
            $this->error(sprintf('The schema for "%s" could not be read, so there is nothing to generate from.', $resource));
            $this->line('  Run <comment>php artisan remote:doctor</comment> to check the base URL and the credentials.');

            return self::FAILURE;
        }

        $class = $this->className($resource);
        $namespace = $this->namespace();
        $path = $this->targetPath($class);

        if ($this->files->exists($path)) {
            $this->error(sprintf('%s already exists.', $this->relative($path)));
            $this->line('  A generated model would take its relations, scopes and accessors with it, so this command never overwrites.');
            $this->line('  To see how it has drifted: <comment>php artisan remote:schema ' . $resource . ' --diff</comment>');
            $this->line('  To generate a second one: <comment>--model=' . $class . 'Contract</comment> or <comment>--path=...</comment>');

            return self::FAILURE;
        }

        $directory = dirname($path);

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $this->files->put($path, $this->render($schema, $resource, $namespace, $class));

        $this->newLine();
        $this->line('  <fg=green>v</> <options=bold>' . $namespace . '\\' . $class . '</> written to ' . $this->relative($path));
        $this->line('    from <comment>' . $schema->versionTag() . '</comment>, '
            . count($schema->fields()) . ' field(s), '
            . count($this->writableFields($schema)) . ' writable');

        if ($schema->actions() !== []) {
            $this->line('    actions available: <comment>' . implode(', ', $schema->actions()) . '</comment>'
                . ' - call them with $model->remoteAction(\'...\')');
        }

        $this->line('    keep it honest in CI: <comment>php artisan remote:schema ' . $resource . ' --diff</comment>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function render(ResourceSchema $schema, string $resource, string $namespace, string $class): string
    {
        $auth = (bool) $this->option('auth');
        $parent = $auth ? 'ApiUser' : 'ApiModel';
        $softDeletes = $schema->softDeletes();

        $imports = [$auth
            ? 'Esanj\RemoteEloquent\Models\ApiUser'
            : 'Esanj\RemoteEloquent\Models\ApiModel'];

        if ($softDeletes) {
            $imports[] = 'Esanj\RemoteEloquent\Concerns\RemoteSoftDeletes';
        }

        sort($imports);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace ' . $namespace . ';',
            '',
        ];

        foreach ($imports as $import) {
            $lines[] = 'use ' . $import . ';';
        }

        $lines[] = '';

        foreach ($this->docBlock($schema, $resource) as $line) {
            $lines[] = $line;
        }

        $lines[] = 'class ' . $class . ' extends ' . $parent;
        $lines[] = '{';

        if ($softDeletes) {
            $lines[] = '    use RemoteSoftDeletes;';
            $lines[] = '';
        }

        $lines[] = '    protected string $resource = ' . var_export($resource, true) . ';';
        $lines[] = '';
        $lines[] = '    protected $primaryKey = ' . var_export($schema->key(), true) . ';';
        $lines[] = '';

        $keyIsInt = in_array(strtolower($schema->keyType()), ['int', 'integer', 'bigint'], true);

        $lines[] = '    protected $keyType = \'' . ($keyIsInt ? 'int' : 'string') . '\';';
        $lines[] = '';

        if (! $keyIsInt) {
            $lines[] = '    public $incrementing = false;';
            $lines[] = '';
        }

        $lines[] = '    protected $fillable = [';

        foreach ($this->writableFields($schema) as $field) {
            $lines[] = '        ' . var_export($field, true) . ',';
        }

        $lines[] = '    ];';

        $casts = $this->casts($schema);

        if ($casts !== []) {
            $lines[] = '';
            $lines[] = '    protected $casts = [';

            foreach ($casts as $field => $cast) {
                $lines[] = '        ' . var_export($field, true) . ' => ' . var_export($cast, true) . ',';
            }

            $lines[] = '    ];';
        }

        $lines[] = '}';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return list<string>
     */
    private function docBlock(ResourceSchema $schema, string $resource): array
    {
        $lines = [
            '/**',
            ' * ' . $this->docText($schema->versionTag() !== '' ? $schema->versionTag() : $resource)
                . ' - generated from the remote schema by `php artisan remote:model ' . $this->docText($resource) . '`.',
            ' *',
            ' * This file is not regenerated. Once it carries a relation or a scope, the',
            ' * schema no longer describes all of it; `php artisan remote:schema ' . $this->docText($resource) . ' --diff`',
            ' * reports where the two have drifted apart.',
            ' *',
        ];

        foreach ($schema->fields() as $name => $field) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name) !== 1) {
                continue;
            }

            $lines[] = ' * @property ' . $this->propertyType($field) . ' $' . $name
                . ($field->isDeprecated()
                    ? '  deprecated' . ($field->deprecation() === null ? '' : ': ' . $this->docText($field->deprecation()))
                    : '');
        }

        if ($schema->includes() !== []) {
            $lines[] = ' *';
            $lines[] = ' * Includes published by the server: ' . $this->docText(implode(', ', $schema->includes()));
        }

        if ($schema->actions() !== []) {
            $lines[] = ' *';
            $lines[] = ' * Actions: $model->remoteAction(' . $this->docText(var_export($schema->actions()[0], true)) . ', [...])';

            foreach ($schema->actions() as $action) {
                $lines[] = ' *   - ' . $this->docText($action);
            }
        }

        $lines[] = ' */';

        return $lines;
    }

    /**
     * Schema text inside a docblock: it can neither close the comment nor start a new line.
     */
    private function docText(string $text): string
    {
        return str_replace(['*/', "\r", "\n"], ['* /', ' ', ' '], $text);
    }

    private function propertyType(FieldDefinition $field): string
    {
        $type = self::PHP_TYPES[strtolower($field->type())] ?? 'mixed';

        return $field->isNullable() && $type !== 'mixed' ? $type . '|null' : $type;
    }

    /**
     * @return list<string>
     */
    private function writableFields(ResourceSchema $schema): array
    {
        $writable = [];

        foreach ($schema->fields() as $name => $field) {
            if (in_array($name, ['created_at', 'updated_at', 'deleted_at', $schema->key()], true)) {
                continue;
            }

            if ($field->isWritableOn('create') || $field->isWritableOn('update')) {
                $writable[] = (string) $name;
            }
        }

        return $writable;
    }

    /**
     * @return array<string, string>
     */
    private function casts(ResourceSchema $schema): array
    {
        $casts = [];

        foreach ($schema->fields() as $name => $field) {
            $cast = $this->castFor($field);

            if ($cast !== null) {
                $casts[(string) $name] = $cast;
            }
        }

        return $casts;
    }

    private function castFor(FieldDefinition $field): ?string
    {
        $type = strtolower($field->type());

        if ($type === 'decimal') {
            return $field->scale() === null ? 'string' : 'decimal:' . $field->scale();
        }

        return self::CASTS[$type] ?? null;
    }

    private function className(string $resource): string
    {
        $option = trim((string) $this->option('model'));

        if ($option !== '') {
            return Str::studly(str_replace('/', '\\', $option));
        }

        return Str::studly(Str::singular($resource));
    }

    private function namespace(): string
    {
        $option = trim((string) $this->option('namespace'));

        if ($option !== '') {
            return trim($option, '\\');
        }

        return trim($this->appNamespace(), '\\') . '\\Models';
    }

    private function targetPath(string $class): string
    {
        $option = trim((string) $this->option('path'));

        if ($option !== '') {
            return $this->isAbsolute($option) ? $option : $this->basePath() . DIRECTORY_SEPARATOR . $option;
        }

        $app = $this->laravel;

        $directory = is_object($app) && method_exists($app, 'path')
            ? (string) $app->path('Models')
            : $this->basePath() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models';

        return $directory . DIRECTORY_SEPARATOR . $class . '.php';
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function basePath(): string
    {
        $app = $this->laravel;

        return is_object($app) && method_exists($app, 'basePath') ? (string) $app->basePath() : (string) getcwd();
    }

    private function relative(string $path): string
    {
        $base = $this->basePath();

        return str_starts_with($path, $base)
            ? ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR . '/')
            : $path;
    }

    private function appNamespace(): string
    {
        $app = $this->laravel;

        if (is_object($app) && method_exists($app, 'getNamespace')) {
            try {
                return (string) $app->getNamespace();
            } catch (Throwable) {
                return 'App\\';
            }
        }

        return 'App\\';
    }
}
