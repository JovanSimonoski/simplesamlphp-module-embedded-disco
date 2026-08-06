<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

use function time;

/**
 * A fake federation, shaped exactly like what a real top-down traversal yields:
 * a map of entity ID => Entity Configuration JWT payload.
 *
 * This exists so the POC can exercise the real library pipeline without any
 * network access. Swapping it for live discovery is a config flag -- see
 * ModuleConfig::OPTION_USE_MOCK_DATA.
 */
class MockFederation
{
    public const string TRUST_MARK_CERTIFIED = 'https://ta.embedded-disco.test/trust-mark/certified';

    public const string TRUST_MARK_RESEARCH_EDU = 'https://ta.embedded-disco.test/trust-mark/research-and-education';


    /**
     * Entity payloads keyed by entity ID, as the library's entity collection
     * store holds them.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function entities(): array
    {
        $entities = [];

        foreach (self::providers() as $provider) {
            $entities[$provider['sub']] = self::openIdProviderPayload(
                $provider['sub'],
                $provider['displayName'],
                $provider['organizationName'],
                $provider['description'],
                $provider['keywords'],
                $provider['trustMarkTypes'],
            );
        }

        foreach (self::relyingParties() as $relyingParty) {
            $entities[$relyingParty['sub']] = self::relyingPartyPayload(
                $relyingParty['sub'],
                $relyingParty['displayName'],
                $relyingParty['organizationName'],
            );
        }

        foreach (self::intermediates() as $intermediate) {
            $entities[$intermediate['sub']] = self::intermediatePayload(
                $intermediate['sub'],
                $intermediate['displayName'],
                $intermediate['organizationName'],
            );
        }

        return $entities;
    }


    /**
     * @return array<int, array{
     *     sub: string,
     *     displayName: string,
     *     organizationName: string,
     *     description: string,
     *     keywords: string[],
     *     trustMarkTypes: string[]
     * }>
     */
    protected static function providers(): array
    {
        return [
            [
                'sub' => 'https://op.ukim.mk',
                'displayName' => 'Ss. Cyril and Methodius University',
                'organizationName' => 'UKIM',
                'description' => 'Identity provider for students and staff of UKIM in Skopje.',
                'keywords' => ['ukim', 'skopje', 'university'],
                'trustMarkTypes' => [self::TRUST_MARK_CERTIFIED, self::TRUST_MARK_RESEARCH_EDU],
            ],
            [
                'sub' => 'https://op.finki.ukim.mk',
                'displayName' => 'Faculty of Computer Science and Engineering',
                'organizationName' => 'FINKI',
                'description' => 'Identity provider for FINKI students and academic staff.',
                'keywords' => ['finki', 'computer science', 'skopje'],
                'trustMarkTypes' => [self::TRUST_MARK_CERTIFIED, self::TRUST_MARK_RESEARCH_EDU],
            ],
            [
                'sub' => 'https://op.uklo.edu.mk',
                'displayName' => 'St. Kliment Ohridski University Bitola',
                'organizationName' => 'UKLO',
                'description' => 'Identity provider for the university in Bitola.',
                'keywords' => ['uklo', 'bitola', 'university'],
                'trustMarkTypes' => [self::TRUST_MARK_RESEARCH_EDU],
            ],
            [
                'sub' => 'https://op.ugd.edu.mk',
                'displayName' => 'Goce Delcev University Stip',
                'organizationName' => 'UGD',
                'description' => 'Identity provider for the university in Stip.',
                'keywords' => ['ugd', 'stip', 'university'],
                'trustMarkTypes' => [self::TRUST_MARK_RESEARCH_EDU],
            ],
            [
                'sub' => 'https://op.marnet.mk',
                'displayName' => 'MARnet National Research Network',
                'organizationName' => 'MARnet',
                'description' => 'National research and education network identity provider.',
                'keywords' => ['marnet', 'nren', 'research'],
                'trustMarkTypes' => [self::TRUST_MARK_CERTIFIED, self::TRUST_MARK_RESEARCH_EDU],
            ],
            [
                'sub' => 'https://op.geant.org',
                'displayName' => 'GEANT Community Identity Provider',
                'organizationName' => 'GEANT',
                'description' => 'Pan-European research and education identity provider.',
                'keywords' => ['geant', 'europe', 'research'],
                'trustMarkTypes' => [self::TRUST_MARK_CERTIFIED],
            ],
            [
                'sub' => 'https://op.surf.nl',
                'displayName' => 'SURF Identity Provider',
                'organizationName' => 'SURF',
                'description' => 'Dutch national research and education identity provider.',
                'keywords' => ['surf', 'netherlands', 'research'],
                'trustMarkTypes' => [self::TRUST_MARK_CERTIFIED, self::TRUST_MARK_RESEARCH_EDU],
            ],
            [
                'sub' => 'https://op.uninett.no',
                'displayName' => 'Sikt Norwegian Identity Provider',
                'organizationName' => 'Sikt',
                'description' => 'Norwegian research and education identity provider.',
                'keywords' => ['sikt', 'norway', 'research'],
                'trustMarkTypes' => [self::TRUST_MARK_RESEARCH_EDU],
            ],
            [
                'sub' => 'https://op.city-library.mk',
                'displayName' => 'Skopje City Library',
                'organizationName' => 'Skopje City Library',
                'description' => 'Public library patron identity provider.',
                'keywords' => ['library', 'skopje', 'public'],
                'trustMarkTypes' => [],
            ],
            [
                'sub' => 'https://op.hospital.mk',
                'displayName' => 'National Hospital Directory',
                'organizationName' => 'Ministry of Health',
                'description' => 'Healthcare staff identity provider.',
                'keywords' => ['health', 'hospital', 'staff'],
                'trustMarkTypes' => [self::TRUST_MARK_CERTIFIED],
            ],
        ];
    }


