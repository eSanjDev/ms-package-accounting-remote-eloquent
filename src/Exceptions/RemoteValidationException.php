<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 422 validation_failed or field_locked — the account service refused the DATA, not the request.
 */
final class RemoteValidationException extends ValidationException
{
    /** @var array<string, list<string>> */
    private readonly array $remoteErrors;

    /**
     * @param  array<string, list<string>|string>  $errors
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        array $errors,
        public readonly string $errorCode = 'validation_failed',
        public readonly int $remoteStatus = 422,
        public readonly ?string $requestId = null,
        public readonly array $context = [],
        string $message = '',
    ) {
        $normalized = self::normalize($errors);

        parent::__construct(self::validatorFor($normalized), null, 'default');

        $this->remoteErrors = $normalized;

        $this->message = $message !== '' ? $message : self::summarizeErrors($normalized);

        // The status Laravel answers with.
        $this->status = 422;
    }

    /**
     * Laravel's shape: field => list of messages.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->remoteErrors;
    }

    /**
     * The server's stable error code: validation_failed or field_locked.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The status the account service answered with.
     */
    public function remoteStatus(): int
    {
        return $this->remoteStatus;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Also picked up by Laravel's handler as log context.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, list<string>|string>  $errors
     * @param  array<string, scalar|null>  $context
     */
    public static function fromServer(
        array $errors,
        string $code = 'validation_failed',
        ?string $requestId = null,
        array $context = [],
        string $message = '',
    ): self {
        return new self(
            $errors,
            $code !== '' ? $code : 'validation_failed',
            422,
            $requestId,
            $context,
            $message,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fieldLocked(
        string $resource,
        string $field,
        string $reason = '',
        ?string $requestId = null,
        array $context = [],
    ): self {
        $reason = $reason !== ''
            ? $reason
            : sprintf('This field is locked on the "%s" record and cannot be changed here.', $resource);

        return new self(
            [$field => [$reason]],
            'field_locked',
            422,
            $requestId,
            $context + ['resource' => $resource, 'field' => $field],
        );
    }

    /**
     * @param  array<string, list<string>|string>  $messages
     */
    public static function withMessages(array $messages): self
    {
        return new self($messages);
    }

    /**
     * Deliberately null.
     */
    public function render(Request $request): ?Response
    {
        return null;
    }

    /**
     * A Validator carrying the server's messages, and nothing else.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function validatorFor(array $errors): ValidatorContract
    {
        $validator = self::newValidator();
        $bag = $validator->errors();

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $bag->add($field, $message);
            }
        }

        return $validator;
    }

    private static function newValidator(): ValidatorContract
    {
        try {
            $container = Container::getInstance();

            if ($container->bound('validator')) {
                $factory = $container->make('validator');

                if ($factory instanceof ValidationFactory) {
                    return $factory->make([], []);
                }
            }
        } catch (Throwable) {
            // A half-built container is not a reason to lose the messages.
        }

        return new Validator(new Translator(new ArrayLoader(), 'en'), [], []);
    }

    /**
     * @param  array<string, list<string>|string>  $errors
     * @return array<string, list<string>>
     */
    private static function normalize(array $errors): array
    {
        $normalized = [];

        foreach ($errors as $field => $messages) {
            $list = [];

            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                if (is_scalar($message)) {
                    $list[] = (string) $message;
                }
            }

            if ($list !== []) {
                $normalized[(string) $field] = $list;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function summarizeErrors(array $errors): string
    {
        $flat = [];

        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                $flat[] = $message;
            }
        }

        if ($flat === []) {
            return 'The account service refused this data.';
        }

        $first = array_shift($flat);
        $remaining = count($flat);

        if ($remaining === 0) {
            return $first;
        }

        return sprintf(
            '%s (and %d more %s)',
            $first,
            $remaining,
            $remaining === 1 ? 'error' : 'errors'
        );
    }
}
