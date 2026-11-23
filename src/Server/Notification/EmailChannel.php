<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Notification;

use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use Webium\ComposerReport\Server\Admin\ComposerMonitorAdmin;
use Webium\ComposerReport\Server\Model\ComposerReportNotification;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;

/**
 * Recipient: the project's NotifyEmail, then env COMPOSER_REPORT_NOTIFY_EMAIL,
 * then YAML notify_email. Sender: env COMPOSER_REPORT_NOTIFY_FROM, then YAML
 * notify_from, then Email.admin_email.
 */
class EmailChannel implements NotificationChannel
{
    use Injectable;

    private const TEMPLATES = [
        ComposerReportNotification::TYPE_NEW_ADVISORIES => 'Webium\\ComposerReport\\Email\\NewAdvisories',
        ComposerReportNotification::TYPE_STALE          => 'Webium\\ComposerReport\\Email\\ProjectStale',
        ComposerReportNotification::TYPE_AUDIT_FAILING  => 'Webium\\ComposerReport\\Email\\AuditFailing',
    ];

    public function send(string $type, ComposerReportProject $project, array $advisories): void
    {
        $recipient = $this->recipient($project);

        if ($recipient === null) {
            throw new NoRecipientException(
                'No valid recipient: set NotifyEmail on the project, COMPOSER_REPORT_NOTIFY_EMAIL or notify_email',
            );
        }

        $template = self::TEMPLATES[$type] ?? throw new \InvalidArgumentException('Unknown notification type ' . $type);

        $email = Email::create()
            ->setTo($recipient)
            ->setSubject($this->subject($type, $project, count($advisories)))
            ->setHTMLTemplate($template)
            ->setPlainTemplate($template . 'Plain')
            ->setData(ArrayData::create([
                'ProjectTitle'   => (string) $project->Title,
                'ProjectSlug'    => (string) $project->Slug,
                'CmsLink'        => $this->cmsLink($project),
                'LastReport'     => $this->date($project, 'LastReportAt'),
                'LastAudit'      => $this->date($project, 'LastAuditAt'),
                'StaleAfterDays' => ServerSettings::singleton()->staleAfterDays(),
                'Advisories'     => ArrayList::create(array_map(
                    static fn (array $advisory): ArrayData => ArrayData::create(self::advisoryRow($advisory)),
                    $advisories,
                )),
            ]));

        $from = ServerSettings::singleton()->notifyFrom();

        if ($from !== null && Email::is_valid_address($from)) {
            $email->setFrom($from);
        }

        $email->send();
    }

    private function recipient(ComposerReportProject $project): ?string
    {
        $candidates = [(string) $project->NotifyEmail, ...ServerSettings::singleton()->notifyEmailCandidates()];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && Email::is_valid_address($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function subject(string $type, ComposerReportProject $project, int $advisoryCount): string
    {
        return match ($type) {
            ComposerReportNotification::TYPE_NEW_ADVISORIES => _t(
                self::class . '.SUBJECT_NEW_ADVISORIES',
                '[Composer monitor] {count} new security advisories for {project}',
                ['count' => $advisoryCount, 'project' => $project->Title],
            ),
            ComposerReportNotification::TYPE_STALE => _t(
                self::class . '.SUBJECT_STALE',
                '[Composer monitor] No report from {project}',
                ['project' => $project->Title],
            ),
            default => _t(
                self::class . '.SUBJECT_AUDIT_FAILING',
                '[Composer monitor] The audit of {project} keeps failing',
                ['project' => $project->Title],
            ),
        };
    }

    /**
     * @param array<string, mixed> $advisory
     * @return array<string, string>
     */
    private static function advisoryRow(array $advisory): array
    {
        $text = static fn (string $key): string => is_string($advisory[$key] ?? null) ? $advisory[$key] : '';

        return [
            'Package'          => $text('package'),
            'Id'               => $text('displayId'),
            'Title'            => $text('title'),
            // Already filtered with Advisory::safeLink() during the ingest
            'Link'             => $text('link'),
            'AffectedVersions' => $text('affectedVersions'),
            'Severity'         => $text('severity'),
        ];
    }

    private function cmsLink(ComposerReportProject $project): string
    {
        try {
            $link = ComposerMonitorAdmin::singleton()->getCMSEditLinkForManagedDataObject($project);

            return Director::absoluteURL($link);
        } catch (\Throwable) {
            return Director::absoluteURL('admin/composer-monitor');
        }
    }

    private function date(ComposerReportProject $project, string $field): string
    {
        return $project->$field
            ? (string) $project->dbObject($field)->Nice()
            : _t(self::class . '.NEVER', 'never');
    }
}
