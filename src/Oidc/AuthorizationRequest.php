<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Oidc;

use Semilla\SolidClient\Dpop\DpopKey;

/**
 * A sign-in in progress: everything the callback needs to check that the
 * answer is to THIS request (state, nonce, PKCE verifier) and to bind the
 * token to the key generated for it. Kept server-side (in the session)
 * between the redirect out and the callback; `url` is where the browser goes.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $issuer,
        public string $clientId,
        public string $redirectUri,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public DpopKey $dpopKey,
        public string $url,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'clientId' => $this->clientId,
            'redirectUri' => $this->redirectUri,
            'state' => $this->state,
            'nonce' => $this->nonce,
            'codeVerifier' => $this->codeVerifier,
            'dpopKey' => $this->dpopKey->toArray(),
            'url' => $this->url,
        ];
    }

    /** @param array<string, mixed> $values as {@see toArray()} wrote them */
    public static function fromArray(array $values): self
    {
        $string = static fn (string $key): string => \is_string($values[$key] ?? null)
            ? $values[$key]
            : throw new \InvalidArgumentException(sprintf('A stored sign-in has no "%s".', $key));
        $key = $values['dpopKey'] ?? null;
        if (!\is_array($key)) {
            throw new \InvalidArgumentException('A stored sign-in has no DPoP key.');
        }

        /** @var array<string, mixed> $key */
        return new self(
            $string('issuer'),
            $string('clientId'),
            $string('redirectUri'),
            $string('state'),
            $string('nonce'),
            $string('codeVerifier'),
            DpopKey::fromArray($key),
            $string('url'),
        );
    }
}
