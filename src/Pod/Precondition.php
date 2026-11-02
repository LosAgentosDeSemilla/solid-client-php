<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

/**
 * The condition a request is made under (RFC 9110 §13): what makes a write
 * safe against someone else's write, and a read cheap when nothing changed.
 * An ETag is passed exactly as the server sent it, quotes included.
 */
final readonly class Precondition
{
    private function __construct(
        private ?string $header,
        private ?string $value,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    /** Write only if the resource is still the version read (lost-update guard). */
    public static function ifMatch(string $etag): self
    {
        return new self('If-Match', $etag);
    }

    /** Read only if the resource changed since `$etag`; otherwise 304. */
    public static function ifNoneMatch(string $etag): self
    {
        return new self('If-None-Match', $etag);
    }

    /** Write only if nothing exists there yet. */
    public static function createOnly(): self
    {
        return new self('If-None-Match', '*');
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->header === null || $this->value === null ? [] : [$this->header => $this->value];
    }

    public function allowsNotModified(): bool
    {
        return $this->header === 'If-None-Match' && $this->value !== '*';
    }
}
