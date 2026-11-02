<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Dpop;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\Util\Base64UrlSafe;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Clock\ClockInterface;

/**
 * DPoP proofs (RFC 9449 §4): one signed JWT per request, naming the method
 * and the URL it is for, so a stolen access token is worthless without the
 * private key.
 *
 * Upstream built the same proof inside its OIDC client; it is separate here
 * because the token endpoint and every pod request need one, and the pod
 * client has no business knowing about OIDC.
 */
final readonly class DpopProofFactory
{
    private JWSBuilder $builder;

    public function __construct(
        private ClockInterface $clock,
    ) {
        $this->builder = new JWSBuilder(new AlgorithmManager([new ES256()]));
    }

    /**
     * @param string|null $accessToken the token the proof accompanies; its hash
     *                                 becomes `ath`. Null at the token endpoint.
     * @param string|null $nonce       a server-provided `DPoP-Nonce`, if it asked for one
     */
    public function create(
        DpopKey $key,
        string $method,
        string $url,
        ?string $accessToken = null,
        ?string $nonce = null,
    ): string {
        $claims = [
            'htu' => self::htu($url),
            'htm' => strtoupper($method),
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $this->clock->now()->getTimestamp(),
        ];
        if ($accessToken !== null) {
            $claims['ath'] = Base64UrlSafe::encodeUnpadded(hash('sha256', $accessToken, true));
        }
        if ($nonce !== null) {
            $claims['nonce'] = $nonce;
        }

        $jws = $this->builder
            ->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))
            ->addSignature($key->jwk, [
                'alg' => 'ES256',
                'typ' => 'dpop+jwt',
                'jwk' => $key->publicJwk(),
            ])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /** The URL without query and fragment (RFC 9449 §4.2). */
    private static function htu(string $url): string
    {
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }
}