    /**
     * Present so entity_type filtering has something to exclude.
     *
     * @return array<int, array{sub: string, displayName: string, organizationName: string}>
     */
    protected static function relyingParties(): array
    {
        return [
            [
                'sub' => 'https://rp.library-portal.mk',
                'displayName' => 'National Library Portal',
                'organizationName' => 'MARnet',
            ],
            [
                'sub' => 'https://rp.grading.finki.ukim.mk',
                'displayName' => 'FINKI Grading System',
                'organizationName' => 'FINKI',
            ],
        ];
    }


    /**
     * @return array<int, array{sub: string, displayName: string, organizationName: string}>
     */
    protected static function intermediates(): array
    {
        return [
            [
                'sub' => 'https://intermediate.mk-edu.test',
                'displayName' => 'Macedonian Education Intermediate',
                'organizationName' => 'MARnet',
            ],
        ];
    }


    /**
     * @param string[] $keywords
     * @param string[] $trustMarkTypes
     * @return array<string, mixed>
     */
    protected static function openIdProviderPayload(
        string $sub,
        string $displayName,
        string $organizationName,
        string $description,
        array $keywords,
        array $trustMarkTypes,
    ): array {
        $payload = self::basePayload($sub);

        $payload[ClaimsEnum::Metadata->value] = [
            EntityTypesEnum::OpenIdProvider->value => [
                'issuer' => $sub,
                'authorization_endpoint' => $sub . '/authorize',
                'token_endpoint' => $sub . '/token',
                'jwks_uri' => $sub . '/jwks',
                ClaimsEnum::DisplayName->value => $displayName,
                ClaimsEnum::OrganizationName->value => $organizationName,
                ClaimsEnum::Description->value => $description,
                ClaimsEnum::Keywords->value => $keywords,
                ClaimsEnum::LogoUri->value => $sub . '/logo.svg',
                ClaimsEnum::InformationUri->value => $sub . '/about',
            ],
            EntityTypesEnum::FederationEntity->value => [
                ClaimsEnum::DisplayName->value => $displayName,
                ClaimsEnum::OrganizationName->value => $organizationName,
                ClaimsEnum::OrganizationUri->value => $sub,
            ],
        ];

        if ($trustMarkTypes !== []) {
            $payload[ClaimsEnum::TrustMarks->value] = array_map(
                static fn(string $type): array => [
                    ClaimsEnum::TrustMarkType->value => $type,
                    // A real statement carries a signed JWT here. The discovery
                    // filter only reads trust_mark_type, so the POC leaves the
                    // signed form out rather than faking a signature.
                ],
                $trustMarkTypes,
            );
        }

        return $payload;
    }


    /**
     * @return array<string, mixed>
     */
    protected static function relyingPartyPayload(
        string $sub,
        string $displayName,
        string $organizationName,
    ): array {
        $payload = self::basePayload($sub);

        $payload[ClaimsEnum::Metadata->value] = [
            EntityTypesEnum::OpenIdRelyingParty->value => [
                ClaimsEnum::DisplayName->value => $displayName,
                ClaimsEnum::OrganizationName->value => $organizationName,
                'redirect_uris' => [$sub . '/callback'],
            ],
        ];

        return $payload;
    }


    /**
     * @return array<string, mixed>
     */
    protected static function intermediatePayload(
        string $sub,
        string $displayName,
        string $organizationName,
    ): array {
        $payload = self::basePayload($sub);

        $payload[ClaimsEnum::Metadata->value] = [
            EntityTypesEnum::FederationEntity->value => [
                ClaimsEnum::DisplayName->value => $displayName,
                ClaimsEnum::OrganizationName->value => $organizationName,
                'federation_list_endpoint' => $sub . '/list',
            ],
        ];

        return $payload;
    }


    /**
     * Claims every Entity Configuration carries.
     *
     * @return array<string, mixed>
     */
    protected static function basePayload(string $sub): array
    {
        $now = time();

        return [
            ClaimsEnum::Iss->value => $sub,
            ClaimsEnum::Sub->value => $sub,
            ClaimsEnum::Iat->value => $now,
            ClaimsEnum::Exp->value => $now + 86400,
        ];
    }
}
