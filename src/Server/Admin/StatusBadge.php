<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Admin;

use SilverStripe\Core\Convert;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use Webium\ComposerReport\Server\Model\ComposerReportProject;

/**
 * Badges for the project grid, built from fixed strings only.
 * Branch 2 uses the Bootstrap 5 classes of admin 3; branch 1 the Bootstrap 4 ones.
 */
final class StatusBadge
{
    private const CLASSES = [
        'danger'    => 'badge text-bg-danger',
        'warning'   => 'badge text-bg-warning',
        'secondary' => 'badge text-bg-secondary',
        'success'   => 'badge text-bg-success',
    ];

    /**
     * "Inactive"; otherwise "Advisories" and/or "Error", or "OK".
     */
    public static function status(ComposerReportProject $project): DBHTMLText
    {
        if (!$project->Active) {
            return self::html([self::badge('secondary', _t(self::class . '.INACTIVE', 'Inactive'))]);
        }

        $badges = [];

        if ((int) $project->LastAdvisoryCount > 0) {
            $badges[] = self::badge('danger', _t(self::class . '.ADVISORIES', 'Advisories'));
        }

        if ((int) $project->LastErrorCount > 0) {
            $badges[] = self::badge('secondary', _t(self::class . '.ERROR', 'Error'));
        }

        if ($badges === [] && $project->LastReportAt) {
            $badges[] = self::badge('success', _t(self::class . '.OK', 'OK'));
        }

        return self::html($badges);
    }

    /**
     * "Stale" (no report), "Audit failing" (reports without a successful audit), or nothing.
     */
    public static function stale(ComposerReportProject $project): DBHTMLText
    {
        if ($project->isStale()) {
            return self::html([self::badge('warning', _t(self::class . '.STALE', 'Stale'))]);
        }

        if ($project->isAuditFailing()) {
            return self::html([self::badge('warning', _t(self::class . '.AUDIT_FAILING', 'Audit failing'))]);
        }

        return self::html([]);
    }

    private static function badge(string $variant, string $label): string
    {
        return sprintf('<span class="%s">%s</span>', self::CLASSES[$variant], Convert::raw2xml($label));
    }

    /**
     * @param list<string> $badges
     */
    private static function html(array $badges): DBHTMLText
    {
        /** @var DBHTMLText $field */
        $field = DBField::create_field('HTMLFragment', implode(' ', $badges));

        return $field;
    }
}
