<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

/** One write of a batch ({@see PodClient::writeAll()}): a PUT that creates or replaces a document, or a DELETE. */
final readonly class PodWrite
{
    private function __construct(
        public string $method,
        public string $url,
        public ?string $body,
        public ?string $contentType,
        public Precondition $precondition,
    ) {
    }

    public static function put(
        string $url,
        string $body,
        string $contentType = 'text/turtle',
        ?Precondition $precondition = null,
    ): self {
        if (str_ends_with($url, '/')) {
            throw new \InvalidArgumentException(sprintf('A PUT to %s would make a container.', $url));
        }

        return new self('PUT', $url, $body, $contentType, $precondition ?? Precondition::none());
    }

    public static function delete(string $url, ?Precondition $precondition = null): self
    {
        return new self('DELETE', $url, null, null, $precondition ?? Precondition::none());
    }

    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return [
            ...($this->contentType === null ? [] : ['Content-Type' => $this->contentType]),
            ...$this->precondition->headers(),
        ];
    }
}
