<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Console;

use Esanj\RemoteEloquent\Models\ApiModel;
use Esanj\RemoteEloquent\Schema\ResourceSchema;
use Esanj\RemoteEloquent\Schema\SchemaRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * What the account service says a resource is — and, with --diff, where the local model disagrees with it.
 */
final class RemoteSchemaCommand extends Command
{
    /**
     * Types that need a cast for the attribute to come back as the contract describes it.
     */
    private const EXPECTED_CASTS = [
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'int' => 'integer',
        'integer' => 'integer',
        'float' => 'float',
        'double' => 'float',
        'decimal' => 'decimal:2',
        'datetime' => 'datetime',
        'timestamp' => 'datetime',
        'date' => 'date',
        'array' => 'array',
        'json' => 'array',
        'object' => 'array',
        'collection' => 'collection',
    ];

    /**
     * Contract types that are text.
     */
    private const TEXT_TYPES = ['string', 'text', 'uuid', 'ulid', 'email'];

    /**
     * Cast families this command is willing to have an opinion about.
     */
    private const KNOWN_FAMILIES = [
        'boolean', 'integer', 'float', 'decimal', 'datetime', 'date', 'array', 'collection', 'string',
    ];

    protected $signature = 'remote:schema
                            {resource : The remote resource, for example "users"}
                            {--diff : Compare the contract with the local model and fail on a mismatch}
                            {--model= : The model class to diff against, when it cannot be found by its resource}';

    protected $description = 'Print the account service schema for a resource, or diff it against the local model.';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $resource = trim((string) $this->argument('resource'));

        if ($resource === '') {
            $this->error('Name the resource: php artisan remote:schema users');

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
            $this->error(sprintf('The schema for "%s" could not be read.', $resource));
            $this->line('  The endpoint is GET {base_url}/api/remote/v1/' . $resource . '/schema.');
            $this->line('  Run <comment>php artisan remote:doctor</comment> to check the base URL, the credentials and the TLS setup.');

            return self::FAILURE;
        }

