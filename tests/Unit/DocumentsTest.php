<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Pod\N3Patch;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Pod\Precondition;
use Semilla\SolidClient\Profile\ProfileReader;
use Semilla\SolidClient\Profile\WebIdProfile;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** What the client reads out of profiles and writes into patches, without a server. */
final class DocumentsTest extends TestCase
{
    private const string WEB_ID = 'https://pod.example/alice/profile/card#me';

    public function testAProfileGivesIssuersStorageAndTypeIndexes(): void
    {
        $profile = $this->profile(<<<'TTL'
            @prefix solid: <http://www.w3.org/ns/solid/terms#>.
            @prefix pim: <http://www.w3.org/ns/pim/space#>.
            <#me> solid:oidcIssuer <https://idp.example/>, <https://other.example/>;
                pim:storage </alice/>;
                pim:preferencesFile </alice/settings/prefs.ttl>;
                solid:publicTypeIndex </alice/settings/publicTypeIndex.ttl>.
            TTL);

        self::assertSame(['https://idp.example/', 'https://other.example/'], $profile->oidcIssuers);
        self::assertSame(['https://pod.example/alice/'], $profile->storages);
        self::assertSame('https://pod.example/alice/settings/publicTypeIndex.ttl', $profile->publicTypeIndex);
        self::assertNull($profile->privateTypeIndex);
        self::assertSame('https://pod.example/alice/settings/prefs.ttl', $profile->preferencesFile);
        self::assertTrue($profile->trustsIssuer('https://idp.example'));
        self::assertFalse($profile->trustsIssuer('https://idp.example.evil/'));
    }

    public function testWithoutPimStorageTheStorageIsFoundByItsLinkHeader(): void
    {
        $asked = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$asked): MockResponse {
            $asked[] = "{$method} {$url}";

            $storage = ['response_headers' => ['Link' => '<http://www.w3.org/ns/pim/space#Storage>; rel="type"']];

            return $url === 'https://pod.example/alice/'
                ? new MockResponse('', $storage)
                : new MockResponse('', ['http_code' => 401]);
        });
        $profile = new WebIdProfile(self::WEB_ID, ['https://idp.example/'], [], null, null, null);

        self::assertSame('https://pod.example/alice/', new ProfileReader(new PodClient($http))->storageOf($profile));
        self::assertSame(['HEAD https://pod.example/alice/profile/', 'HEAD https://pod.example/alice/'], $asked);
    }

    public function testAnN3PatchDeletesBeforeItInserts(): void
    {
        $n3 = N3Patch::create()
            ->withPrefix('ex', 'https://example.org/')
            ->withWhere('?s ex:stav ?stav.')
            ->withDeletes('?s ex:stav ?stav.')
            ->withInserts('?s ex:stav "nový".')
            ->toN3();

        self::assertStringContainsString('@prefix ex: <https://example.org/>.', $n3);
        self::assertStringContainsString('a solid:InsertDeletePatch', $n3);
        self::assertMatchesRegularExpression('/solid:where \{.*solid:deletes \{.*solid:inserts \{/s', $n3);
    }

    public function testPreconditionsAreTheirHeaders(): void
    {
        self::assertSame([], Precondition::none()->headers());
        self::assertSame(['If-Match' => '"v1"'], Precondition::ifMatch('"v1"')->headers());
        self::assertSame(['If-None-Match' => '*'], Precondition::createOnly()->headers());
        self::assertTrue(Precondition::ifNoneMatch('"v1"')->allowsNotModified());
        self::assertFalse(Precondition::createOnly()->allowsNotModified());
    }

    private function profile(string $turtle): WebIdProfile
    {
        $response = new MockResponse($turtle, ['response_headers' => ['Content-Type' => 'text/turtle']]);
        $http = new MockHttpClient($response);

        return new ProfileReader(new PodClient($http))->read(self::WEB_ID);
    }
}
