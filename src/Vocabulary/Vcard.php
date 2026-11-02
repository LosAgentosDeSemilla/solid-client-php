<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

/** The W3C vCard ontology (https://www.w3.org/TR/vcard-rdf/), as the SolidOS contacts pane writes it. */
final class Vcard
{
    public const string PREFIX = 'vcard';
    public const string NS = 'http://www.w3.org/2006/vcard/ns#';

    public const string INDIVIDUAL = self::NS . 'Individual';
    public const string GROUP = self::NS . 'Group';
    public const string ADDRESS_BOOK = self::NS . 'AddressBook';
    public const string FN = self::NS . 'fn';
    public const string GIVEN_NAME = self::NS . 'given-name';
    public const string FAMILY_NAME = self::NS . 'family-name';
    public const string NICKNAME = self::NS . 'nickname';
    public const string HAS_UID = self::NS . 'hasUID';
    public const string HAS_TELEPHONE = self::NS . 'hasTelephone';
    public const string HAS_EMAIL = self::NS . 'hasEmail';
    public const string HAS_MEMBER = self::NS . 'hasMember';
    public const string VALUE = self::NS . 'value';
    public const string TELEPHONE = self::NS . 'TelephoneType';
    public const string EMAIL = self::NS . 'Email';
}
