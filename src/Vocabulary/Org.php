<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Org
{
    public const string PREFIX = 'org';
    public const string NS = 'http://www.w3.org/ns/org#';

    public const string ORGANIZATION = self::NS . 'Organization';
    public const string MEMBERSHIP = self::NS . 'Membership';
    public const string MEMBER = self::NS . 'member';
    public const string ORGANIZATION_PROPERTY = self::NS . 'organization';
    public const string ROLE = self::NS . 'role';
    public const string CLASSIFICATION = self::NS . 'classification';
    public const string SUB_ORGANIZATION_OF = self::NS . 'subOrganizationOf';
}
