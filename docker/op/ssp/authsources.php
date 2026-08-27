<?php

declare(strict_types=1);

// Who can log in at the local OpenID Provider. These are the credentials the
// person doing the demo types once the picker has redirected them here.
$config = [
    'admin' => [
        'core:AdminPassword',
    ],

    'example-userpass' => [
        'exampleauth:UserPass',
        'users' => [
            'student:studentpass' => [
                'uid' => ['student'],
                'eduPersonPrincipalName' => ['student@op.demo.test'],
                'displayName' => ['Student Example'],
                'mail' => ['student@op.demo.test'],
                'eduPersonAffiliation' => ['student', 'member'],
            ],
            'staff:staffpass' => [
                'uid' => ['staff'],
                'eduPersonPrincipalName' => ['staff@op.demo.test'],
                'displayName' => ['Staff Example'],
                'mail' => ['staff@op.demo.test'],
                'eduPersonAffiliation' => ['staff', 'member'],
            ],
        ],
    ],
];
