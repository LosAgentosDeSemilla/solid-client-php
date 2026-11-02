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
 * One `ldp:contains` of a container. A member whose URL ends in a slash is
 * itself a container (Solid Protocol §3.1).
 *
 * `$modified` is the member's `dc:modified` as the container listing states
 * it, when the server says (Community Solid Server does, to the millisecond,
 * and derives the member's ETag from the same instant). Null when the listing
 * does not — then only a HEAD on the member tells whether it changed.
 */
final readonly class ContainerMember
{
    public function __construct(
        public string $url,
        public ?string $modified = null,
    ) {
    }

    public function isContainer(): bool
    {
        return str_ends_with($this->url, '/');
    }
}
