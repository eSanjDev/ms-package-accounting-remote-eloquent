<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Console;

use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Models\ApiModel;
use Esanj\RemoteEloquent\Schema\SchemaRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * Everything that is configured to fail later, found now.
 */
final class RemoteDoctorCommand extends Command
{
    /**
     * Hosts where plain HTTP is a developer's machine rather than a mistake.
     */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'host.docker.internal'];

    protected $signature = 'remote:doctor
                            {--offline : Skip the checks that call the account service}';

    protected $description = 'Check the Remote Eloquent setup for the mistakes that only show up in production.';

    private int $problems = 0;

    private int $warnings = 0;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Remote Eloquent doctor</>');

        $this->checkConfiguration();
        $this->checkAuthProviders();

        $models = $this->modelClasses();

        $this->checkValidationRules($models);
        $this->checkModels($models);

        $this->newLine();

        if ($this->problems === 0 && $this->warnings === 0) {
            $this->line('  <fg=green;options=bold>Nothing to report.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '  %s, %s',
            $this->problems === 0
                ? '<fg=green>no problems</>'
                : sprintf('<fg=red;options=bold>%d problem%s</>', $this->problems, $this->problems === 1 ? '' : 's'),
            sprintf('%d warning%s', $this->warnings, $this->warnings === 1 ? '' : 's'),
        ));
        $this->newLine();

        return $this->problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkConfiguration(): void
    {
        $this->section('configuration');

        $baseUrl = trim((string) $this->setting('rest.base_url', ''));

        if ($baseUrl === '') {
            $this->reportProblem(
                'rest.base_url is empty',
                'Set REMOTE_ELOQUENT_BASE_URL (or ACCOUNTING_BRIDGE_BASE_URL). Without it every call is a connection to nowhere.',
            );
        } else {
            $this->checkBaseUrl($baseUrl);
        }

        foreach (['auth.client_id' => 'REMOTE_ELOQUENT_CLIENT_ID', 'auth.client_secret' => 'REMOTE_ELOQUENT_CLIENT_SECRET'] as $key => $variable) {
            if (trim((string) $this->setting($key, '')) === '') {
                $this->reportProblem(
                    $key . ' is empty',
                    sprintf('Set %s, or the ACCOUNTING_BRIDGE_* variable it falls back to. Every call will come back 401.', $variable),
                );
            }
        }

        if (trim((string) $this->setting('auth.token_url', '')) === '') {
            $this->reportProblem('auth.token_url is empty', 'No token endpoint: the client-credentials grant has nowhere to go.');
        }

        $driver = (string) $this->setting('driver', 'rest');

        if ($driver !== 'rest') {
            $this->reportWarning(
                sprintf('driver is "%s"', $driver),
                'Only "rest" ships with the package; anything else has to be bound by the application itself.',
            );
        }

        if ($this->setting('fallback', false)) {
            $this->reportProblem(
                'fallback is on',
                'Version 1 replayed a failed call on a second transport and re-sent writes that had already been accepted. It stays off.',
            );
        }

        $maxLimit = (int) $this->setting('limits.max_query_limit', 100);

        if ($maxLimit < 1) {
            $this->reportProblem('limits.max_query_limit is ' . $maxLimit, 'Every bounded get() would be refused before it is sent.');
        } elseif ($maxLimit > 500) {
            $this->reportWarning(
                'limits.max_query_limit is ' . $maxLimit,
                'The server caps a page too, and the lower cap wins. This only decides which side reports it.',
            );
        }

        if ((int) $this->setting('limits.in_chunk', 500) > 500) {
            $this->reportWarning('limits.in_chunk is above 500', 'The contract caps an "in" list at 500; a longer one comes back 400.');
        }

        $findTtl = (int) $this->setting('cache.find_ttl', 0);

        if ($findTtl > 0) {
            $this->reportWarning(
                'cache.find_ttl is ' . $findTtl . 's',
                'Records are cached across requests. An account service is the system of record for balances and permissions; serving a stale one is a correctness decision, not a speedup.',
            );
        }

        $this->checkTransportBinding();
    }

    private function checkBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);

        if ($parts === false || ! isset($parts['host'])) {
            $this->reportProblem('rest.base_url is not a URL: ' . $baseUrl, 'It needs a scheme and a host, e.g. https://accounting.example.com');

            return;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            $this->reportOk('base url', $baseUrl);
        } elseif ($this->isLocal($host)) {
            $this->reportWarning('base url is plain HTTP: ' . $baseUrl, 'Fine on a developer machine. It must not reach an environment file that ships.');
        } else {
            $this->reportProblem(
                'base url is plain HTTP on a remote host: ' . $baseUrl,
                'The Authorization header carries this application\'s client-credentials token, and the Actor-Authorization header carries a user\'s. Use https.',
            );
        }

        $prefix = (string) $this->setting('rest.prefix', '/api/remote/v1');

        if ($prefix !== '' && ! str_starts_with($prefix, '/')) {
            $this->reportWarning('rest.prefix does not start with "/": ' . $prefix, 'Every URL will be built with the path glued to the host.');
        }

        if (str_contains($baseUrl, $prefix) && $prefix !== '') {
            $this->reportWarning(
                'rest.base_url already contains the prefix',
                'The transport appends "' . $prefix . '", so the path would be doubled.',
            );
        }
    }

    private function checkTransportBinding(): void
    {
        $transport = $this->resolve(ResourceTransport::class);

        if ($transport instanceof ResourceTransport) {
            $this->reportOk('transport', $transport->name());

            return;
        }

        $this->reportProblem(
            'nothing is bound to ' . ResourceTransport::class,
            'Register the package service provider. Until then every model answers with an exception instead of a row.',
        );
    }

    private function checkAuthProviders(): void
    {
        $providers = $this->config->get('auth.providers');

        if (! is_array($providers)) {
            return;
        }

        $this->section('auth');

        $remoteProviders = [];

        foreach ($providers as $name => $provider) {
            if (! is_array($provider)) {
                continue;
            }

            $model = isset($provider['model']) && is_string($provider['model']) ? $provider['model'] : null;

            if ($model === null || ! $this->isApiModel($model)) {
                continue;
            }

            $remoteProviders[] = (string) $name;

            $driver = (string) ($provider['driver'] ?? '');

            if ($driver === 'eloquent') {
                $this->reportProblem(
                    sprintf('auth provider "%s" uses the eloquent driver with the remote model %s', $name, $model),
                    'The eloquent provider retrieves the user with a local query and compares a password hash. A remote user has neither. Write a provider that calls ApiUser::me() with the end user\'s token, and keep Auth::attempt() away from it.',
                );

                continue;
            }

            if ($driver === 'database') {
                $this->reportProblem(
                    sprintf('auth provider "%s" is a database provider pointed at %s', $name, $model),
                    'It queries a local table that does not hold these users.',
                );

                continue;
            }

            $this->reportOk(sprintf('auth provider "%s"', $name), $driver === '' ? $model : $driver . ' -> ' . $model);
        }

        if ($remoteProviders === []) {
            return;
        }

        $guards = $this->config->get('auth.guards');

        if (! is_array($guards)) {
            return;
        }

        foreach ($guards as $name => $guard) {
            if (! is_array($guard) || ! in_array((string) ($guard['provider'] ?? ''), $remoteProviders, true)) {
                continue;
            }

            if ((string) ($guard['driver'] ?? '') === 'session') {
                $this->reportWarning(
                    sprintf('guard "%s" is a session guard over a remote user provider', $name),
                    'Anything that logs in through it — a login form, a Livewire component — is authenticating against the account service on every request. Make sure that is what was meant.',
                );
            }
        }
    }

    /**
     * @param  list<class-string<ApiModel>>  $models
     */
    private function checkValidationRules(array $models): void
    {
        $tables = [];
        $shortNames = [];

        foreach ($models as $class) {
            $model = $this->instantiate($class);

            if ($model === null) {
                continue;
            }

            try {
                $tables[] = $model->resource();
            } catch (Throwable) {
                // Reported by checkModels().
            }

            $tables[] = $model->getTable();
            $shortNames[] = class_basename($class);
        }

        $tables = array_values(array_unique(array_filter($tables)));

        if ($tables === []) {
            return;
        }

        $this->section('validation rules');

        $path = $this->appPath();

        if ($path === '' || ! $this->files->isDirectory($path)) {
            return;
        }

        $found = 0;

        foreach ($this->files->allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $found += $this->scanFile($file, $tables, $shortNames);
        }

        if ($found === 0) {
            $this->reportOk('no exists:/unique: rules point at a remote resource', '');
        }
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $shortNames
     */
    private function scanFile(SplFileInfo $file, array $tables, array $shortNames): int
    {
        $contents = $this->files->get($file->getPathname());

        if (! str_contains($contents, 'exists:') && ! str_contains($contents, 'unique:')
            && ! str_contains($contents, 'Rule::exists') && ! str_contains($contents, 'Rule::unique')) {
            return 0;
        }

        $found = 0;
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match_all('/\b(exists|unique)\s*:\s*([\\\\A-Za-z0-9_]+)/', $line, $matches, PREG_SET_ORDER) === 0) {
                $matches = [];
            }

            foreach ($matches as $match) {
                $table = str_replace('\\', '', $match[2]);

                if (! in_array($table, $tables, true) && ! in_array($table, $shortNames, true)) {
                    continue;
                }

                $found++;

                $this->reportProblem(
                    sprintf('%s:%d  "%s:%s"', $this->relative($file->getPathname()), $number + 1, $match[1], $match[2]),
                    sprintf(
                        'That rule compiles a local query against "%s", a table this application does not own. Validate through the resource API instead: validateRemote() posts to %s/validate and throws a RemoteValidationException the form renders on its own.',
                        $table,
                        $table,
                    ),
                );
            }

            if (preg_match('/Rule::(exists|unique)\s*\(\s*([A-Za-z0-9_\\\\]+)::class/', $line, $ruleMatch) === 1) {
                $class = class_basename(str_replace('\\\\', '\\', $ruleMatch[2]));

                if (in_array($class, $shortNames, true)) {
                    $found++;

                    $this->reportProblem(
                        sprintf('%s:%d  Rule::%s(%s::class)', $this->relative($file->getPathname()), $number + 1, $ruleMatch[1], $class),
                        'Rule::' . $ruleMatch[1] . '() compiles a query on the model\'s connection, which for a remote model is the API connection and refuses to run SQL. Use the resource API\'s validate endpoint.',
                    );
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<class-string<ApiModel>>  $models
     */
    private function checkModels(array $models): void
    {
        if ($models === []) {
            return;
        }

        $this->section('models');

        $schemas = $this->option('offline') ? null : $this->resolve(SchemaRepository::class);

        foreach ($models as $class) {
            $model = $this->instantiate($class);

            if ($model === null) {
                $this->reportProblem($class . ' could not be constructed', 'Everything below it is unchecked.');

                continue;
            }

            try {
                $resource = $model->resource();
            } catch (Throwable $exception) {
                $this->reportProblem($class . ' has no $resource', $exception->getMessage());

                continue;
            }

            if (! $schemas instanceof SchemaRepository) {
                $this->reportOk($class, $resource);

                continue;
            }

            $schema = $schemas->for($resource);

            if ($schema === null) {
                $this->reportWarning(
                    sprintf('%s -> %s: the schema could not be read', $class, $resource),
                    'The drift check was skipped. The queries themselves are unaffected: the server validates every request whether or not the schema was fetched.',
                );

                continue;
            }

            $drifted = [];

            foreach ($model->getFillable() as $field) {
                $definition = $schema->field($field);

                if ($definition === null) {
                    $drifted[] = sprintf('"%s" is not a field of %s', $field, $schema->versionTag());

                    continue;
                }

                if (! $definition->isWritableOn('create') && ! $definition->isWritableOn('update')) {
                    $drifted[] = sprintf('"%s" is not writable', $field);
                }
            }

            if ($model->getKeyName() !== $schema->key()) {
                $drifted[] = sprintf('the key is "%s" here and "%s" in the contract', $model->getKeyName(), $schema->key());
            }

            if ($drifted === []) {
                $this->reportOk($class . ' -> ' . $schema->versionTag(), count($model->getFillable()) . ' writable field(s)');

                continue;
            }

            $this->reportProblem(
                sprintf('%s has drifted from %s', $class, $schema->versionTag()),
                implode('; ', $drifted) . '. See php artisan remote:schema ' . $resource . ' --diff',
            );
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold>' . $title . '</>');
    }

    private function reportOk(string $label, string $detail): void
    {
        $this->line('    <fg=green>v</> ' . $label . ($detail === '' ? '' : '  <fg=gray>' . $detail . '</>'));
    }

    private function reportProblem(string $label, string $detail): void
    {
        $this->problems++;

        $this->line('    <fg=red>x</> ' . $label);

        if ($detail !== '') {
            $this->line('      <fg=gray>' . $detail . '</>');
        }
    }

    private function reportWarning(string $label, string $detail = ''): void
    {
        $this->warnings++;

        $this->line('    <fg=yellow>!</> ' . $label);

        if ($detail !== '') {
            $this->line('      <fg=gray>' . $detail . '</>');
        }
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->config->get('esanj.remote_eloquent.' . $key, $default);
    }

    private function isLocal(string $host): bool
    {
        return in_array($host, self::LOCAL_HOSTS, true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }

    private function resolve(string $abstract): ?object
    {
        try {
            $container = $this->laravel;

            if (! is_object($container) || ! method_exists($container, 'bound') || ! $container->bound($abstract)) {
                return null;
            }

            $resolved = $container->make($abstract);

            return is_object($resolved) ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function instantiate(string $class): ?ApiModel
    {
        try {
            $model = new $class;

            return $model instanceof ApiModel ? $model : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<class-string<ApiModel>>
     */
    private function modelClasses(): array
    {
        $path = $this->appPath();

        if ($path === '' || ! $this->files->isDirectory($path)) {
            return [];
        }

        $namespace = $this->appNamespace();
        $classes = [];

        foreach ($this->files->allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $namespace . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if ($this->isApiModel($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function isApiModel(string $class): bool
    {
        try {
            if (! class_exists($class) || ! is_subclass_of($class, ApiModel::class)) {
                return false;
            }

            return ! (new ReflectionClass($class))->isAbstract();
        } catch (Throwable) {
            return false;
        }
    }

    private function appPath(): string
    {
        $app = $this->laravel;

        return is_object($app) && method_exists($app, 'path') ? (string) $app->path() : '';
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

    private function relative(string $path): string
    {
        $app = $this->laravel;
        $base = is_object($app) && method_exists($app, 'basePath') ? (string) $app->basePath() : (string) getcwd();

        return str_starts_with($path, $base)
            ? ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR . '/')
            : $path;
    }
}
