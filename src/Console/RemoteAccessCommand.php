<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Console;

use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Exceptions\RemoteEloquentException;
use Illuminate\Console\Command;
use Throwable;

/**
 * GET access — what THIS application may do, and how much of it is left.
 */
final class RemoteAccessCommand extends Command
{
    protected $signature = 'remote:access
                            {--json : Print the raw payload instead of the tables}';

    protected $description = 'Show the permissions and quota the account service grants this application.';

    public function handle(): int
    {
        try {
            $transport = $this->laravel->make(ResourceTransport::class);
            $response = $transport->access();
        } catch (RemoteEloquentException $exception) {
            $this->error('The access endpoint refused: ' . $exception->getMessage());

            if ($exception->requestId() !== null) {
                $this->line('  request id: <comment>' . $exception->requestId() . '</comment>');
            }

            $this->line('  Run <comment>php artisan remote:doctor</comment> to check the base URL and the credentials.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('The access endpoint could not be reached: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $data = $response->data();

        if (! is_array($data)) {
            $this->error('The access endpoint answered a payload this command cannot read.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>' . $this->applicationName($data) . '</>'
            . '   <fg=gray>via ' . $transport->name() . '</>');

        $version = $data['permissions_version'] ?? $response->permissionsVersion();

        if (is_scalar($version)) {
            $this->line('  permissions version: <comment>' . $version . '</comment>');
        }

        $this->newLine();

        $this->printResources(is_array($data['resources'] ?? null) ? $data['resources'] : []);
        $this->printPermissions(is_array($data['permissions'] ?? null) ? $data['permissions'] : []);
        $this->printQuota(is_array($data['quota'] ?? null) ? $data['quota'] : []);

        $rateLimit = $response->rateLimit();

        if (! $rateLimit->isEmpty()) {
            $this->line(sprintf(
                '  rate limit: <comment>%s</comment> left of <comment>%s</comment>%s',
                $rateLimit->remaining() ?? '?',
                $rateLimit->limit() ?? '?',
                $rateLimit->bucket() === '' ? '' : ' in bucket "' . $rateLimit->bucket() . '"',
            ));
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $resources
     */
    private function printResources(array $resources): void
    {
        if ($resources === []) {
            return;
        }

        $rows = [];

        foreach ($resources as $name => $definition) {
            if (is_int($name) && is_string($definition)) {
                $rows[] = [$definition, '-', '-'];

                continue;
            }

            $operations = is_array($definition) ? ($definition['operations'] ?? $definition) : [];
            $actions = is_array($definition) ? ($definition['actions'] ?? []) : [];

            $rows[] = [
                (string) $name,
                $this->flatten(is_array($operations) ? $operations : []),
                $this->flatten(is_array($actions) ? $actions : []),
            ];
        }

        $this->table(['resource', 'operations', 'actions'], $rows);
    }

    /**
     * @param  array<array-key, mixed>  $permissions
     */
    private function printPermissions(array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        $this->line('  <options=bold>permissions</>');

        foreach ($permissions as $permission) {
            if (is_scalar($permission)) {
                $this->line('    - ' . $permission);
            }
        }

        $this->newLine();
    }

    /**
     * @param  array<array-key, mixed>  $quota
     */
    private function printQuota(array $quota): void
    {
        if ($quota === []) {
            return;
        }

        $rows = [];

        foreach ($quota as $name => $value) {
            if (is_array($value)) {
                $rows[] = [
                    (string) $name,
                    $this->scalar($value['used'] ?? null),
                    $this->scalar($value['limit'] ?? null),
                    $this->scalar($value['remaining'] ?? null),
                    $this->scalar($value['resets_at'] ?? ($value['reset'] ?? null)),
                ];

                continue;
            }

            $rows[] = [(string) $name, $this->scalar($value), '-', '-', '-'];
        }

        $this->line('  <options=bold>quota</>');
        $this->table(['bucket', 'used', 'limit', 'remaining', 'resets'], $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applicationName(array $data): string
    {
        $application = $data['application'] ?? null;

        if (is_scalar($application)) {
            return (string) $application;
        }

        if (is_array($application)) {
            foreach (['name', 'title', 'client_id', 'id'] as $key) {
                if (isset($application[$key]) && is_scalar($application[$key])) {
                    return (string) $application[$key];
                }
            }
        }

        return 'this application';
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function flatten(array $values): string
    {
        $flat = [];

        foreach ($values as $value) {
            if (is_scalar($value)) {
                $flat[] = (string) $value;
            }
        }

        return $flat === [] ? '-' : implode(', ', $flat);
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return is_scalar($value) ? (string) $value : '-';
    }
}
