<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP over a Solid pod (Solid Protocol §5): read, write, patch and delete
 * resources and containers as whoever the {@see Credentials} say, with ETag
 * preconditions on every write.
 *
 * Grew out of upstream's `SolidClient`, which had GET and POST and put the
 * token on the request itself. Every non-2xx answer is an exception here
 * ({@see PodRequestFailed} and its subclasses), so a caller never mistakes a
 * refused write for a written one.
 *
 * Redirects are not followed: a DPoP proof names the exact URL, and a write
 * silently replayed somewhere else is not the write that was asked for.
 */
final readonly class PodClient
{
    public const string LDP_CONTAINS = 'http://www.w3.org/ns/ldp#contains';
    public const string LDP_BASIC_CONTAINER = 'http://www.w3.org/ns/ldp#BasicContainer';
    public const string LDP_RESOURCE = 'http://www.w3.org/ns/ldp#Resource';
    public const string DC_MODIFIED = 'http://purl.org/dc/terms/modified';
    private const string TURTLE = 'text/turtle';

    public function __construct(
        private HttpClientInterface $http,
        private Credentials $credentials = new Anonymous(),
    ) {
    }

    public function as(Credentials $credentials): self
    {
        return new self($this->http, $credentials);
    }

    /** A 304 comes back as a resource, not an exception, when `$precondition` is an {@see Precondition::ifNoneMatch()}. */
    public function get(string $url, string $accept = self::TURTLE, ?Precondition $precondition = null): PodResource
    {
        $precondition ??= Precondition::none();
        $response = $this->send('GET', $url, ['Accept' => $accept, ...$precondition->headers()]);
        $status = $response->getStatusCode();
        if ($status === 304 && $precondition->allowsNotModified()) {
            return $this->resource($url, $response, '');
        }
        $this->guard('GET', $url, $response);

        return $this->resource($url, $response, $response->getContent(false));
    }

    /**
     * `$accept` as for {@see get()}: Community Solid Server answers a HEAD of a
     * file that is not RDF with 501 when asked for Turtle.
     */
    public function head(string $url, string $accept = self::TURTLE): PodResource
    {
        $response = $this->send('HEAD', $url, ['Accept' => $accept]);
        $this->guard('HEAD', $url, $response);

        return $this->resource($url, $response, '');
    }

    /** Creates or replaces the resource. A URL ending in a slash would make a container; use {@see createContainer()}. */
    public function put(
        string $url,
        string $body,
        string $contentType = self::TURTLE,
        ?Precondition $precondition = null,
    ): Written {
        return $this->write('PUT', $url, $body, [
            'Content-Type' => $contentType,
            ...($precondition ?? Precondition::none())->headers(),
        ]);
    }

    public function patch(string $url, N3Patch $patch, ?Precondition $precondition = null): Written
    {
        return $this->write('PATCH', $url, $patch->toN3(), [
            'Content-Type' => 'text/n3',
            ...($precondition ?? Precondition::none())->headers(),
        ]);
    }

    public function delete(string $url, ?Precondition $precondition = null): Written
    {
        return $this->write('DELETE', $url, null, ($precondition ?? Precondition::none())->headers());
    }

    /** A new resource in `$containerUrl`, named by the server; `$slug` is a hint it may ignore. */
    public function post(
        string $containerUrl,
        string $body,
        string $contentType = self::TURTLE,
        ?string $slug = null,
    ): Written {
        $headers = [
            'Content-Type' => $contentType,
            'Link' => sprintf('<%s>; rel="type"', self::LDP_RESOURCE),
        ];
        if ($slug !== null) {
            $headers['Slug'] = $slug;
        }

        return $this->write('POST', $containerUrl, $body, $headers);
    }

    /**
     * An empty container at `$url` (which must end in a slash). Missing
     * intermediate containers are created by the server. With the default
     * {@see Precondition::createOnly()} an existing container is a
     * {@see PreconditionFailed}; pass {@see Precondition::none()} to accept it.
     */
    public function createContainer(string $url, ?Precondition $precondition = null): Written
    {
        if (!str_ends_with($url, '/')) {
            throw new \InvalidArgumentException(sprintf('A container URL ends in a slash, %s does not.', $url));
        }

        return $this->write('PUT', $url, '', [
            'Content-Type' => self::TURTLE,
            'Link' => sprintf('<%s>; rel="type"', self::LDP_BASIC_CONTAINER),
            ...($precondition ?? Precondition::createOnly())->headers(),
        ]);
    }

    /** @return list<ContainerMember> in the order the server listed them */
    public function listContainer(string $url): array
    {
        $document = $this->get($url)->rdf();

        return array_map(
            static fn (string $member): ContainerMember => new ContainerMember(
                $member,
                $document->firstLiteral($member, self::DC_MODIFIED),
            ),
            $document->objects($url, self::LDP_CONTAINS),
        );
    }

    /**
     * Many writes at once: up to `$concurrency` requests in flight, the next
     * one taken from `$writes` only when a slot frees up — so a generator that
     * builds each body as it is asked for is never read further ahead than
     * that, and nothing holds every body at once.
     *
     * One write failing does not stop the others: each yields its own result,
     * a {@see Written} or the {@see PodRequestFailed} a single write would
     * have thrown, keyed by the write. Results come in the order the server
     * answers, not the order of `$writes`.
     *
     * @param iterable<PodWrite> $writes
     *
     * @return \Generator<PodWrite, Written|PodRequestFailed>
     */
    public function writeAll(iterable $writes, int $concurrency = 8): \Generator
    {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('At least one write has to be allowed in flight.');
        }

        /** @var \SplObjectStorage<ResponseInterface, array{PodWrite, bool}> $inFlight write, and whether it is the nonce retry */
        $inFlight = new \SplObjectStorage();
        $source = self::generate($writes);
        // Advanced only when a slot is free: next() right after a start would
        // already build the following body.
        $begun = false;

        while (true) {
            while (count($inFlight) < $concurrency) {
                if ($begun) {
                    $source->next();
                }
                $begun = true;
                if (!$source->valid()) {
                    break;
                }
                $write = $source->current();
                $inFlight[$this->startWrite($write)] = [$write, false];
            }
            if (count($inFlight) === 0) {
                return;
            }

            foreach ($this->http->stream(iterator_to_array($inFlight, false)) as $response => $chunk) {
                try {
                    // Read the status before anything else: a response whose status nobody
                    // looked at by its first chunk makes stream() throw for a 4xx or 5xx,
                    // and a refusal here is a result, not an exception.
                    $response->getStatusCode();
                    if (!$chunk->isLast()) {
                        continue;
                    }
                } catch (TransportExceptionInterface) {
                    // The response is done all the same; outcome() says how.
                }
                [$write, $retried] = $inFlight[$response];
                unset($inFlight[$response]);

                $outcome = $this->outcome($write, $response);
                $nonce = !$retried && $outcome instanceof AccessDenied && $outcome->status === 401
                    ? $response->getHeaders(false)['dpop-nonce'][0] ?? null
                    : null;
                if ($nonce !== null) {
                    // The one retry this client makes (see send()), here per write.
                    $inFlight[$this->startWrite($write, $nonce)] = [$write, true];
                    break;
                }

                yield $write => $outcome;
                break;
            }
        }
    }

    /**
     * GETs many resources at once, `$concurrency` in flight at a time, and
     * yields each as it is read — keyed by its URL, in the order asked. A
     * resource that fails comes out as its {@see PodRequestFailed} instead of
     * stopping the rest; the caller decides what one missing resource means.
     *
     * @param list<string> $urls
     *
     * @return \Generator<string, PodResource|PodRequestFailed>
     */
    public function getMany(array $urls, string $accept = self::TURTLE, int $concurrency = 16): \Generator
    {
        return $this->many('GET', $urls, $accept, $concurrency);
    }

    /**
     * HEADs many resources at once — for a server whose container listing
     * gives no modification time ({@see ContainerMember::$modified}).
     *
     * @param list<string> $urls
     *
     * @return \Generator<string, PodResource|PodRequestFailed>
     */
    public function headMany(array $urls, int $concurrency = 16): \Generator
    {
        return $this->many('HEAD', $urls, self::TURTLE, $concurrency);
    }

    /**
     * @param list<string> $urls
     *
     * @return \Generator<string, PodResource|PodRequestFailed>
     */
    private function many(string $method, array $urls, string $accept, int $concurrency): \Generator
    {
        foreach (array_chunk($urls, max(1, $concurrency)) as $chunk) {
            // Started together: Symfony's client sends them before the first
            // status is read, and reads them in whatever order they finish.
            $started = [];
            foreach ($chunk as $url) {
                $started[$url] = $this->start($method, $url, ['Accept' => $accept]);
            }
            foreach ($started as $url => $response) {
                $url = (string) $url;
                try {
                    $response = $this->retryWithNonce($method, $url, ['Accept' => $accept], $response);
                    $this->guard($method, $url, $response);
                    yield $url => $this->resource($url, $response, $method === 'HEAD' ? '' : $response->getContent(false));
                } catch (PodRequestFailed $e) {
                    yield $url => $e;
                }
            }
        }
    }

    /**
     * @param iterable<PodWrite> $writes
     *
     * @return \Generator<mixed, PodWrite>
     */
    private static function generate(iterable $writes): \Generator
    {
        yield from $writes;
    }

    private function startWrite(PodWrite $write, ?string $dpopNonce = null): ResponseInterface
    {
        return $this->http->request(
            $write->method,
            $write->url,
            $this->options($write->method, $write->url, $write->headers(), $write->body, $dpopNonce),
        );
    }

    /** Status 0 is a request that never got an answer (connection refused, timed out). */
    private function outcome(PodWrite $write, ResponseInterface $response): Written|PodRequestFailed
    {
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            return new PodRequestFailed($write->method, $write->url, 0, $e->getMessage());
        }
        if ($status < 200 || $status >= 300) {
            return PodRequestFailed::from($write->method, $write->url, $status, $response->getContent(false));
        }
        $headers = $response->getHeaders(false);

        return new Written($write->url, $status, $headers['etag'][0] ?? null, $headers['location'][0] ?? null);
    }

    /** @param array<string, string> $headers */
    private function write(string $method, string $url, ?string $body, array $headers): Written
    {
        $response = $this->send($method, $url, $headers, $body);
        $this->guard($method, $url, $response);
        $responseHeaders = $response->getHeaders(false);

        return new Written(
            $url,
            $response->getStatusCode(),
            $responseHeaders['etag'][0] ?? null,
            $responseHeaders['location'][0] ?? null,
        );
    }

    /**
     * One request, retried once when the server asks for a DPoP nonce
     * (RFC 9449 §9) — the only retry this client ever makes.
     *
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, array $headers, ?string $body = null): ResponseInterface
    {
        return $this->retryWithNonce($method, $url, $headers, $this->start($method, $url, $headers, $body), $body);
    }

    /** @param array<string, string> $headers */
    private function start(string $method, string $url, array $headers, ?string $body = null): ResponseInterface
    {
        return $this->http->request($method, $url, $this->options($method, $url, $headers, $body));
    }

    /** @param array<string, string> $headers */
    private function retryWithNonce(
        string $method,
        string $url,
        array $headers,
        ResponseInterface $response,
        ?string $body = null,
    ): ResponseInterface {
        $nonce = $response->getHeaders(false)['dpop-nonce'][0] ?? null;
        if ($response->getStatusCode() === 401 && $nonce !== null) {
            $response = $this->http->request($method, $url, $this->options($method, $url, $headers, $body, $nonce));
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function options(string $method, string $url, array $headers, ?string $body, ?string $nonce = null): array
    {
        $options = ['max_redirects' => 0];
        if ($body !== null) {
            $options['body'] = $body;
        }
        $options['headers'] = [...$headers, ...$this->credentials->headersFor($method, $url, $nonce)];

        return $options;
    }

    private function guard(string $method, string $url, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw PodRequestFailed::from($method, $url, $status, $method === 'HEAD' ? '' : $response->getContent(false));
    }

    private function resource(string $url, ResponseInterface $response, string $body): PodResource
    {
        $headers = $response->getHeaders(false);

        return new PodResource(
            $url,
            $response->getStatusCode(),
            $headers['etag'][0] ?? null,
            $headers['content-type'][0] ?? null,
            $body,
            $headers['link'] ?? [],
        );
    }
}
