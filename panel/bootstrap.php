<?php
/**
 * Namingo Reseller panel, adapted from Argora Foundry's Slim/Twig bootstrap.
 * Original foundation: Copyright (c) 2025 Argora, MIT (see LICENSE-FOUNDRY).
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
