<?php

test('a known legal document slug renders', function () {
    $response = $this->get('/legal/terms');

    $response->assertStatus(200);
    $response->assertInertia(
        fn ($page) => $page->component('legal/show')
            ->where('document.slug', 'terms')
    );
});

test('the other known legal document slug also renders', function () {
    $response = $this->get('/legal/privacy');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('legal/show')->where('document.slug', 'privacy'));
});

test('an unknown legal document slug returns 404', function () {
    $response = $this->get('/legal/does-not-exist');

    $response->assertStatus(404);
});

test('a legal document slug is never treated as a filesystem path', function () {
    // If the slug were ever interpolated into a file/view path, a
    // traversal-shaped slug would risk resolving outside the intended
    // directory instead of cleanly 404ing like any other unknown key.
    $response = $this->get('/legal/'.rawurlencode('../../../../etc/passwd'));

    $response->assertStatus(404);
});
