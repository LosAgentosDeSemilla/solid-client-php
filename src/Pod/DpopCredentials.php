<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

use Semilla\SolidClient\Dpop\DpopProofFactory;
use Semilla\SolidClient\Oidc\TokenSet;

/**
 * A fixed token set: right for a script holding client credentials, which
 * simply asks for a new token when this one expires. A signed-in user's
 * session, whose token must be refreshed in place, implements
 * {@see Credentials} itself and delegates here per request.
 */
final readonly class DpopCredentials implements Credentials
{
    public function __construct(
        private TokenSet $tokens,
        private DpopProofFactory $proofs,
    ) {
    }

    public function headersFor(string $method, string $url, ?string $dpopNonce = null): array
    {
        return [
            'Authorization' => 'DPoP ' . $this->tokens->accessToken,
            'DPoP' => $this->proofs->create(
                $this->tokens->dpopKey,
                $method,
                $url,
                $this->tokens->accessToken,
                $dpopNonce,
            ),
        ];
    }
}
