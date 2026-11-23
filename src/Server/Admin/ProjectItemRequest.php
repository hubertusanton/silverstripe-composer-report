<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Admin;

use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\SudoMode\SudoModeServiceInterface;
use Webium\ComposerReport\Core\Security\Secret;
use Webium\ComposerReport\Core\Security\TokenHasher;
use Webium\ComposerReport\Server\Model\ComposerReportProject;

/**
 * Adds "Generate new token" to the project form. The plaintext token is shown
 * once in the response that follows the action; it is never stored in the
 * session or the database.
 */
class ProjectItemRequest extends GridFieldDetailForm_ItemRequest
{
    /**
     * An action method that is overridden must be allowed again on the subclass.
     */
    private static $allowed_actions = [
        'ItemEditForm',
    ];

    private ?Secret $freshToken = null;

    public function doGenerateToken(array $data, Form $form)
    {
        $record = $this->getRecord();

        if (!$record instanceof ComposerReportProject || !$record->exists() || !$record->canEdit()) {
            return $this->httpError(403);
        }

        $session = $this->getToplevelController()->getRequest()->getSession();

        if (!Injector::inst()->get(SudoModeServiceInterface::class)->check($session)) {
            return $this->httpError(403, _t(self::class . '.SUDO_REQUIRED', 'Verify your password first.'));
        }

        [$token, $hash] = TokenHasher::generate();
        $record->TokenHash = $hash;
        $record->write();

        $this->freshToken = $token;

        // No redirect: the token exists only in this response
        HTTPCacheControlMiddleware::singleton()->disableCache(true);
        $controller = $this->getToplevelController();
        $controller->getResponse()->addHeader(
            'X-Status',
            rawurlencode(_t(self::class . '.TOKEN_GENERATED', 'New token generated')),
        );

        return $this->edit($controller->getRequest());
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Silverstripe API name
    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();

        if ($form instanceof Form && $this->freshToken !== null) {
            $form->Fields()->unshift(LiteralField::create('FreshToken', sprintf(
                '<div class="alert alert-warning" role="alert"><p>%s</p><p><code>%s</code></p></div>',
                Convert::raw2xml(_t(
                    self::class . '.TOKEN_SHOWN_ONCE',
                    'Set this as COMPOSER_REPORT_TOKEN in the .env of the site. It is shown only once.',
                )),
                Convert::raw2xml($this->freshToken->reveal()),
            )));
        }

        return $form;
    }

    protected function getFormActions()
    {
        $actions = parent::getFormActions();
        $record = $this->getRecord();

        if ($record instanceof ComposerReportProject && $record->exists() && $record->canEdit()) {
            $confirm = _t(
                self::class . '.CONFIRM',
                'Generate a new token? The current token stops working immediately.',
            );

            $actions->push(
                FormAction::create('doGenerateToken', _t(self::class . '.GENERATE', 'Generate new token'))
                    ->setUseButtonTag(true)
                    ->addExtraClass('btn-outline-secondary')
                    ->setAttribute('onclick', 'return confirm(' . json_encode($confirm) . ');'),
            );
        }

        return $actions;
    }
}
