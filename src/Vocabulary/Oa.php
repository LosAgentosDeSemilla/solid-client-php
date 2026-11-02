<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Oa
{
    public const string PREFIX = 'oa';
    public const string NS = 'http://www.w3.org/ns/oa#';

    public const string ANNOTATION = self::NS . 'Annotation';
    public const string ASSESSING = self::NS . 'assessing';
    public const string MOTIVATED_BY = self::NS . 'motivatedBy';
    public const string HAS_TARGET = self::NS . 'hasTarget';
    public const string HAS_BODY = self::NS . 'hasBody';
    public const string BODY_VALUE = self::NS . 'bodyValue';
}
