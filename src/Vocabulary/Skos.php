<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Skos
{
    public const string PREFIX = 'skos';
    public const string NS = 'http://www.w3.org/2004/02/skos/core#';

    public const string CONCEPT = self::NS . 'Concept';
    public const string CONCEPT_SCHEME = self::NS . 'ConceptScheme';
    public const string PREF_LABEL = self::NS . 'prefLabel';
    public const string ALT_LABEL = self::NS . 'altLabel';
    public const string BROADER = self::NS . 'broader';
    public const string IN_SCHEME = self::NS . 'inScheme';
    public const string NOTATION = self::NS . 'notation';
    public const string EXACT_MATCH = self::NS . 'exactMatch';
    public const string TOP_CONCEPT_OF = self::NS . 'topConceptOf';
}
