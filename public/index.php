<?php
use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
$kernel = new Kernel(getenv('APP_ENV') ?: 'prod', (getenv('APP_DEBUG') ?: '0') === '1');
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('Referrer-Policy', 'same-origin');
$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
$response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
$response->send();
$kernel->terminate($request, $response);
