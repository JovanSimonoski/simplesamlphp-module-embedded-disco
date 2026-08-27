<?php

declare(strict_types=1);

$config = array(

    // This is an authentication source which handles admin authentication.
    'admin' => array(
        'core:AdminPassword',
    ),

    // Log in through a provider discovered in the federation. Visiting the admin
    // area's "Test authentication sources" page and picking this one runs the
    // whole flow: the picker, Trust Chain verification, the provider's own login
    // page, and back here with the claims it issued.
    'embedded-disco' => [
        'embeddeddisco:OpenIdFederation',
    ],

    'example-userpass' => [
        'exampleauth:UserPass',
        'users' => [
            'student:studentpass' => [
                'uid' => ['student'],
                'eduPersonAffiliation' => ['member', 'student'],
                'eduPersonNickname' => 'Sir_Nickname',
                'displayName' => 'Some User',
                'givenName' => 'Firsty',
                'middle_name' => 'Mid',
                'sn' => 'Lasty',
                'labeledURI' => 'https://example.com/student',
                'jpegURL' => 'https://example.com/student.jpg',
                'mail' => 'something@example.com',
                'email_verified' => 'yes',
                'zoneinfo' => 'Europe/Paris',
                'updated_at' => '1621374126',
                'preferredLanguage' => 'fr-CA',
                'website' => 'https://example.com/student-blog',
                'gender' => 'female',
                'birthdate' => '1945-03-21',
                'eduPersonUniqueId' => '13579',
                'phone_number_verified' => 'yes',
                'mobile' => '+1 (604) 555-1234;ext=5678',
                'postalAddress' => ["Place Charles de Gaulle, Paris"],
                'street_address' => ['Place Charles de Gaulle'],
                'locality' => ['Paris'],
                'region' => ['Île-de-France'],
                'postal_code' => ['75008'],
                'country' => ['France'],
            ],
            'employee:employeepass' => [
                'uid' => ['employee'],
                'eduPersonAffiliation' => ['member', 'employee'],
                'eduPersonEntitlement' => ['urn:example:oidc:manage:client']
            ],
            'member:memberpass' => [
                'uid' => ['member'],
                'eduPersonAffiliation' => ['member'],
                'eduPersonEntitlement' => ['urn:example:oidc:manage:client']
            ],
            'minimal:minimalpass' => [
                'uid' => ['minimal'],
            ],
        ],
    ],


);
