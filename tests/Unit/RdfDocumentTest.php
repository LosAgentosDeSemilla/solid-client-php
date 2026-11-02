<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Rdf\Iri;
use Semilla\SolidClient\Rdf\MalformedDocument;
use Semilla\SolidClient\Rdf\RdfDocument;
use Semilla\SolidClient\TypeIndex\Registration;
use Semilla\SolidClient\TypeIndex\Registrations;
use Semilla\SolidClient\TypeIndex\TypeIndexRegistrar;
use Semilla\SolidClient\Vocabulary\Schema;
use Semilla\SolidClient\Vocabulary\Solid;
use Semilla\SolidClient\Vocabulary\Vcard;

/** RdfDocument writes its own Turtle (for speed); whatever goes in reads back the same. */
final class RdfDocumentTest extends TestCase
{
    protected function tearDown(): void
    {
        RdfDocument::speak('cs');
    }

    public function testAwkwardValuesSurviveTheRoundTrip(): void
    {
        $iri = new Iri('https://pod.example/alice/doc');
        $thing = $iri->withFragment('it');
        $text = "Řekl: \"ne\"\\ano\na\tpak\r — 🗳️";
        $document = RdfDocument::create($iri)
            ->addType($thing, Schema::CLAIM)
            ->addCzech($thing, Schema::TEXT, $text)
            ->addString($thing, Schema::NAME, 'plain')
            ->addInteger($thing, ExampleVocabulary::POSITION, -12)
            ->addDecimal($thing, ExampleVocabulary::VALUE, '-0.8')
            ->addBoolean($thing, ExampleVocabulary::FREE, false)
            ->addDate($thing, Schema::START_DATE, \DateTimeImmutable::createFromFormat('!Y-m-d', '2025-12-10') ?: null)
            ->addIri($thing, ExampleVocabulary::PAGE, 'https://www.psp.cz/sqw/hlasy.sqw?g=84123&x=a%20b')
            ->addIri($thing, Schema::ABOUT, 'https://example.org/odd/a.b');

        $turtle = $document->toTurtle();
        $read = RdfDocument::fromTurtle($turtle, $iri);

        self::assertTrue($read->isIsomorphicTo($document), $turtle);
        self::assertSame($text, $read->string($thing, Schema::TEXT));
        self::assertSame(-12, $read->integer($thing, ExampleVocabulary::POSITION));
        self::assertSame(-0.8, $read->decimal($thing, ExampleVocabulary::VALUE));
        self::assertFalse($read->boolean($thing, ExampleVocabulary::FREE));
        self::assertStringContainsString('schema:Claim', $turtle);
        self::assertStringContainsString('<https://example.org/odd/a.b>', $turtle);
    }

    public function testTheSameGraphIsWrittenTheSameWay(): void
    {
        $build = static fn (): RdfDocument => RdfDocument::create(new Iri('https://pod.example/alice/doc'))
            ->addType(new Iri('https://pod.example/alice/doc#a'), Schema::CLAIM)
            ->addString(new Iri('https://pod.example/alice/doc#a'), Schema::NAME, 'x');

        self::assertSame($build()->toTurtle(), $build()->toTurtle());
    }

    public function testTheApplicationNamesItsLanguageAndItsVocabularies(): void
    {
        RdfDocument::speak('en', ExampleVocabulary::class);
        $iri = new Iri('https://pod.example/alice/doc');
        $document = RdfDocument::create($iri)
            ->addType($iri->withFragment('it'), ExampleVocabulary::NS . 'Thing')
            ->addCzech($iri->withFragment('it'), Schema::NAME, 'thing');

        $turtle = $document->toTurtle();

        self::assertStringContainsString('ex:Thing', $turtle);
        self::assertStringContainsString('"thing"@en', $turtle);
        self::assertSame('thing', RdfDocument::fromTurtle($turtle, $iri)->string($iri->withFragment('it'), Schema::NAME));
    }

    public function testGarbageIsAMalformedDocument(): void
    {
        $this->expectException(MalformedDocument::class);

        RdfDocument::fromTurtle('this is <not turtle', new Iri('https://pod.example/alice/x'));
    }

    public function testAnIriCannotSmuggleSyntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Iri('https://example.org/> . <x');
    }

    public function testRegistrationsAreTriplesOfATypeIndex(): void
    {
        $index = new Iri('https://pod.example/alice/settings/privateTypeIndex.ttl');
        $groups = new Iri('https://pod.example/alice/contacts/groups/');

        $rdf = TypeIndexRegistrar::toRdf($index, new Registrations(new Registration('contacts-groups', Vcard::GROUP, $groups, true)), false);

        $registration = $index->withFragment('contacts-groups');
        self::assertTrue($rdf->hasType($index, Solid::UNLISTED_DOCUMENT));
        self::assertTrue($rdf->hasType($registration, Solid::TYPE_REGISTRATION));
        self::assertTrue($groups->equals($rdf->requiredIri($registration, Solid::INSTANCE_CONTAINER)));
    }
}

final class ExampleVocabulary
{
    public const string PREFIX = 'ex';
    public const string NS = 'https://example.org/ns#';
    public const string POSITION = self::NS . 'position';
    public const string VALUE = self::NS . 'value';
    public const string FREE = self::NS . 'free';
    public const string PAGE = self::NS . 'page';
}
