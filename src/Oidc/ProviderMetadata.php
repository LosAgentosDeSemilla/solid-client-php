<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Oidc;

/** The parts of an OpenID Provider's discovery document this client uses. */
final readonly class ProviderMetadata
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $endSessionEndpoint = null,
    ) {
    }

    /** @param array<mixed> $document the decoded `.well-known/openid-configuration` */
    public static function fromDocument(string $expectedIssuer, array $document): self
    {
        $string = static fn (string $key): string => \is_string($document[$key] ?? null) && $document[$key] !== ''
            ? $document[$key]
            : throw new AuthenticationFailed(sprintf('The provider %s publishes no "%s".', $expectedIssuer, $key));

        $issuer = $string('issuer');
        // OpenID Connect Discovery §4.3: the document must be the issuer's own.
        if (rtrim($issuer, '/') !== rtrim($expectedIssuer, '/')) {
            throw new AuthenticationFailed(sprintf('%s describes itself as issuer %s.', $expectedIssuer, $issuer));
        }

        $endSession = $document['end_session_endpoint'] ?? null;

        return new self(
            $issuer,
            $string('authorization_endpoint'),
            $string('token_endpoint'),
            $string('jwks_uri'),
            \is_string($endSession) ? $endSession : null,
        );
    }
}
