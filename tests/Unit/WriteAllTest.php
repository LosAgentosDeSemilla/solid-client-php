<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Pod\Credentials;
use Semilla\SolidClient\Pod\NotFound;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Pod\PodResource;
use Semilla\SolidClient\Pod\PodWrite;
use Semilla\SolidClient\Pod\Written;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** {@see PodClient::writeAll()}: many writes in flight, each with its own result, a body made only when its turn comes. */
final class WriteAllTest extends TestCase
{
    private const string POD = 'https://pod.example/alice/';

    public function testEveryWriteHasItsOwnResultAndOneFailureStopsNothing(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => match (true) {
            str_ends_with($url, '/missing') => new MockResponse('nope', ['http_code' => 404]),
            $method === 'DELETE' => new MockResponse('', ['http_code' => 205]),
            default => new MockResponse('', ['http_code' => 201, 'response_headers' => ['ETag' => '"1"']]),
        });

        $results = [];
        foreach (
            (new PodClient($http))->writeAll([
            PodWrite::put(self::POD . 'a', '<#a> <#b> <#c>.'),
            PodWrite::delete(self::POD . 'missing'),
            PodWrite::delete(self::POD . 'b'),
            ], 2) as $write => $outcome
        ) {
            $results["{$write->method} {$write->url}"] = $outcome;
        }

        self::assertCount(3, $results);
        self::assertInstanceOf(Written::class, $results['PUT ' . self::POD . 'a']);
        self::assertSame('"1"', $results['PUT ' . self::POD . 'a']->etag);
        self::assertInstanceOf(NotFound::class, $results['DELETE ' . self::POD . 'missing']);
        self::assertInstanceOf(Written::class, $results['DELETE ' . self::POD . 'b']);
    }

    public function testNoMoreThanTheConcurrencyIsTakenAheadOfTheAnswers(): void
    {
        $taken = 0;
        $writes = (static function () use (&$taken): \Generator {
            for ($i = 0; $i < 10; ++$i) {
                ++$taken;
                yield PodWrite::put(self::POD . "doc-{$i}", '<#a> <#b> <#c>.');
            }
        })();
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 201]));

        $takenAtFirstResult = null;
        foreach ((new PodClient($http))->writeAll($writes, 3) as $outcome) {
            $takenAtFirstResult ??= $taken;
            self::assertInstanceOf(Written::class, $outcome);
        }

        self::assertSame(3, $takenAtFirstResult);
        self::assertSame(10, $taken);
    }

    public function testAWriteTheServerAsksANonceForIsSentAgainWithIt(): void
    {
        $nonces = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$nonces): MockResponse {
            /** @var array<string, list<string>> $headers */
            $headers = $options['normalized_headers'];
            $nonce = $headers['x-nonce'][0] ?? null;
            $nonces[] = $nonce;

            return $nonce === null
                ? new MockResponse('', ['http_code' => 401, 'response_headers' => ['DPoP-Nonce' => 'n-1']])
                : new MockResponse('', ['http_code' => 201]);
        });
        $credentials = new class implements Credentials {
            public function headersFor(string $method, string $url, ?string $dpopNonce = null): array
            {
                return $dpopNonce === null ? [] : ['X-Nonce' => $dpopNonce];
            }
        };

        $outcomes = iterator_to_array((new PodClient($http, $credentials))->writeAll([PodWrite::put(self::POD . 'a', '')]), false);

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(Written::class, $outcomes[0]);
        self::assertSame([null, 'X-Nonce: n-1'], $nonces);
    }

    public function testAListingCarriesEachMembersVersion(): void
    {
        $http = new MockHttpClient(new MockResponse(<<<'TTL'
            @prefix dc: <http://purl.org/dc/terms/>.
            @prefix ldp: <http://www.w3.org/ns/ldp#>.
            @prefix xsd: <http://www.w3.org/2001/XMLSchema#>.
            <> ldp:contains <a>, <sub/>.
            <a> dc:modified "2026-10-29T10:00:33.922Z"^^xsd:dateTime.
            TTL . "
", ['response_headers' => ['Content-Type' => 'text/turtle']]));

        $members = (new PodClient($http))->listContainer(self::POD);

        self::assertSame([self::POD . 'a', self::POD . 'sub/'], array_map(static fn ($m): string => $m->url, $members));
        self::assertSame('2026-10-29T10:00:33.922Z', $members[0]->modified);
        self::assertNull($members[1]->modified);
    }

    public function testTheAccessControlResourceIsFoundByItsLink(): void
    {
        $resource = new PodResource(self::POD . 'politika/', 200, null, null, '', [
            '<http://www.w3.org/ns/ldp#BasicContainer>; rel="type", <.acr>; rel="acl"',
        ]);

        self::assertSame(self::POD . 'politika/.acr', $resource->link('acl'));
        self::assertNull($resource->link('describedby'));
    }
}
