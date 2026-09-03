<?php

declare(strict_types=1);

// Appended to the container's SimpleSAMLphp config, so $config already holds the
// image defaults. This is the local OpenID Provider the picker logs in against.

// One URL for everyone. host.docker.internal resolves from the host browser
// (Docker Desktop puts it in the hosts file) and from inside the relying party
// container, so the issuer in an ID token is the same string the RP fetched its
// metadata from. The environment value lets both local OP containers share this
// file while keeping distinct public ports and issuers.
$config['baseurlpath'] = getenv('OP_BASE_URL') ?: 'https://host.docker.internal:8444/simplesaml/';

$config['session.cookie.name'] = getenv('OP_COOKIE_NAME') ?: 'OPSSPSID';

// Bcrypt hash of "secret1" (dev container only).
$config['auth.adminpassword'] = '$2y$12$r7va34qU6OCwc3uCqgtrVexdS3uesM9KJEfY4Xp3KgR0N5NeCuChq';

$config['module.enable']['exampleauth'] = true;
$config['module.enable']['oidc'] = true;

// The OIDC module keeps clients, tokens and grants here. SQLite keeps the demo
// to one container; the module also supports MySQL and PostgreSQL.
$config['database.dsn'] = 'sqlite:/var/simplesamlphp/data/oidc.sqlite';
$config['database.username'] = null;
$config['database.password'] = null;

$config['language.i18n.backend'] = 'gettext/gettext';
$config['logging.level'] = 7;
$config['usenewui'] = false;

$config['trusted.url.domains'] = ['host.docker.internal', 'localhost'];
