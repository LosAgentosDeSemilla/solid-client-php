<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Unit;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\Base64UrlSafe;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Dpop\DpopKey;
use Semilla\SolidClient\Dpop\DpopProofFactory;
use Symfony\Component\Clock\MockClock;

final class DpopProofFactoryTest extends TestCase
{
    public function testAProofIsSignedByTheKeyItCarriesAndNamesTheRequest(): void
    {
        $key = DpopKey::generate();
        $proof = new DpopProofFactory(new MockClock('2026-10-29 12:00:00'))
            ->create($key, 'put', 'https://pod.example/a/b.ttl?x=1#frag', 'the-access-token', 'n0nce');

        $jws = new CompactSerializer()->unserialize($proof);
        $header = $jws->getSignature(0)->getProtectedHeader();
        self::assertSame('dpop+jwt', $header['typ']);
        self::assertSame('ES256', $header['alg']);
        self::assertArrayNotHasKey('d', $header['jwk'], 'The proof must carry the PUBLIC key only.');
        $verifier = new JWSVerifier(new AlgorithmManager([new ES256()]));
        self::assertTrue($verifier->verifyWithKey($jws, new JWK($header['jwk']), 0));

        $claims = json_decode((string) $jws->getPayload(), true);
        self::assertSame('PUT', $claims['htm']);
        self::assertSame('https://pod.example/a/b.ttl', $claims['htu']);
        self::assertSame(1793275200, $claims['iat']);
        self::assertSame('n0nce', $claims['nonce']);
        // RFC 9449 §4.2: base64url of the RAW SHA-256 (upstream hashed to hex first).
        self::assertSame(Base64UrlSafe::encodeUnpadded(hash('sha256', 'the-access-token', true)), $claims['ath']);
    }

    public function testATokenEndpointProofHasNoAccessTokenHash(): void
    {
        $proofs = new DpopProofFactory(new MockClock());
        $proof = $proofs->create(DpopKey::generate(), 'POST', 'https://idp.example/token');

        $claims = json_decode((string) new CompactSerializer()->unserialize($proof)->getPayload(), true);
        self::assertArrayNotHasKey('ath', $claims);
    }

    public function testTheKeySurvivesTheSession(): void
    {
        $key = DpopKey::generate();

        self::assertSame($key->thumbprint(), DpopKey::fromArray($key->toArray())->thumbprint());
    }
}
