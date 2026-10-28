<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Re-enter the auth guards before every request.
     *
     * Sanctum wires its token guard through a RequestGuard singleton on the
     * shared application container; in a single test process (many requests,
     * one app) the guard caches the first user it resolves and every later
     * authenticated request would masquerade as that same user. Real HTTP
     * gives each request its own process/container, so this only matters for
     * feature tests that authenticate different users in sequence.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->app) {
            $this->app['auth']->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
