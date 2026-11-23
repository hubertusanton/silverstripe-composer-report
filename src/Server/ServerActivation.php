<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server;

use SilverStripe\Core\Environment;

/**
 * Runtime guard for the server features. The YAML in server-enabled.yml only
 * registers the route and menu with an unquoted COMPOSER_REPORT_SERVER_ENABLED=true;
 * this check also covers a toggle without a flush.
 */
final class ServerActivation
{
    public const ENV_VARIABLE = 'COMPOSER_REPORT_SERVER_ENABLED';

    public static function isEnabled(): bool
    {
        return filter_var(Environment::getEnv(self::ENV_VARIABLE), FILTER_VALIDATE_BOOL);
    }
}
