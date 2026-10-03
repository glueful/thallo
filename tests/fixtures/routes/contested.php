<?php

declare(strict_types=1);

// A route the contested-capability fixture registers only while `test.contested` is on.
use Glueful\Routing\Router;

/** @var Router $router */
$router->get('/test-contested', static fn () => new Glueful\Http\Response(['ok' => true]));
