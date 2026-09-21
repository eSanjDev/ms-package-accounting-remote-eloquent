<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Testing;

use Closure;
use Esanj\RemoteEloquent\Cache\IdentityMap;
use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Idempotency\OperationContext;
use Illuminate\Container\Container;

final class RemoteResourceFake
{
    private function __construct(
        private readonly FakeResourceTransport $transport,
        private readonly Container $container,
    ) {
    }

    /**
     * Bind a fresh fake as THE transport, and hand back the handle.
     *
     * @param  array<int, array<string, mixed>>  $rows  seeded under the wildcard resource
     */
    public static function install(array $rows = [], ?Container $container = null): self
    {
        $container ??= Container::getInstance();

        $transport = new FakeResourceTransport($rows);

        $container->instance(ResourceTransport::class, $transport);

        if ($container->bound(IdentityMap::class)) {
            $container->make(IdentityMap::class)->flush();
        }

        if ($container->bound(OperationContext::class)) {
            $container->make(OperationContext::class)->flush();
        }

        return new self($transport, $container);
    }

    /**
     * Wrap a fake that is already bound.
     */
    public static function wrap(FakeResourceTransport $transport, ?Container $container = null): self
    {
        return new self($transport, $container ?? Container::getInstance());
    }

    public function transport(): FakeResourceTransport
    {
        return $this->transport;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function seed(string $resource, array $rows): self
    {
        FakeResourceTransport::seed($resource, $rows);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function seedOne(string $resource, array $row): self
    {
        FakeResourceTransport::seedOne($resource, $row);

        return $this;
    }

    public function identifyBy(string $resource, string $keyName): self
    {
        FakeResourceTransport::identifyBy($resource, $keyName);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function schemaFor(string $resource, array $schema): self
    {
        FakeResourceTransport::schemaFor($resource, $schema);

        return $this;
    }

    public function failWith(
        string $resource,
        int $status,
        string $code = '',
        string $message = '',
        int $retryAfter = 30,
        string $bucket = '',
    ): self {
        FakeResourceTransport::failWith($resource, $status, $code, $message, $retryAfter, $bucket);

        return $this;
    }

    public function stopFailing(string $resource = FakeResourceTransport::ANY): self
    {
        FakeResourceTransport::stopFailing($resource);

        return $this;
    }

    /**
     * @param  array<string, list<string>|string>  $errors
     */
    public function failValidation(string $resource, array $errors): self
    {
        FakeResourceTransport::failValidation($resource, $errors);

        return $this;
    }

    /**
     * @param  Closure(array<string, mixed>, array<string, mixed>): mixed  $handler
     */
    public function handleAction(string $resource, string $action, Closure $handler): self
    {
        FakeResourceTransport::handleAction($resource, $action, $handler);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function actingAs(array $row): self
    {
        FakeResourceTransport::actingAs($row);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function allowAccess(array $payload): self
    {
        FakeResourceTransport::allowAccess($payload);

        return $this;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function grant(array $permissions): self
    {
        FakeResourceTransport::grant($permissions);

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $resource): array
    {
        return FakeResourceTransport::rows($resource);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calls(): array
    {
        return FakeResourceTransport::calls();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsTo(string $method, ?string $resource = null): array
    {
        return FakeResourceTransport::callsTo($method, $resource);
    }

    public function requestCount(): int
    {
        return FakeResourceTransport::requestCount();
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher
     */
    public function assertQueried(string $resource, ?callable $matcher = null): self
    {
        FakeResourceTransport::assertQueried($resource, $matcher);

        return $this;
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher
     */
    public function assertCreated(string $resource, ?callable $matcher = null): self
    {
        FakeResourceTransport::assertCreated($resource, $matcher);

        return $this;
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher
     */
    public function assertUpdated(string $resource, ?callable $matcher = null): self
    {
        FakeResourceTransport::assertUpdated($resource, $matcher);

        return $this;
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher
     */
    public function assertDeleted(string $resource, ?callable $matcher = null): self
    {
        FakeResourceTransport::assertDeleted($resource, $matcher);

        return $this;
    }

    public function assertNothingDeleted(): self
    {
        FakeResourceTransport::assertNothingDeleted();

        return $this;
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher
     */
    public function assertActioned(string $resource, string $action, ?callable $matcher = null): self
    {
        FakeResourceTransport::assertActioned($resource, $action, $matcher);

        return $this;
    }

    /**
     * The N+1 guard: every remote call of every kind counts.
     */
    public function assertRequestCount(int $expected): self
    {
        FakeResourceTransport::assertRequestCount($expected);

        return $this;
    }

    /**
     * Drop the binding and forget every row, call and failure.
     */
    public function stop(): void
    {
        FakeResourceTransport::reset();

        $this->container->forgetInstance(ResourceTransport::class);

        if ($this->container->bound(IdentityMap::class)) {
            $this->container->make(IdentityMap::class)->flush();
        }
    }
}
