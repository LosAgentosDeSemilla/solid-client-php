<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Rdf;

use EasyRdf\Graph;
use Semilla\SolidClient\SolidClientException;

/**
 * One RDF document read from a pod as plain triples, reduced to the questions this package
 * asks of one: "which resources does X point to over P" and "what is of type
 * T". Parsing is EasyRdf's (sweetrdf fork); nothing of its API leaks out.
 *
 * Turtle only: every request this package makes asks for `text/turtle`, which
 * a Solid server must serve for every RDF resource (Solid Protocol §5.2).
 */
final readonly class Triples
{
    public const string RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    /** @param array<string, array<string, list<array{type: string, value: string}>>> $triples */
    private function __construct(
        public string $url,
        private array $triples,
    ) {
    }

    public static function parseTurtle(string $turtle, string $baseUrl): self
    {
        $graph = new Graph($baseUrl);
        try {
            $graph->parse($turtle, 'turtle', $baseUrl);
        } catch (\Throwable $e) {
            throw new SolidClientException(sprintf('%s is not valid Turtle: %s', $baseUrl, $e->getMessage()), 0, $e);
        }

        /** @var array<string, array<string, list<array{type: string, value: string}>>> $triples */
        $triples = $graph->toRdfPhp();

        return new self($baseUrl, $triples);
    }

    /** @return list<string> the IRIs `$subject` points to over `$predicate`, literals and blank nodes left out */
    public function objects(string $subject, string $predicate): array
    {
        $objects = [];
        foreach ($this->triples[$subject][$predicate] ?? [] as $object) {
            if ($object['type'] === 'uri') {
                $objects[] = $object['value'];
            }
        }

        return array_values(array_unique($objects));
    }

    /** The lexical form of the first literal `$subject` has over `$predicate`, whatever its datatype. */
    public function firstLiteral(string $subject, string $predicate): ?string
    {
        foreach ($this->triples[$subject][$predicate] ?? [] as $object) {
            if ($object['type'] === 'literal') {
                return $object['value'];
            }
        }

        return null;
    }

    public function firstObject(string $subject, string $predicate): ?string
    {
        return $this->objects($subject, $predicate)[0] ?? null;
    }

    /** @return list<string> subjects (IRIs and blank nodes alike) typed `$type` */
    public function subjectsOfType(string $type): array
    {
        $subjects = [];
        foreach ($this->triples as $subject => $predicates) {
            foreach ($predicates[self::RDF_TYPE] ?? [] as $object) {
                if ($object['type'] === 'uri' && $object['value'] === $type) {
                    $subjects[] = (string) $subject;
                }
            }
        }

        return $subjects;
    }

    /** @return list<string> every subject with at least one `$predicate` */
    public function subjectsWith(string $predicate): array
    {
        $subjects = [];
        foreach ($this->triples as $subject => $predicates) {
            if (isset($predicates[$predicate])) {
                $subjects[] = (string) $subject;
            }
        }

        return $subjects;
    }

    public function isA(string $subject, string $type): bool
    {
        return \in_array($type, $this->objects($subject, self::RDF_TYPE), true);
    }
}
