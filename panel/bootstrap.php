<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact hello@terbora.com.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

use Namingo\ResellerApi\Config;
use Namingo\ResellerApi\Db;
use Namingo\ResellerApi\PanelApi;
use Namingo\ResellerApi\PanelController;
use Namingo\ResellerApi\PanelSession;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Config::load($root);
PanelSession::start($root);

$app = AppFactory::create();
$view = Twig::create(__DIR__ . '/views', ['cache' => false, 'autoescape' => 'html', 'strict_variables' => false]);
$controller = new PanelController(Db::pdo(), $view, PanelApi::create());
$app->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/{path:.*}', [$controller, 'handle']);
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, false);
return $app;
