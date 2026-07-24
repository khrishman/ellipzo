<?php

use Inertia\Testing\AssertableInertia;

test('every public navigation destination is a real route that renders its expected page', function (string $uri, string $component) {
    $response = $this->get($uri);

    $response->assertStatus(200);
    $response->assertInertia(fn (AssertableInertia $page) => $page->component($component));
})->with([
    ['/', 'public/welcome'],
    ['/how-it-works', 'public/how-it-works'],
    ['/earn', 'public/earn'],
    ['/advertise', 'public/advertise'],
    ['/help', 'public/help'],
    ['/login', 'auth/login'],
    ['/register', 'auth/register'],
]);
