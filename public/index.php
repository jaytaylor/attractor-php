<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Autoload.php';

use AttractorPhp\App;
use AttractorPhp\Http\Request;

$app = App::createDefault();
$response = $app->handle(Request::fromGlobals());
$response->send();
