<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Solid
{
    public const string PREFIX = 'solid';
    public const string NS = 'http://www.w3.org/ns/solid/terms#';

    public const string TYPE_INDEX = self::NS . 'TypeIndex';
    public const string LISTED_DOCUMENT = self::NS . 'ListedDocument';
    public const string UNLISTED_DOCUMENT = self::NS . 'UnlistedDocument';
    public const string TYPE_REGISTRATION = self::NS . 'TypeRegistration';
    public const string FOR_CLASS = self::NS . 'forClass';
    public const string INSTANCE = self::NS . 'instance';
    public const string INSTANCE_CONTAINER = self::NS . 'instanceContainer';
    public const string PUBLIC_TYPE_INDEX = self::NS . 'publicTypeIndex';
    public const string PRIVATE_TYPE_INDEX = self::NS . 'privateTypeIndex';
}
