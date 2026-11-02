<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Rdfs
{
    public const string PREFIX = 'rdfs';
    public const string NS = 'http://www.w3.org/2000/01/rdf-schema#';

    public const string LABEL = self::NS . 'label';
    public const string COMMENT = self::NS . 'comment';
    public const string SUB_CLASS_OF = self::NS . 'subClassOf';
    public const string SUB_PROPERTY_OF = self::NS . 'subPropertyOf';
    public const string SEE_ALSO = self::NS . 'seeAlso';
}
