<?php

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

        if ($version !== null) {
            $this->withHeader('X-Inertia-Version', $version);
        }
    }
}
