<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Model;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Server\Admin\ComposerMonitorAdmin;
use Webium\ComposerReport\Server\Admin\ReadonlyCountField;
use Webium\ComposerReport\Server\Admin\ReportViewModel;
use Webium\ComposerReport\Server\Admin\StatusBadge;
use Webium\ComposerReport\Server\ServerActivation;
use Webium\ComposerReport\Server\ServerSettings;

/**
 * A site that reports to the monitor.
 *
 * @property string $Title
 * @property string $Slug
 * @property ?string $TokenHash
 * @property bool $Active
 * @property ?string $LastReportAt
 * @property ?string $LastAuditAt
 * @property int $LastOutdatedCount
 * @property int $LastAdvisoryCount
 * @property int $LastAbandonedCount
 * @property ?string $LastAdvisoryKeys
 * @property int $LastErrorCount
 * @property ?string $NotifyEmail
 * @method HasManyList<ComposerReport> Reports()
 * @method HasManyList<ComposerReportNotification> Notifications()
 */
class ComposerReportProject extends DataObject implements PermissionProvider
{
    public const MANAGE_PERMISSION = 'COMPOSER_REPORT_MANAGE';
    public const NEVER = 'never';

    private static string $table_name = 'ComposerReportProject';

    private static string $singular_name = 'Project';

    private static string $plural_name = 'Projects';

    private static array $db = [
        'Title'              => 'Varchar(255)',
        'Slug'               => 'Varchar(64)',
        'TokenHash'          => 'Varchar(64)',
        'Active'             => 'Boolean',
        'LastReportAt'       => 'Datetime',
        'LastAuditAt'        => 'Datetime',
        'LastOutdatedCount'  => 'Int',
        'LastAdvisoryCount'  => 'Int',
        'LastAbandonedCount' => 'Int',
        // JSON list of advisory keys; the basis of the "new advisories" diff
        'LastAdvisoryKeys'   => 'Text',
        'LastErrorCount'     => 'Int',
        'NotifyEmail'        => 'Varchar(254)',
    ];

    private static array $defaults = [
        'Active' => true,
    ];

    private static array $has_many = [
        'Reports'       => ComposerReport::class,
        'Notifications' => ComposerReportNotification::class,
    ];

    private static array $cascade_deletes = [
        'Reports',
        'Notifications',
    ];

    private static array $indexes = [
        'Slug' => [
            'type'    => 'unique',
            'columns' => ['Slug'],
        ],
    ];

    private static bool $require_sudo_mode = true;

    private static string $default_sort = 'Title ASC';

    private static array $summary_fields = [
        'Title'              => 'Title',
        'Slug'               => 'Slug',
        'LastReportAt.Nice'  => 'Last report',
        'LastOutdatedCount'  => 'Outdated',
        'LastAdvisoryCount'  => 'Advisories',
        'LastAbandonedCount' => 'Abandoned',
        'StatusBadges'       => 'Status',
        'StaleBadge'         => 'Stale',
        'Active.Nice'        => 'Active',
    ];

    private static array $casting = [
        'StatusBadges' => 'HTMLFragment',
        'StaleBadge'   => 'HTMLFragment',
    ];

    private static array $searchable_fields = [
        'Title',
        'Slug',
        'Active',
    ];

