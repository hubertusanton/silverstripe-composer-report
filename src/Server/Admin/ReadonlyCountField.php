<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Admin;

use SilverStripe\Forms\ReadonlyField;

/**
 * Read-only number that shows 0 as "0"; ReadonlyField shows every empty value as "(none)".
 */
class ReadonlyCountField extends ReadonlyField
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Silverstripe API name
    public function Value()
    {
        $value = $this->dataValue();

        return is_numeric($value) ? (string) (int) $value : parent::Value();
    }
}
