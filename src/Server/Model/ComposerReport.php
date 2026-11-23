<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Model;

use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use Webium\ComposerReport\Server\Admin\ComposerMonitorAdmin;
use Webium\ComposerReport\Server\Admin\ReportViewModel;

/**
 * One received report. Immutable in the CMS.
 *
 * @property string $ReceivedAt
 * @property int $SchemaVersion
 * @property ?string $PhpVersion
 * @property ?string $FrameworkVersion
 * @property int $OutdatedCount
 * @property int $AdvisoryCount
 * @property int $AbandonedCount
 * @property string $Payload
 * @property int $ProjectID
 * @method ComposerReportProject Project()
 */
class ComposerReport extends DataObject
{
    private static string $table_name = 'ComposerReport';

    private static string $singular_name = 'Report';

    private static string $plural_name = 'Reports';

    private static array $db = [
        'ReceivedAt'       => 'Datetime',
        'SchemaVersion'    => 'Int',
        'PhpVersion'       => 'Varchar(50)',
        'FrameworkVersion' => 'Varchar(50)',
        'OutdatedCount'    => 'Int',
        'AdvisoryCount'    => 'Int',
        'AbandonedCount'   => 'Int',
        'Payload'          => 'Text',
    ];

    private static array $has_one = [
        'Project' => ComposerReportProject::class,
    ];

    private static array $indexes = [
        'ProjectReceivedAt' => [
            'type'    => 'index',
            'columns' => ['ProjectID', 'ReceivedAt'],
        ],
    ];

    private static array $default_sort = [
        'ReceivedAt' => 'DESC',
        'ID'         => 'DESC',
    ];

    private static array $summary_fields = [
        'ReceivedAt.Nice'  => 'Received',
        'OutdatedCount'    => 'Outdated',
        'AdvisoryCount'    => 'Advisories',
        'AbandonedCount'   => 'Abandoned',
        'PhpVersion'       => 'PHP',
        'FrameworkVersion' => 'Framework',
    ];

    public function getTitle(): string
    {
        return (string) $this->dbObject('ReceivedAt')->Nice();
    }

    /**
     * Read-only: the rendered report plus the raw payload, collapsed.
     */
    public function getCMSFields(): FieldList
    {
        $payload = $this->payloadData();
        $pretty = $payload === null
            ? (string) $this->Payload
            : (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $fields = FieldList::create(TabSet::create('Root', Tab::create('Main')));
        $fields->addFieldsToTab('Root.Main', [
            LiteralField::create('ReportView', (string) (new ReportViewModel($this))->render()),
            ToggleCompositeField::create('RawPayload', _t(self::class . '.RAW_PAYLOAD', 'Raw payload'), [
                LiteralField::create('PayloadJson', '<pre>' . Convert::raw2xml($pretty) . '</pre>'),
            ])->setStartClosed(true),
        ]);

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    /**
     * The stored payload, decoded; null when it is not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    public function payloadData(): ?array
    {
        $data = json_decode((string) $this->Payload, true);

        return is_array($data) ? $data : null;
    }

    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_' . ComposerMonitorAdmin::class, 'any', $member);
    }

    public function canEdit($member = null)
    {
        return false;
    }

    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    public function canDelete($member = null)
    {
        return Permission::check(ComposerReportProject::MANAGE_PERMISSION, 'any', $member);
    }
}
