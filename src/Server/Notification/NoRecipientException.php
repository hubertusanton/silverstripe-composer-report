<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Notification;

/**
 * A configuration problem, not a delivery failure: the notification stays
 * pending without using up an attempt, so it goes out once the config is fixed.
 */
class NoRecipientException extends \RuntimeException
{
}
