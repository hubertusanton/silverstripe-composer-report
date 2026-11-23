<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Client;

/**
 * Exit codes of the client. Precedence when several apply: 1, 3, 2, 0.
 */
final class ExitCode
{
    public const SUCCESS = 0;
    public const CONFIG_ERROR = 1;
    public const COMPOSER_ERROR = 2;
    public const HTTP_ERROR = 3;
}
