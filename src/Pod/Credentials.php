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
 * Who a pod request is made as. Asked once per request, because a DPoP proof
 * is per request — and because an implementation may refresh an expiring
 * token right here, which is how a signed-in session keeps working.
 */
interface Credentials
{
    /**
     * @param string|null $dpopNonce a `DPoP-Nonce` the server asked for on the previous attempt
     *
     * @return array<string, string> headers to add to the request
     */
    public function headersFor(string $method, string $url, ?string $dpopNonce = null): array;
}
