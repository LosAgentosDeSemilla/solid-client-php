<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Rdf
{
    public const string PREFIX = 'rdf';
    public const string NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    public const string TYPE = self::NS . 'type';
    public const string VALUE = self::NS . 'value';
    public const string STATEMENT = self::NS . 'Statement';
    public const string SUBJECT = self::NS . 'subject';
    public const string PREDICATE = self::NS . 'predicate';
    public const string OBJECT = self::NS . 'object';
}
