<?php

declare(strict_types=1);

$config['baseurlpath'] = 'https://host.docker.internal:8446/simplesaml/';
$config['session.cookie.name'] = 'TASSPSID';
$config['auth.adminpassword'] = '$2y$12$r7va34qU6OCwc3uCqgtrVexdS3uesM9KJEfY4Xp3KgR0N5NeCuChq';
$config['module.enable']['embeddeddisco'] = true;
$config['language.i18n.backend'] = 'gettext/gettext';
$config['logging.level'] = 7;
$config['usenewui'] = false;
$config['trusted.url.domains'] = ['host.docker.internal', 'localhost'];
