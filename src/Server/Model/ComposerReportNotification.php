<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use Webium\ComposerReport\Server\Admin\ComposerMonitorAdmin;

/**
 * Outbox row: filled by the ingest (new advisories) and NotifyTask (stale,
 * audit failing), sent by NotifyTask. Basis deduplicates per project and type.
 *
 * @property string $Type
 * @property string $Basis
 * @property ?string $Data
 * @property ?string $SentAt
 * @property int $Attempts
 * @property ?string $LastError
 * @property int $ProjectID
 * @method ComposerReportProject Project()
 */
class ComposerReportNotification extends DataObject
{
    public const TYPE_NEW_ADVISORIES = 'new_advisories';
    public const TYPE_STALE = 'stale';
    public const TYPE_AUDIT_FAILING = 'audit_failing';

    private static string $table_name = 'ComposerReportNotification';

    private static string $singular_name = 'Notification';

    private static string $plural_name = 'Notifications';

    private static array $db = [
        'Type'      => "Enum('new_advisories,stale,audit_failing', 'new_advisories')",
        // LastReportAt/LastAuditAt at the time of the notification, or "never"
        'Basis'     => 'Varchar(32)',
        // JSON, e.g. the new advisories
        'Data'      => 'Text',
        'SentAt'    => 'Datetime',
        'Attempts'  => 'Int',
        'LastError' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Project' => ComposerReportProject::class,
    ];

    private static array $indexes = [
        'Pending' => [
            'type'    => 'index',
            'columns' => ['SentAt', 'Attempts'],
        ],
        'Dedup'   => [
            'type'    => 'index',
            'columns' => ['ProjectID', 'Type', 'Basis'],
        ],
    ];

    private static string $default_sort = 'ID ASC';

    /**
     * @return array<array-key, mixed>
     */
    public function data(): array
    {
        $data = json_decode((string) $this->Data, true);

        return is_array($data) ? $data : [];
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
