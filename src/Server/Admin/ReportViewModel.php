<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Admin;

use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use Webium\ComposerReport\Core\Report\Advisory;
use Webium\ComposerReport\Core\Report\OutdatedPackage;
use Webium\ComposerReport\Core\Report\ReportSummary;
use Webium\ComposerReport\Server\Model\ComposerReport;

/**
 * Template data for one report. Every value is a plain string, so the
 * template escapes it; Composer data is never cast to HTML.
 */
final class ReportViewModel
{
    public const TEMPLATE = 'Webium\\ComposerReport\\Admin\\ReportView';

    public function __construct(
        private readonly ComposerReport $report,
    ) {
    }

    public function render(): DBHTMLText
    {
        return $this->data()->renderWith(self::TEMPLATE);
    }

    public function data(): ArrayData
    {
        $payload = $this->report->payloadData() ?? [];
        $summary = ReportSummary::fromPayload($payload);
        $environment = is_array($payload['environment'] ?? null) ? $payload['environment'] : [];

        return ArrayData::create([
            'ReceivedAt'        => (string) $this->report->dbObject('ReceivedAt')->Nice(),
            'OutdatedAvailable' => $summary->isOutdatedAvailable(),
            'AuditAvailable'    => $summary->isAuditAvailable(),
            'Outdated'          => ArrayList::create(array_map(
                static fn (OutdatedPackage $package): ArrayData => self::outdatedRow($package),
                array_values(array_filter(
                    $summary->outdatedPackages(),
                    static fn (OutdatedPackage $package): bool => $package->isOutdated() || $package->abandoned,
                )),
            )),
            'Advisories'        => ArrayList::create(array_map(
                static fn (Advisory $advisory): ArrayData => self::advisoryRow($advisory),
                $summary->advisories(),
            )),
            'Errors'            => ArrayList::create(array_map(
                static fn (string $error): ArrayData => ArrayData::create(['Message' => $error]),
                $summary->errors(),
            )),
            'PhpVersion'        => self::text($environment['php_version'] ?? null),
            'ComposerVersion'   => self::text($environment['composer_version'] ?? null),
            'FrameworkVersion'  => self::text($environment['silverstripe_framework'] ?? null),
            'Hostname'          => self::text($environment['hostname'] ?? null),
        ]);
    }

    private static function outdatedRow(OutdatedPackage $package): ArrayData
    {
        return ArrayData::create([
            'Name'      => $package->name,
            'Version'   => $package->version,
            'Latest'    => $package->latest ?? '',
            'Level'     => $package->isOutdated() ? $package->updateLevel()->value : '',
            'Direct'    => $package->directDependency ? 'yes' : 'no',
            'Abandoned' => $package->abandoned ? ($package->replacement ?? 'yes') : '',
        ]);
    }

    private static function advisoryRow(Advisory $advisory): ArrayData
    {
        return ArrayData::create([
            'Package'          => $advisory->packageName,
            'Id'               => $advisory->displayId(),
            'Title'            => $advisory->title,
            'Link'             => $advisory->safeLink() ?? '',
            'AffectedVersions' => $advisory->affectedVersions,
            'Severity'         => $advisory->severity ?? '',
            'ReportedAt'       => $advisory->reportedAt ?? '',
        ]);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '-';
    }
}