    /**
     * Normalised here and not in onBeforeWrite(): write() validates before onBeforeWrite().
     */
    public function setSlug(mixed $value): static
    {
        $this->setField('Slug', strtolower(trim((string) $value)));

        return $this;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(self::class . '.TITLE_REQUIRED', 'A title is required.'));
        }

        if (!PayloadValidator::isValidSlug($this->Slug)) {
            $result->addFieldError('Slug', _t(
                self::class . '.SLUG_INVALID',
                'Use lowercase letters, digits and single hyphens (at most 64 characters), e.g. "client-x".',
            ));
        } elseif (self::get()->filter('Slug', $this->Slug)->exclude('ID', $this->ID)->exists()) {
            $result->addFieldError('Slug', _t(self::class . '.SLUG_TAKEN', 'Another project already uses this slug.'));
        }

        if ((string) $this->NotifyEmail !== '' && !Email::is_valid_address($this->NotifyEmail)) {
            $result->addFieldError(
                'NotifyEmail',
                _t(self::class . '.EMAIL_INVALID', 'This is not a valid email address.'),
            );
        }

        return $result;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName([
            'TokenHash',
            'LastAdvisoryKeys',
            'LastReportAt',
            'LastAuditAt',
            'LastOutdatedCount',
            'LastAdvisoryCount',
            'LastAbandonedCount',
            'LastErrorCount',
            'Reports',
            'Notifications',
        ]);

        $fields->dataFieldByName('Slug')?->setDescription(_t(
            self::class . '.SLUG_DESCRIPTION',
            'Must match COMPOSER_REPORT_PROJECT on the site.',
        ));
        $fields->dataFieldByName('NotifyEmail')
            ?->setTitle(_t(self::class . '.NOTIFY_EMAIL', 'Notification email'))
            ->setDescription(_t(
                self::class . '.NOTIFY_EMAIL_DESCRIPTION',
                'Optional; overrides the default recipient.',
            ));

        // Field names must not match a method of this class: Form::loadDataFrom() would overwrite the value
        $readonly = static fn (string $name, string $key, string $title, mixed $value): ReadonlyField
            => ReadonlyField::create($name, _t(self::class . '.' . $key, $title), $value);
        $count = static fn (string $name, string $key, string $title, int $value): ReadonlyField
            => ReadonlyCountField::create($name, _t(self::class . '.' . $key, $title), $value);

        $fields->addFieldsToTab('Root.Main', [
            $readonly('TokenStatus', 'TOKEN', 'Token', $this->describeToken()),
            $readonly('LastReportAtNice', 'LAST_REPORT', 'Last report', $this->niceDate('LastReportAt')),
            $readonly('LastAuditAtNice', 'LAST_AUDIT', 'Last successful audit', $this->niceDate('LastAuditAt')),
            $count('LastOutdatedCountRO', 'OUTDATED', 'Outdated packages', (int) $this->LastOutdatedCount),
            $count('LastAdvisoryCountRO', 'ADVISORIES', 'Advisories', (int) $this->LastAdvisoryCount),
            $count('LastAbandonedCountRO', 'ABANDONED', 'Abandoned packages', (int) $this->LastAbandonedCount),
            $count('LastErrorCountRO', 'ERRORS', 'Errors in last report', (int) $this->LastErrorCount),
        ]);

        if ($this->exists()) {
            $latest = $this->Reports()->first();
            $view = $latest instanceof ComposerReport
                ? (string) (new ReportViewModel($latest))->render()
                : '<p>' . Convert::raw2xml(_t(self::class . '.NO_REPORT', 'No report received yet.')) . '</p>';

            $fields->addFieldToTab('Root.LatestReport', LiteralField::create('LatestReportView', $view));
            $fields->fieldByName('Root.LatestReport')?->setTitle(_t(self::class . '.TAB_LATEST', 'Latest report'));

            $fields->addFieldToTab('Root.History', GridField::create(
                'Reports',
                _t(self::class . '.HISTORY', 'Reports'),
                $this->Reports(),
                GridFieldConfig_RecordViewer::create(),
            ));
            $fields->fieldByName('Root.History')?->setTitle(_t(self::class . '.TAB_HISTORY', 'History'));
        }

        return $fields;
    }

    public function getStatusBadges(): DBHTMLText
    {
        return StatusBadge::status($this);
    }

    public function getStaleBadge(): DBHTMLText
    {
        return StatusBadge::stale($this);
    }

    /**
     * No report since stale_after_days, or never one while the project is older than that.
     */
    public function isStale(): bool
    {
        if (!$this->Active) {
            return false;
        }

        $since = $this->LastReportAt ?: $this->Created;

        return $since !== null && $this->isOlderThanThreshold($since);
    }

    /**
     * Reports arrive, but no successful audit since stale_after_days.
     */
    public function isAuditFailing(): bool
    {
        if (!$this->Active || !$this->LastReportAt || $this->isStale()) {
            return false;
        }

        return $this->isOlderThanThreshold($this->LastAuditAt ?: $this->Created);
    }

    /**
     * Dedup basis of a stale notification: the last report time, or "never".
     */
    public function staleBasis(): string
    {
        return $this->LastReportAt ?: self::NEVER;
    }

    /**
     * Dedup basis of an "audit failing" notification.
     */
    public function auditBasis(): string
    {
        return $this->LastAuditAt ?: self::NEVER;
    }

    /**
     * @return list<string>
     */
    public function lastAdvisoryKeys(): array
    {
        $keys = json_decode((string) $this->LastAdvisoryKeys, true);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_' . ComposerMonitorAdmin::class, 'any', $member);
    }

    public function canEdit($member = null)
    {
        return Permission::check(self::MANAGE_PERMISSION, 'any', $member);
    }

    public function canDelete($member = null)
    {
        return Permission::check(self::MANAGE_PERMISSION, 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        return Permission::check(self::MANAGE_PERMISSION, 'any', $member);
    }

    public function providePermissions()
    {
        if (!ServerActivation::isEnabled()) {
            return [];
        }

        return [
            self::MANAGE_PERMISSION => [
                'name'     => _t(self::class . '.PERMISSION_MANAGE', 'Manage Composer monitor projects and tokens'),
                'category' => _t(self::class . '.PERMISSION_CATEGORY', 'Composer monitor'),
                'help'     => _t(
                    self::class . '.PERMISSION_MANAGE_HELP',
                    'Create, edit and delete projects and generate tokens. Viewing needs access to the section.',
                ),
            ],
        ];
    }

    private function describeToken(): string
    {
        return $this->TokenHash
            ? _t(self::class . '.TOKEN_SET', 'Set')
            : _t(self::class . '.TOKEN_NOT_SET', 'Not set: save the project, then use "Generate new token"');
    }

    private function niceDate(string $field): string
    {
        return $this->$field ? (string) $this->dbObject($field)->Nice() : _t(self::class . '.NEVER', 'Never');
    }

    private function isOlderThanThreshold(string $datetime): bool
    {
        $days = ServerSettings::singleton()->staleAfterDays();
        $threshold = DBDatetime::now()->getTimestamp() - $days * 86400;

        return strtotime($datetime) <= $threshold;
    }
}
