<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Dpop\DpopProofFactory;
use Semilla\SolidClient\Oidc\IdTokenVerifier;
use Semilla\SolidClient\Oidc\SolidOidcClient;
use Semilla\SolidClient\Pod\AccessDenied;
use Semilla\SolidClient\Pod\DpopCredentials;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Pod\Precondition;
use Semilla\SolidClient\Pod\PreconditionFailed;
use Semilla\SolidClient\Profile\ProfileReader;
use Semilla\SolidClient\Testing\FakePod;
use Semilla\SolidClient\Testing\FakeSolidServer;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/** The fakes an application tests against answer this package as Community Solid Server would. */
final class FakesTest extends TestCase
{
    private FakeSolidServer $server;

    private SolidOidcClient $oidc;

    private DpopProofFactory $proofs;

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-11-02 12:00:00');
        $this->server = new FakeSolidServer($clock, new FakePod());
        $this->http = new MockHttpClient($this->server);
        $this->proofs = new DpopProofFactory($clock);
        $this->oidc = new SolidOidcClient($this->http, $this->proofs, new IdTokenVerifier($this->http, $clock), $clock);
    }

    public function testTheOwnersClientCredentialsWriteTheirPod(): void
    {
        $pod = $this->podAs(FakeSolidServer::OWNER_CLIENT_ID, FakeSolidServer::OWNER_CLIENT_SECRET);
        $url = FakePod::STORAGE . 'notes/first';

        $pod->put($url, '<#it> <http://schema.org/name> "první" .', precondition: Precondition::createOnly());

        self::assertTrue($this->server->pod->has($url));
        self::assertSame([FakePod::STORAGE . 'notes/'], array_map(
            static fn ($member): string => $member->url,
            $pod->listContainer(FakePod::STORAGE),
        ));
        $this->expectException(PreconditionFailed::class);
        $pod->put($url, '<#it> <http://schema.org/name> "druhá" .', precondition: Precondition::createOnly());
    }

    public function testAStrangerIsRefusedTheOwnersPod(): void
    {
        $pod = $this->podAs(FakeSolidServer::STRANGER_CLIENT_ID, FakeSolidServer::STRANGER_CLIENT_SECRET);

        $this->expectException(AccessDenied::class);
        $pod->get(FakePod::STORAGE . 'notes/first');
    }

    public function testTheOwnersProfileNamesTheIssuerAndTheStorage(): void
    {
        $profile = (new ProfileReader(new PodClient($this->http)))->read(FakeSolidServer::OWNER);

        self::assertTrue($profile->trustsIssuer(FakeSolidServer::ISSUER));
        self::assertSame([FakeSolidServer::STORAGE], $profile->storages);
    }

    private function podAs(string $clientId, string $secret): PodClient
    {
        $tokens = $this->oidc->clientCredentials(FakeSolidServer::ISSUER, $clientId, $secret);

        return new PodClient($this->http, new DpopCredentials($tokens, $this->proofs));
    }
}
