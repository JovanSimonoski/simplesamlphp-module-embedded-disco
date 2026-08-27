<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Auth\Source;

use SimpleSAML\Auth;
use SimpleSAML\Module;
use SimpleSAML\Utils;

/**
 * Log in through a provider discovered in an OpenID Federation.
 *
 * Configured like any other SimpleSAMLphp authentication source, so a service
 * provider on this installation -- or the admin area's "Test authentication
 * sources" page -- can use federated discovery without knowing anything about
 * it:
 *
 *     'embedded-disco' => [
 *         'embeddeddisco:OpenIdFederation',
 *     ],
 *
 * The source itself does very little. It hands the user to the picker and waits;
 * the picker verifies what they choose, redirects to that provider, and the
 * callback route completes the login through completeAuth().
 */
class OpenIdFederation extends Auth\Source
{
    /**
     * Stage the state is in while the user is choosing a provider.
     */
    public const string STAGE_DISCOVERY = 'embeddeddisco:discovery';

    /**
     * Stage the state is in while the user is at the provider.
     */
    public const string STAGE_AUTHORIZATION = 'embeddeddisco:authorization';

    /**
     * Where the authentication source ID is kept in the state array.
     */
    public const string AUTH_ID = 'embeddeddisco:AuthId';

    /**
     * Where the pending authorization request is kept while the user is away.
     */
    public const string PENDING = 'embeddeddisco:Pending';


    /**
     * @param array<mixed> $info
     * @param array<mixed> $config
     */
    public function __construct(array $info, array $config)
    {
        parent::__construct($info, $config);
    }


    /**
     * @param array<mixed> $state
     */
    public function authenticate(array &$state): void
    {
        // The callback needs to find its way back to this source.
        $state[self::AUTH_ID] = $this->authId;

        $stateId = Auth\State::saveState($state, self::STAGE_DISCOVERY);

        $httpUtils = new Utils\HTTP();
        $httpUtils->redirectTrustedURL(
            Module::getModuleURL('embeddeddisco/disco', ['AuthState' => $stateId]),
        );
    }


    /**
     * Finish a login once the callback has an ID token it trusts.
     *
     * @param array<string, mixed> $state
     * @param array<string, array<int, mixed>> $attributes
     */
    public static function completeWithAttributes(array $state, array $attributes): void
    {
        $state['Attributes'] = $attributes;

        // Does not return: it resumes whatever was interrupted when the login
        // began, which is a redirect away from here.
        Auth\Source::completeAuth($state);
    }
}
