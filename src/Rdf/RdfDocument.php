<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Rdf;

use EasyRdf\Graph;
use EasyRdf\Isomorphic;
use EasyRdf\Literal;
use EasyRdf\RdfNamespace;
use EasyRdf\Resource;
use Semilla\SolidClient\Vocabulary\Dct;
use Semilla\SolidClient\Vocabulary\Foaf;
use Semilla\SolidClient\Vocabulary\Oa;
use Semilla\SolidClient\Vocabulary\Org;
use Semilla\SolidClient\Vocabulary\Owl;
use Semilla\SolidClient\Vocabulary\Prov;
use Semilla\SolidClient\Vocabulary\Rdf;
use Semilla\SolidClient\Vocabulary\Rdfs;
use Semilla\SolidClient\Vocabulary\Schema;
use Semilla\SolidClient\Vocabulary\Skos;
use Semilla\SolidClient\Vocabulary\Solid;
use Semilla\SolidClient\Vocabulary\Vcard;
use Semilla\SolidClient\Vocabulary\Xsd;

/**
 * One RDF document of a pod — the unit that is written, fetched and compared by ETag.
 *
 * The one place that knows EasyRdf: serializers speak IRIs, vocabulary constants
 * and PHP values, so the library can be swapped behind this class. Protocol
 * documents this package reads for itself (profiles, Type Indexes, container
 * listings, ACRs) are {@see Triples}, which keep blank nodes and ignore language.
 *
 * Two things are the application's, and set once at boot ({@see speak()}): the
 * language of its human-readable text, and the vocabularies whose prefixes the
 * written Turtle uses. EasyRdf keeps prefixes in a process-wide registry, so
 * this does too; an application that never calls it writes Czech text and the
 * standard vocabularies' prefixes.
 */
final class RdfDocument
{
    /** The vocabularies of this package, always among the prefixes. */
    public const array STANDARD_VOCABULARIES = [
        Dct::class, Foaf::class, Oa::class, Org::class, Owl::class, Prov::class, Rdf::class, Rdfs::class,
        Schema::class, Skos::class, Solid::class, Vcard::class, Xsd::class,
    ];

    private static string $language = 'cs';

    /** @var list<class-string> */
    private static array $vocabularies = self::STANDARD_VOCABULARIES;

    private static bool $prefixesRegistered = false;

    private function __construct(public readonly Iri $iri, private readonly Graph $graph)
    {
    }

    /**
     * The application's language and its own vocabularies (classes with `PREFIX`
     * and `NS` constants), beside {@see STANDARD_VOCABULARIES}. Call it once, at
     * boot, before any document is made.
     *
     * @param class-string ...$vocabularies
     */
    public static function speak(string $language, string ...$vocabularies): void
    {
        self::$language = $language;
        self::$vocabularies = array_values(array_unique([...self::STANDARD_VOCABULARIES, ...$vocabularies]));
        self::$prefixesRegistered = false;
    }

    public static function language(): string
    {
        return self::$language;
    }

    public static function create(Iri $iri): self
    {
        self::registerPrefixes();

        return new self($iri, new Graph($iri->value));
    }

    /** @throws MalformedDocument */
    public static function fromTurtle(string $turtle, Iri $iri): self
    {
        $document = self::create($iri);
        try {
            $document->graph->parse($turtle, 'turtle', $iri->value);
        } catch (\Throwable $e) {
            throw MalformedDocument::unparsable($iri, $e);
        }

        return $document;
    }

    /** Our own writer ({@see TurtleWriter}): EasyRdf's serialiser was most of an import's time. */
    public function toTurtle(): string
    {
        $writer = new TurtleWriter(self::$vocabularies);
        $body = '';
        /** @var array<string, array<string, list<array{type: string, value: string, lang?: string, datatype?: string}>>> $triples */
        $triples = $this->graph->toRdfPhp();
        foreach ($triples as $subject => $predicates) {
            $statements = [];
            foreach ($predicates as $predicate => $objects) {
                $terms = [];
                foreach ($objects as $object) {
                    $terms[] = $object['type'] === 'literal'
                        ? $writer->literal($object['value'], $object['lang'] ?? null, $object['datatype'] ?? null)
                        : $writer->node($object['value']);
                }
                $statements[] = $writer->predicate($predicate) . ' ' . implode(', ', $terms);
            }
            $body .= $writer->subject($subject) . "\n  " . implode(" ;\n  ", $statements) . " .\n\n";
        }
        $head = $writer->prefixes();

        return $head === '' ? $body : $head . "\n" . $body;
    }

