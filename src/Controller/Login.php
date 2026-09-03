<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Auth\Simple;
use SimpleSAML\Configuration;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Module;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public entry point for starting the demo relying party login.
 */
class Login
{
    public const string AUTH_SOURCE_ID = 'embedded-disco';

    public const string ROUTE_LOGIN = 'embeddeddisco/login';

    public const string ROUTE_SESSION = 'embeddeddisco/session';


    public function __construct(protected Configuration $config)
    {
    }


    public function main(): Response
    {
        $authSource = new Simple(self::AUTH_SOURCE_ID);
        $returnTo = Module::getModuleURL(self::ROUTE_SESSION);

        if ($authSource->isAuthenticated()) {
            return new RedirectResponse($returnTo);
        }

        return new RunnableResponse([$authSource, 'login'], [[
            'ReturnTo' => $returnTo,
        ]]);
    }


    /**
     * Show what the provider returned and what SimpleSAMLphp stored for the
     * completed authentication.
     */
    public function session(): Response
    {
        $authSource = new Simple(self::AUTH_SOURCE_ID);

        if (!$authSource->isAuthenticated()) {
            return new RedirectResponse(Module::getModuleURL(self::ROUTE_LOGIN));
        }

        $attributes = $authSource->getAttributes();
        $authData = $authSource->getAuthDataArray() ?? [];
        $expiresAt = $authData['Expire'] ?? null;

        $template = new Template($this->config, 'embeddeddisco:session.twig');
        $template->data['attributes'] = $attributes;
        $template->data['authData'] = $authData;
        $template->data['issuer'] = $attributes['embeddeddisco:issuer'][0] ?? null;
        $template->data['expiresAt'] = is_int($expiresAt) ? $expiresAt : null;
        $template->data['remaining'] = is_int($expiresAt) ? max(0, $expiresAt - time()) : null;
        $template->data['logoutUrl'] = $authSource->getLogoutURL(Module::getModuleURL(self::ROUTE_LOGIN));

        return $template;
    }
}