        return $this->option('diff')
            ? $this->diff($resource, $schema)
            : $this->describe($schema);
    }

    private function describe(ResourceSchema $schema): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>' . $schema->versionTag() . '</>');
        $this->line('  key: <comment>' . $schema->key() . '</comment> (' . $schema->keyType() . ')'
            . '   soft deletes: <comment>' . ($schema->softDeletes() ? 'yes' : 'no') . '</comment>');

        if ($schema->defaultFields() !== []) {
            $this->line('  default projection: <comment>' . implode(', ', $schema->defaultFields()) . '</comment>');
        }

        $this->newLine();

        $rows = [];

        foreach ($schema->fields() as $name => $field) {
            $rows[] = [
                $name . ($field->isDeprecated() ? ' *' : ''),
                $field->type() . ($field->isNullable() ? '?' : ''),
                $field->constrainsOperators() ? implode(' ', $field->operators()) : 'any',
                $field->isSortable() ? 'yes' : 'no',
                $this->describeCapability($field->writableOn(), $field->isWritableOn('create')),
                $this->describeCapability($field->aggregates(), $field->allowsAggregate('count')),
            ];
        }

        if ($rows === []) {
            $this->warn('  The schema publishes no fields.');
        } else {
            $this->table(['field', 'type', 'filters', 'sort', 'write', 'aggregate'], $rows);
        }

        foreach ($schema->fields() as $name => $field) {
            if ($field->isDeprecated()) {
                $this->line('  <fg=yellow>*</> ' . $name . ' is deprecated'
                    . ($field->deprecation() !== null ? ' - ' . $field->deprecation() : ''));
            }
        }

        if ($schema->publishesRelations()) {
            $this->newLine();
            $this->line('  <options=bold>relations</>');

            $relations = [];

            foreach ($schema->relations() as $name => $definition) {
                $relations[] = [
                    $name,
                    $schema->relationResource($name) ?? '-',
                    $schema->supportsHas($name) ? 'yes' : 'no',
                    $schema->supportsCount($name) ? 'yes' : 'no',
                    in_array($name, $schema->includes(), true) ? 'yes' : 'no',
                ];
            }

            $this->table(['relation', 'resource', 'has', 'count', 'include'], $relations);
        } elseif ($schema->publishesIncludes()) {
            $this->line('  includes: <comment>' . implode(', ', $schema->includes()) . '</comment>');
        }

        if ($schema->actions() !== []) {
            $this->newLine();
            $this->line('  <options=bold>actions</>   POST ' . $schema->name() . '/{id}/actions/{action}');

            foreach ($schema->actions() as $action) {
                $this->line('    - ' . $action);
            }
        }

        if ($schema->limits() !== []) {
            $this->newLine();
            $this->line('  <options=bold>limits</>');

            foreach ($schema->limits() as $limit => $value) {
                $this->line('    ' . $limit . ': ' . $value);
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $published
     */
    private function describeCapability(array $published, bool $allowsAnything): string
    {
        if ($published !== []) {
            return implode(' ', $published);
        }

        return $allowsAnything ? 'any' : '-';
    }

    private function diff(string $resource, ResourceSchema $schema): int
    {
        $class = $this->resolveModel($resource);

        if ($class === null) {
            $this->error(sprintf('No ApiModel maps to "%s".', $resource));
            $this->line('  Point at one with <comment>--model="App\\Models\\User"</comment>, or set <comment>protected string $resource = \'' . $resource . '\';</comment> on it.');

            return self::FAILURE;
        }

        try {
            /** @var ApiModel $model */
            $model = new $class;
        } catch (Throwable $exception) {
            $this->error(sprintf('%s could not be constructed: %s', $class, $exception->getMessage()));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>' . $schema->versionTag() . '</> <-> <options=bold>' . $class . '</>');

        $errors = [];
        $warnings = [];
        $matches = 0;

        $this->diffKey($model, $schema, $errors);
        $this->diffSoftDeletes($model, $schema, $errors, $warnings);
        $matches += $this->diffFillable($model, $schema, $errors, $warnings);
        $this->diffCasts($model, $schema, $warnings);

        foreach ($errors as $line) {
            $this->line('    <fg=red>x</> ' . $line);
        }

        foreach ($warnings as $line) {
            $this->line('    <fg=yellow>!</> ' . $line);
        }

        if ($matches > 0) {
            $this->line(sprintf('    <fg=green>v</> %d field%s match', $matches, $matches === 1 ? '' : 's'));
        }

        if ($errors === [] && $warnings === [] && $matches === 0) {
            $this->line('    <fg=yellow>!</> the model declares no $fillable, so there is nothing to compare');
        }

        $this->newLine();

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function diffFillable(ApiModel $model, ResourceSchema $schema, array &$errors, array &$warnings): int
    {
        $matches = 0;

        foreach ($model->getFillable() as $field) {
            if ($field === '*') {
                continue;
            }

            $definition = $schema->field($field);

            if ($definition === null) {
                $suggestion = $this->suggest($field, $schema->fieldNames());

                $errors[] = sprintf(
                    '$fillable contains "%s" - no such field in the contract%s',
                    $field,
                    $suggestion === '' ? '' : ' (did you mean ' . $suggestion . '?)',
                );

                continue;
            }

            if (! $definition->isWritableOn('create') && ! $definition->isWritableOn('update')) {
                $action = $this->actionFor($field, $schema->actions());

                $errors[] = sprintf(
                    '$fillable contains "%s" - not writable%s',
                    $field,
                    $action === null ? '' : '; use the ' . $action . ' action',
                );

                continue;
            }

            if ($definition->isDeprecated()) {
                $warnings[] = sprintf(
                    '"%s" is deprecated%s',
                    $field,
                    $definition->deprecation() === null ? '' : ' - ' . $definition->deprecation(),
                );
            }

            $matches++;
        }

        return $matches;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function diffCasts(ApiModel $model, ResourceSchema $schema, array &$warnings): void
    {
        $casts = $model->getCasts();
        $visible = array_unique(array_merge($schema->defaultFields(), $model->getFillable()));
        $missing = [];

        foreach ($schema->fields() as $name => $field) {
            if (! in_array($name, $visible, true) || isset($casts[$name])) {
                continue;
            }

            $type = strtolower($field->type());

            if (! isset(self::EXPECTED_CASTS[$type])) {
                continue;
            }

            $missing[] = sprintf('"%s" (%s)', $name, self::EXPECTED_CASTS[$type]);
        }

        if ($missing !== []) {
            $warnings[] = '$casts has no entry for ' . implode(', ', $missing);
        }

        foreach ($casts as $name => $cast) {
            $field = $schema->field((string) $name);

            if ($field === null || ! is_string($cast)) {
                continue;
            }

            $type = strtolower($field->type());
            $expected = self::EXPECTED_CASTS[$type]
                ?? (in_array($type, self::TEXT_TYPES, true) ? 'string' : null);

            if ($expected === null) {
                continue;
            }

            $family = $this->castFamily($cast);

            if (! in_array($family, self::KNOWN_FAMILIES, true)) {
                continue;
            }

            if ($family !== $this->castFamily($expected)) {
                $warnings[] = sprintf(
                    '$casts["%s"] is "%s", but the contract calls it %s',
                    $name,
                    $cast,
                    $field->type(),
                );
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function diffKey(ApiModel $model, ResourceSchema $schema, array &$errors): void
    {
        if ($model->getKeyName() !== $schema->key()) {
            $errors[] = sprintf(
                'the key is "%s" in the contract, "%s" on the model',
                $schema->key(),
                $model->getKeyName(),
            );
        }

        $remoteKeyIsInt = in_array(strtolower($schema->keyType()), ['int', 'integer', 'bigint'], true);

        if (! $remoteKeyIsInt && $model->getKeyType() === 'int') {
            $errors[] = sprintf(
                'the key is a %s in the contract; set protected $keyType = \'string\'; and protected $incrementing = false;',
                $schema->keyType(),
            );
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function diffSoftDeletes(ApiModel $model, ResourceSchema $schema, array &$errors, array &$warnings): void
    {
        $uses = method_exists($model, 'getRemoteDeletedAtColumn');

        if ($schema->softDeletes() && ! $uses) {
            $warnings[] = 'the resource soft-deletes; add the RemoteSoftDeletes trait to reach withTrashed() and restore()';
        }

        if (! $schema->softDeletes() && $uses) {
            $errors[] = 'the model uses RemoteSoftDeletes, but the resource does not soft-delete: delete() is permanent here';
        }
    }

    private function castFamily(string $cast): string
    {
        $family = strtolower(explode(':', $cast, 2)[0]);

        return match ($family) {
            'bool' => 'boolean',
            'int' => 'integer',
            'real', 'double' => 'float',
            'timestamp', 'immutable_datetime' => 'datetime',
            'immutable_date' => 'date',
            'json', 'object' => 'array',
            default => $family,
        };
    }

    /**
     * @param  list<string>  $candidates
     */
    private function suggest(string $field, array $candidates): string
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            $distance = levenshtein($field, $candidate);

            if ($distance <= 3 || str_contains($candidate, $field) || str_contains($field, $candidate)) {
                $scored[$candidate] = $distance;
            }
        }

        asort($scored);

        $best = array_slice(array_keys($scored), 0, 2);

        return implode(' / ', $best);
    }

    /**
     * The action whose name mentions this field.
     *
     * @param  list<string>  $actions
     */
    private function actionFor(string $field, array $actions): ?string
    {
        $needle = str_replace('_', '', strtolower($field));

        foreach ($actions as $action) {
            if (str_contains(str_replace(['-', '_', '.'], '', strtolower($action)), $needle)) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @return class-string<ApiModel>|null
     */
    private function resolveModel(string $resource): ?string
    {
        $option = trim((string) $this->option('model'));

        if ($option !== '') {
            $class = str_contains($option, '\\') ? $option : $this->appNamespace() . 'Models\\' . $option;

            return $this->isApiModel($class) ? $class : null;
        }

        $guess = $this->appNamespace() . 'Models\\' . Str::studly(Str::singular($resource));

        if ($this->isApiModel($guess) && $this->resourceOf($guess) === $resource) {
            return $guess;
        }

        foreach ($this->modelClasses() as $class) {
            if ($this->resourceOf($class) === $resource) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @return list<class-string<ApiModel>>
     */
    private function modelClasses(): array
    {
        $path = $this->modelsPath();

        if ($path === '' || ! $this->files->isDirectory($path)) {
            return [];
        }

        $classes = [];

        foreach ($this->files->allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = $this->appNamespace() . 'Models\\' . $relative;

            if ($this->isApiModel($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @phpstan-assert-if-true class-string<ApiModel> $class
     */
    private function isApiModel(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, ApiModel::class)) {
            return false;
        }

        return ! (new ReflectionClass($class))->isAbstract();
    }

    private function resourceOf(string $class): ?string
    {
        try {
            /** @var ApiModel $model */
            $model = new $class;

            return $model->resource();
        } catch (Throwable) {
            return null;
        }
    }

    private function modelsPath(): string
    {
        $app = $this->laravel;

        return is_object($app) && method_exists($app, 'path') ? (string) $app->path('Models') : '';
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
