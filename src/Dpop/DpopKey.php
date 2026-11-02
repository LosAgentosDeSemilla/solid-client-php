<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Dpop;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * The key pair an access token is bound to (RFC 9449). One per sign-in: the
 * token is useless without it, so it lives exactly as long as the token does
 * and is stored beside it.
 */
final readonly class DpopKey
{
    private function __construct(
        public JWK $jwk,
    ) {
        if ($jwk->get('kty') !== 'EC' || !$jwk->has('d')) {
            throw new \InvalidArgumentException('A DPoP key is a private EC key.');
        }
    }

    public static function generate(): self
    {
        return new self(JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig']));
    }

    /** @param array<string, mixed> $values the private JWK, as {@see toArray()} wrote it */
    public static function fromArray(array $values): self
    {
        return new self(new JWK($values));
    }

    /** @return array<string, mixed> the PRIVATE key — store it only where the access token is stored */
    public function toArray(): array
    {
        return $this->jwk->all();
    }

    /** @return array<string, mixed> */
    public function publicJwk(): array
    {
        return $this->jwk->toPublic()->all();
    }

    /** RFC 7638 thumbprint; the `cnf.jkt` of every token bound to this key. */
    public function thumbprint(): string
    {
        return $this->jwk->thumbprint('sha256');
    }
}
