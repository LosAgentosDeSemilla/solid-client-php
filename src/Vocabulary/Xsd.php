<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Xsd
{
    public const string PREFIX = 'xsd';
    public const string NS = 'http://www.w3.org/2001/XMLSchema#';

    public const string STRING = self::NS . 'string';
    public const string DATE = self::NS . 'date';
    public const string DATE_TIME = self::NS . 'dateTime';
    public const string INTEGER = self::NS . 'integer';
    public const string DECIMAL = self::NS . 'decimal';
    public const string BOOLEAN = self::NS . 'boolean';
}
