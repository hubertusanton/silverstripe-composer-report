<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\ORM\DataList;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerActivation;

/**
 * "Composer monitor" section. The defaults here are for client installs: the
 * class always has a url_segment (without one every CMS request crashes), but
 * no menu item and no permission. server-enabled.yml switches those on.
 */
class ComposerMonitorAdmin extends ModelAdmin
{
    private static string $url_segment = 'composer-monitor';

    private static string $menu_title = 'Composer monitor';

    private static string $menu_icon_class = 'font-icon-chart-line';

    private static bool $ignore_menuitem = true;

    private static string|array|false $required_permission_codes = false;

    private static array $managed_models = [
        ComposerReportProject::class,
    ];

    /**
     * No CSV import: it could set a TokenHash.
     */
    public $showImportForm = false;

    public function canView($member = null)
    {
        // Before the parent: with required_permission_codes false it allows every logged-in member
        if (!ServerActivation::isEnabled()) {
            return false;
        }

        return parent::canView($member);
    }

    /**
     * Projects with advisories first.
     */
    public function getList(): DataList
    {
        return parent::getList()->sort(['LastAdvisoryCount' => 'DESC', 'Title' => 'ASC']);
    }

    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        $config->getComponentByType(GridFieldDetailForm::class)?->setItemRequestClass(ProjectItemRequest::class);

        return $config;
    }
}
