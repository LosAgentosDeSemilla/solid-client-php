<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

use Semilla\SolidClient\SolidClientException;

/** A pod answered with a status the operation cannot go on from. */
class PodRequestFailed extends SolidClientException
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $status,
        string $detail = '',
    ) {
        parent::__construct(
            sprintf('%s %s answered %d%s', $method, $url, $status, $detail === '' ? '' : ': ' . $detail),
            $status,
        );
    }

    public static function from(string $method, string $url, int $status, string $body): self
    {
        $detail = mb_substr(trim($body), 0, 300);

        return match (true) {
            $status === 401, $status === 403 => new AccessDenied($method, $url, $status, $detail),
            $status === 404 => new NotFound($method, $url, $status, $detail),
            $status === 409 => new Conflict($method, $url, $status, $detail),
            $status === 412 => new PreconditionFailed($method, $url, $status, $detail),
            default => new self($method, $url, $status, $detail),
        };
    }
}
