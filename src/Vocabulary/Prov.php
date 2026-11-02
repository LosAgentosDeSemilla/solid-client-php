<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Prov
{
    public const string PREFIX = 'prov';
    public const string NS = 'http://www.w3.org/ns/prov#';

    public const string ACTIVITY = self::NS . 'Activity';
    public const string AGENT = self::NS . 'Agent';
    public const string SOFTWARE_AGENT = self::NS . 'SoftwareAgent';
    public const string WAS_ATTRIBUTED_TO = self::NS . 'wasAttributedTo';
    public const string WAS_DERIVED_FROM = self::NS . 'wasDerivedFrom';
    public const string WAS_GENERATED_BY = self::NS . 'wasGeneratedBy';
    public const string USED = self::NS . 'used';
    public const string ENDED_AT_TIME = self::NS . 'endedAtTime';
    public const string WAS_ASSOCIATED_WITH = self::NS . 'wasAssociatedWith';
    public const string ACTED_ON_BEHALF_OF = self::NS . 'actedOnBehalfOf';
}
