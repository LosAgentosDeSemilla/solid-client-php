<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Pod\NotFound;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Pod\PodResource;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** What an ingest needs of a pod: which documents changed (from the listing alone) and many of them at once. */
final class ContainerReadsTest extends TestCase
{
    private const string CONTAINER = 'https://pod.example/alice/politika/fakta/kauzy/';

    public function testAListingGivesEachMembersModificationTimeWhenTheServerStatesIt(): void
    {
        $pod = new PodClient(new MockHttpClient(new MockResponse(<<<'TTL'
            @prefix dc: <http://purl.org/dc/terms/>.
            @prefix ldp: <http://www.w3.org/ns/ldp#>.
            @prefix xsd: <http://www.w3.org/2001/XMLSchema#>.
            <> ldp:contains <capi-hnizdo>, <archiv/>, <bez-casu>.
            <capi-hnizdo> dc:modified "2026-10-29T08:53:04.392Z"^^xsd:dateTime.
            <archiv/> dc:modified "2026-10-28T10:00:00.000Z"^^xsd:dateTime .
            TTL, ['response_headers' => ['Content-Type' => 'text/turtle']])));

        $members = $pod->listContainer(self::CONTAINER);

        self::assertSame(
            [
                [self::CONTAINER . 'capi-hnizdo', '2026-10-29T08:53:04.392Z', false],
                [self::CONTAINER . 'archiv/', '2026-10-28T10:00:00.000Z', true],
                [self::CONTAINER . 'bez-casu', null, false],
            ],
            array_map(static fn ($member): array => [$member->url, $member->modified, $member->isContainer()], $members),
        );
    }

    public function testManyResourcesAreReadAtOnceAndAFailureDoesNotStopTheRest(): void
    {
        $pod = new PodClient(new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, 'gone')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse("<#it> <https://example.org/p> \"{$url}\".", [
                'response_headers' => ['Content-Type' => 'text/turtle', 'ETag' => '"1-text/turtle"'],
            ])));

        $read = iterator_to_array($pod->getMany([self::CONTAINER . 'a', self::CONTAINER . 'gone', self::CONTAINER . 'b'], concurrency: 2));

        self::assertSame([self::CONTAINER . 'a', self::CONTAINER . 'gone', self::CONTAINER . 'b'], array_keys($read));
        self::assertInstanceOf(PodResource::class, $read[self::CONTAINER . 'a']);
        self::assertSame('"1-text/turtle"', $read[self::CONTAINER . 'a']->etag);
        self::assertStringContainsString(self::CONTAINER . 'b', $read[self::CONTAINER . 'b']->body);
        self::assertInstanceOf(NotFound::class, $read[self::CONTAINER . 'gone']);
    }
}
