<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Rdf;

use Semilla\SolidClient\Vocabulary\Rdf;
use Semilla\SolidClient\Vocabulary\Xsd;

/**
 * The Turtle of one {@see RdfDocument}, written here rather than by EasyRdf's
 * serialiser, which took ~15 ms for one vote event (1 400 triples) — the bulk
 * of an import of ~11 000 of them; this takes ~1.5 ms. Our documents need
 * none of what makes that one slow (nested blank nodes, collections): one
 * block per subject, predicates and objects in the order they were added,
 * our vocabularies' prefixes where the local name is plain, everything else a
 * full IRI. The same graph is always written the same way.
 */
final class TurtleWriter
{
    private const string PLAIN_LOCAL_NAME = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    private const string NOT_IN_IRIREF = '/[\x00-\x20<>"{}|^`\\\\]/';

    /** @var array<string, string> namespace => prefix */
    private array $prefixOf = [];

    /** @var array<string, string> prefixes used so far, prefix => namespace */
    private array $used = [];

    /** @var array<string, string> IRI => how it is written, since the same few recur thousands of times */
    private array $written = [];

    /** @param iterable<class-string> $vocabularies classes with `PREFIX` and `NS` constants */
    public function __construct(iterable $vocabularies)
    {
        foreach ($vocabularies as $vocabulary) {
            $namespace = \constant($vocabulary . '::NS');
            $prefix = \constant($vocabulary . '::PREFIX');
            if (\is_string($namespace) && \is_string($prefix)) {
                $this->prefixOf[$namespace] = $prefix;
            }
        }
    }

    public function subject(string $subject): string
    {
        return $this->node($subject);
    }

    public function predicate(string $predicate): string
    {
        return $predicate === Rdf::TYPE ? 'a' : $this->iri($predicate);
    }

    public function node(string $node): string
    {
        return str_starts_with($node, '_:') ? $node : $this->iri($node);
    }

    public function literal(string $value, ?string $language, ?string $datatype): string
    {
        if (
            ($datatype === Xsd::INTEGER && preg_match('/^[+-]?\d+$/', $value) === 1)
            || ($datatype === Xsd::DECIMAL && preg_match('/^[+-]?\d*\.\d+$/', $value) === 1)
        ) {
            return $value;
        }

        $quoted = self::quoted($value);

        return match (true) {
            $language !== null && $language !== '' => $quoted . '@' . $language,
            $datatype !== null && $datatype !== Xsd::STRING => $quoted . '^^' . $this->iri($datatype),
            default => $quoted,
        };
    }

    /** The prefix declarations for what has been written so far. */
    public function prefixes(): string
    {
        $head = '';
        foreach ($this->used as $prefix => $namespace) {
            $head .= sprintf("@prefix %s: <%s> .\n", $prefix, $namespace);
        }

        return $head;
    }

    private function iri(string $iri): string
    {
        return $this->written[$iri] ??= $this->write($iri);
    }

    private function write(string $iri): string
    {
        foreach ($this->prefixOf as $namespace => $prefix) {
            if (str_starts_with($iri, $namespace)) {
                $local = substr($iri, \strlen($namespace));
                if (preg_match(self::PLAIN_LOCAL_NAME, $local) === 1) {
                    $this->used[$prefix] = $namespace;

                    return $prefix . ':' . $local;
                }
            }
        }

        if (preg_match(self::NOT_IN_IRIREF, $iri) !== 1) {
            return '<' . $iri . '>';
        }
        $escaped = '';
        foreach (str_split($iri) as $char) {
            $escaped .= preg_match(self::NOT_IN_IRIREF, $char) === 1 ? sprintf('\\u%04X', \ord($char)) : $char;
        }

        return '<' . $escaped . '>';
    }

    /**
     * JSON's string escapes are a subset of Turtle's, all but one: the backslash
     * goes as `\u005C`, not `\\`, which EasyRdf's parser never unescapes
     * (sweetrdf/easyrdf 1.x). In JSON's output `\\` only ever stands for one.
     */
    private static function quoted(string $value): string
    {
        $json = json_encode(
            $value,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );

        return str_replace('\\\\', '\\u005C', $json);
    }
}
