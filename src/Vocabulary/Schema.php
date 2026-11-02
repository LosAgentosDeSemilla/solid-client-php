<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Schema
{
    public const string PREFIX = 'schema';
    public const string NS = 'http://schema.org/';

    public const string CLAIM = self::NS . 'Claim';
    public const string EVENT = self::NS . 'Event';
    public const string NAME = self::NS . 'name';
    public const string DESCRIPTION = self::NS . 'description';
    public const string TEXT = self::NS . 'text';
    public const string START_DATE = self::NS . 'startDate';
    public const string END_DATE = self::NS . 'endDate';
    public const string BIRTH_DATE = self::NS . 'birthDate';
    public const string HONORIFIC_PREFIX = self::NS . 'honorificPrefix';
    public const string HONORIFIC_SUFFIX = self::NS . 'honorificSuffix';
    public const string VALID_FROM = self::NS . 'validFrom';
    public const string VALID_UNTIL = self::NS . 'validUntil';
    public const string FOUNDING_DATE = self::NS . 'foundingDate';
    public const string DISSOLUTION_DATE = self::NS . 'dissolutionDate';
    public const string ORGANIZER = self::NS . 'organizer';
    public const string AGENT = self::NS . 'agent';
    public const string OPTION = self::NS . 'option';
    public const string ABOUT = self::NS . 'about';
}