    /** One absolute triple per line, no prefixes: what goes inside the formulas of an N3 Patch. */
    public function toNTriples(): string
    {
        $triples = $this->graph->serialise('ntriples');
        assert(is_string($triples));

        return $triples;
    }

    public function countTriples(): int
    {
        return $this->graph->countTriples();
    }

    /** Same triples up to blank node names — what "round-trip" means here. */
    public function isIsomorphicTo(self $other): bool
    {
        return Isomorphic::isomorphic($this->graph, $other->graph);
    }

    /** The same document without any triple of `$predicate`: to compare two versions apart from a timestamp. */
    public function without(string $predicate): self
    {
        $copy = self::fromTurtle($this->toTurtle(), $this->iri);
        foreach ($copy->graph->resources() as $resource) {
            $copy->graph->delete($resource, self::path($predicate));
        }

        return $copy;
    }

    // ---- writing ----------------------------------------------------------

    public function addType(Iri $subject, string ...$types): self
    {
        foreach ($types as $type) {
            $this->graph->addResource($subject->value, Rdf::TYPE, $type);
        }

        return $this;
    }

    public function addIri(Iri $subject, string $predicate, Iri|string|null $object): self
    {
        if ($object !== null) {
            $this->graph->addResource($subject->value, $predicate, (string) $object);
        }

        return $this;
    }

    public function addIris(Iri $subject, string $predicate, Iris $objects): self
    {
        foreach ($objects as $object) {
            $this->addIri($subject, $predicate, $object);
        }

        return $this;
    }

    /** A plain string: names, identifiers, codes. */
    public function addString(Iri $subject, string $predicate, ?string $value): self
    {
        return $value === null ? $this : $this->addLiteral($subject, $predicate, new Literal($value));
    }

    /** Human-readable text in the application's language ({@see speak()}): titles, descriptions, quotes. */
    public function addCzech(Iri $subject, string $predicate, ?string $value): self
    {
        return $value === null ? $this : $this->addLiteral($subject, $predicate, new Literal($value, self::$language));
    }

    public function addDate(Iri $subject, string $predicate, ?\DateTimeImmutable $value): self
    {
        return $value === null ? $this : $this->addTyped($subject, $predicate, $value->format('Y-m-d'), Xsd::DATE);
    }

    public function addDateTime(Iri $subject, string $predicate, ?\DateTimeImmutable $value): self
    {
        return $value === null
            ? $this
            : $this->addTyped($subject, $predicate, $value->format(\DateTimeInterface::ATOM), Xsd::DATE_TIME);
    }

    public function addInteger(Iri $subject, string $predicate, ?int $value): self
    {
        return $value === null ? $this : $this->addTyped($subject, $predicate, (string) $value, Xsd::INTEGER);
    }

    public function addBoolean(Iri $subject, string $predicate, ?bool $value): self
    {
        return $value === null ? $this : $this->addTyped($subject, $predicate, $value ? 'true' : 'false', Xsd::BOOLEAN);
    }

    /** @param string $lexical an xsd:decimal lexical form, always with a decimal point */
    public function addDecimal(Iri $subject, string $predicate, string $lexical): self
    {
        if (preg_match('/^-?\d+\.\d+$/', $lexical) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a canonical decimal', $lexical));
        }

        return $this->addTyped($subject, $predicate, $lexical, Xsd::DECIMAL);
    }

    /** A literal of a datatype of somebody else's vocabulary (`g7:type-Date`). */
    public function addTyped(Iri $subject, string $predicate, ?string $lexical, string $datatype): self
    {
        return $lexical === null ? $this : $this->addLiteral($subject, $predicate, new Literal($lexical, null, $datatype));
    }

    // ---- reading ----------------------------------------------------------

    public function hasType(Iri $subject, string $type): bool
    {
        return $this->graph->isA($subject->value, $type);
    }

    /** Named subjects of a type, sorted so documents read back deterministically. */
    public function subjectsOfType(string $type): Iris
    {
        $iris = [];
        foreach ($this->graph->allOfType($type) as $resource) {
            if ($resource instanceof Resource && !$resource->isBNode()) {
                $iris[] = new Iri($resource->getUri());
            }
        }

        return (new Iris(...$iris))->sorted();
    }

    public function iris(Iri $subject, string $predicate): Iris
    {
        $iris = [];
        foreach ($this->graph->allResources($subject->value, self::path($predicate)) as $resource) {
            if (!$resource->isBNode()) {
                $iris[] = new Iri($resource->getUri());
            }
        }

        return (new Iris(...$iris))->sorted();
    }

    public function iri(Iri $subject, string $predicate): ?Iri
    {
        $iris = $this->iris($subject, $predicate);
        if (count($iris) > 1) {
            throw MalformedDocument::invalid($this->iri, $subject->value, $predicate, 'more than one value');
        }

        foreach ($iris as $iri) {
            return $iri;
        }

        return null;
    }

