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
 * What a write returned. `etag` is null when the server did not send one (the
 * Solid Protocol does not require it on writes), so a caller that needs the
 * new version's ETag reads it with `head()`.
 */
final readonly class Written
{
    public function __construct(
        public string $url,
        public int $status,
        public ?string $etag,
        public ?string $location,
    ) {
    }

    public function created(): bool
    {
        return $this->status === 201;
    }
}
