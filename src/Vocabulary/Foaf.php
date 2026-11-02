<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Foaf
{
    public const string PREFIX = 'foaf';
    public const string NS = 'http://xmlns.com/foaf/0.1/';

    public const string PERSON = self::NS . 'Person';
    public const string NAME = self::NS . 'name';
    public const string GIVEN_NAME = self::NS . 'givenName';
    public const string FAMILY_NAME = self::NS . 'familyName';
    public const string DOCUMENT = self::NS . 'Document';
}