    public function requiredIri(Iri $subject, string $predicate): Iri
    {
        return $this->iri($subject, $predicate)
            ?? throw MalformedDocument::missing($this->iri, $subject->value, $predicate);
    }

    /** The one untagged literal or one in our language; another language is somebody else's translation, not ours. */
    public function string(Iri $subject, string $predicate): ?string
    {
        $values = [];
        foreach ($this->graph->allLiterals($subject->value, self::path($predicate)) as $literal) {
            $lang = $literal->getLang();
            if ($lang === null || $lang === '' || $lang === self::$language) {
                $values[] = (string) $literal;
            }
        }

        if (count($values) > 1) {
            throw MalformedDocument::invalid($this->iri, $subject->value, $predicate, 'more than one value');
        }

        return $values[0] ?? null;
    }

    public function requiredString(Iri $subject, string $predicate): string
    {
        return $this->string($subject, $predicate)
            ?? throw MalformedDocument::missing($this->iri, $subject->value, $predicate);
    }

    public function date(Iri $subject, string $predicate): ?\DateTimeImmutable
    {
        $lexical = $this->string($subject, $predicate);
        if ($lexical === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $lexical, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $lexical
            ? $date
            : throw MalformedDocument::invalid($this->iri, $subject->value, $predicate, 'not an xsd:date');
    }

    public function requiredDate(Iri $subject, string $predicate): \DateTimeImmutable
    {
        return $this->date($subject, $predicate)
            ?? throw MalformedDocument::missing($this->iri, $subject->value, $predicate);
    }

    public function dateTime(Iri $subject, string $predicate): ?\DateTimeImmutable
    {
        $lexical = $this->string($subject, $predicate);
        if ($lexical === null) {
            return null;
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $lexical);

        return $dateTime !== false
            ? $dateTime
            : throw MalformedDocument::invalid($this->iri, $subject->value, $predicate, 'not an xsd:dateTime with offset');
    }

    public function requiredDateTime(Iri $subject, string $predicate): \DateTimeImmutable
    {
        return $this->dateTime($subject, $predicate)
            ?? throw MalformedDocument::missing($this->iri, $subject->value, $predicate);
    }

    public function integer(Iri $subject, string $predicate): ?int
    {
        $lexical = $this->string($subject, $predicate);
        if ($lexical === null) {
            return null;
        }

        return preg_match('/^[+-]?\d+$/', $lexical) === 1
            ? (int) $lexical
            : throw MalformedDocument::invalid($this->iri, $subject->value, $predicate, 'not an integer');
    }

    public function requiredInteger(Iri $subject, string $predicate): int
    {
        return $this->integer($subject, $predicate)
            ?? throw MalformedDocument::missing($this->iri, $subject->value, $predicate);
    }

    public function boolean(Iri $subject, string $predicate): ?bool
    {
        return match ($this->string($subject, $predicate)) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw MalformedDocument::invalid($this->iri, $subject->value, $predicate, 'not an xsd:boolean'),
        };
    }

    public function requiredDecimal(Iri $subject, string $predicate): float
    {
        return $this->decimal($subject, $predicate)
            ?? throw MalformedDocument::missing($this->iri, $subject->value, $predicate);
    }

    public function decimal(Iri $subject, string $predicate): ?float
    {
        $lexical = $this->string($subject, $predicate);
        if ($lexical === null) {
            return null;
        }

        return is_numeric($lexical)
            ? (float) $lexical
            : throw MalformedDocument::invalid($this->iri, $subject->value, $predicate, 'not a decimal');
    }

    /** EasyRdf reads its arguments as property paths, where a bare `http:…` would be a prefixed name. */
    private static function path(string $predicate): string
    {
        return '<' . $predicate . '>';
    }

    private function addLiteral(Iri $subject, string $predicate, Literal $literal): self
    {
        $this->graph->addLiteral($subject->value, $predicate, $literal);

        return $this;
    }

    /** EasyRdf keeps prefixes in a process-wide registry; these make the written Turtle readable. */
    private static function registerPrefixes(): void
    {
        if (self::$prefixesRegistered) {
            return;
        }

        // EasyRdf's defaults spell Dublin Core terms `dc:`; the rest of the ecosystem writes `dct:`.
        RdfNamespace::delete('dc');
        RdfNamespace::delete('dcterms');
        foreach (self::$vocabularies as $vocabulary) {
            RdfNamespace::set($vocabulary::PREFIX, $vocabulary::NS);
        }
        self::$prefixesRegistered = true;
    }
}
