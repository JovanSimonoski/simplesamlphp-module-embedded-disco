<?php

declare(strict_types=1);

// Included at the end of config/config-override-base.php, so $config already
// holds the container defaults (baseurlpath, admin password, secret salt, ...).

$config['session.cookie.name'] = 'SSPSID';

// The base image sets auth.adminpassword to the plain SSP_ADMIN_PASSWORD value,
// which SSP 2.5 rejects. Bcrypt hash of "secret1" (dev container only).
$config['auth.adminpassword'] = '$2y$12$r7va34qU6OCwc3uCqgtrVexdS3uesM9KJEfY4Xp3KgR0N5NeCuChq';

$config['module.enable']['exampleauth'] = true;
$config['module.enable']['embeddeddisco'] = true;

$config['language.i18n.backend'] = 'gettext/gettext';
$config['logging.level'] = 7;
$config['usenewui'] = false;

// Trust the proxy/port mapping in front of the container so generated URLs keep
// the published port.
$config['trusted.url.domains'] = ['localhost'];
