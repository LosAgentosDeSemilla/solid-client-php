<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

/** No credentials: public resources only, such as a WebID profile. */
final readonly class Anonymous implements Credentials
{
    public function headersFor(string $method, string $url, ?string $dpopNonce = null): array
    {
        return [];
    }
}
