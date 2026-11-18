<?php

// Local router: keep ticket/display secret URLs out of custom request logging.
$public = dirname(__DIR__).'/public';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = realpath($public.$path);
if ($path !== '/' && $file && str_starts_with($file, realpath($public).DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require $public.'/index.php';
