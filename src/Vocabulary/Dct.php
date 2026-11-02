<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Dct
{
    public const string PREFIX = 'dct';
    public const string NS = 'http://purl.org/dc/terms/';

    public const string IDENTIFIER = self::NS . 'identifier';
    public const string SUBJECT = self::NS . 'subject';
    public const string DATE = self::NS . 'date';
    public const string CREATED = self::NS . 'created';
    public const string MODIFIED = self::NS . 'modified';
    public const string CREATOR = self::NS . 'creator';
    public const string SOURCE = self::NS . 'source';
    public const string TITLE = self::NS . 'title';
}
