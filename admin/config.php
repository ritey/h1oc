<?php
// HTTP
define('HTTP_SERVER', 'https://shop.h1golf.com/admin/');
define('HTTP_CATALOG', 'https://shop.h1golf.com/');

// HTTPS
define('HTTPS_SERVER', 'https://shop.h1golf.com/admin/');
define('HTTPS_CATALOG', 'https://shop.h1golf.com/');

// DIR
define('DIR_APPLICATION', '/srv/www/shop/webroot/admin/');
define('DIR_SYSTEM', '/srv/www/shop/webroot/system/');
define('DIR_IMAGE', '/srv/www/shop/webroot/image/');
define('DIR_STORAGE', '/srv/www/shop/storage/');
define('DIR_CATALOG', '/srv/www/shop/webroot/catalog/');
define('DIR_LANGUAGE', DIR_APPLICATION . 'language/');
define('DIR_TEMPLATE', DIR_APPLICATION . 'view/template/');
define('DIR_CONFIG', DIR_SYSTEM . 'config/');
define('DIR_CACHE', DIR_STORAGE . 'cache/');
define('DIR_DOWNLOAD', DIR_STORAGE . 'download/');
define('DIR_LOGS', DIR_STORAGE . 'logs/');
define('DIR_MODIFICATION', DIR_STORAGE . 'modification/');
define('DIR_SESSION', DIR_STORAGE . 'session/');
define('DIR_UPLOAD', DIR_STORAGE . 'upload/');

// DB
define('DB_DRIVER', 'mysqli');
define('DB_HOSTNAME', '127.0.0.1');
define('DB_USERNAME', 'h1club');
define('DB_PASSWORD', '!h1clubuser');
define('DB_DATABASE', 'opencart');
define('DB_PORT', '3306');
define('DB_PREFIX', 'oc_');

// OpenCart API
define('OPENCART_SERVER', 'https://www.opencart.com/');
