<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Oidc;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks an ID token the way OpenID Connect Core §3.1.3.7 says: signed by a
 * key the issuer publishes, issued by that issuer, for this client, not
 * expired, and carrying the nonce this sign-in sent.
 *
 * Upstream accepted HS256/384/512 too, which against a published public key
 * is the classic algorithm-confusion hole, and skipped every claim check.
 * Asymmetric algorithms only here.
 */
final readonly class IdTokenVerifier
{
    private const int LEEWAY_SECONDS = 60;

    private JWSVerifier $verifier;

    public function __construct(
        private HttpClientInterface $http,
        private ClockInterface $clock,
    ) {
        $this->verifier = new JWSVerifier(new AlgorithmManager([
            new ES256(),
            new ES384(),
            new ES512(),
            new RS256(),
            new RS384(),
            new RS512(),
            new PS256(),
            new PS384(),
            new PS512(),
        ]));
    }

    /** @return array<string, mixed> the verified claims */
    public function verify(string $idToken, ProviderMetadata $provider, string $clientId, string $nonce): array
    {
        try {
            $jws = (new CompactSerializer())->unserialize($idToken);
        } catch (\Throwable $e) {
            throw new AuthenticationFailed('The ID token is not a compact JWS.', 0, $e);
        }

        $keys = $this->keysOf($provider);
        $verified = false;
        try {
            $verified = $this->verifier->verifyWithKeySet($jws, $keys, 0);
        } catch (\Throwable $e) {
            throw new AuthenticationFailed('The ID token signature could not be checked: ' . $e->getMessage(), 0, $e);
        }
        if (!$verified) {
            throw new AuthenticationFailed('The ID token is not signed by the issuer.');
        }

        $claims = json_decode((string) $jws->getPayload(), true);
        if (!\is_array($claims)) {
            throw new AuthenticationFailed('The ID token has no JSON claims.');
        }
        /** @var array<string, mixed> $claims */
        $this->checkClaims($claims, $provider, $clientId, $nonce);

        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private function checkClaims(array $claims, ProviderMetadata $provider, string $clientId, string $nonce): void
    {
        $now = $this->clock->now()->getTimestamp();

        if (($claims['iss'] ?? null) !== $provider->issuer) {
            throw new AuthenticationFailed('The ID token was issued by someone else.');
        }
        $audience = $claims['aud'] ?? null;
        $audiences = \is_array($audience) ? $audience : [$audience];
        if (!\in_array($clientId, $audiences, true)) {
            throw new AuthenticationFailed('The ID token is for another client.');
        }
        $exp = $claims['exp'] ?? null;
        if (!\is_int($exp) || $exp < $now - self::LEEWAY_SECONDS) {
            throw new AuthenticationFailed('The ID token has expired.');
        }
        $iat = $claims['iat'] ?? null;
        if (\is_int($iat) && $iat > $now + self::LEEWAY_SECONDS) {
            throw new AuthenticationFailed('The ID token was issued in the future.');
        }
        $tokenNonce = $claims['nonce'] ?? null;
        if (!\is_string($tokenNonce) || !hash_equals($nonce, $tokenNonce)) {
            throw new AuthenticationFailed('The ID token answers another sign-in (nonce mismatch).');
        }
    }

    private function keysOf(ProviderMetadata $provider): JWKSet
    {
        try {
            return JWKSet::createFromJson(
                $this->http->request('GET', $provider->jwksUri, ['headers' => ['Accept' => 'application/json']])
                    ->getContent(),
            );
        } catch (\Throwable $e) {
            throw new AuthenticationFailed(sprintf('No usable keys at %s.', $provider->jwksUri), 0, $e);
        }
    }
}
