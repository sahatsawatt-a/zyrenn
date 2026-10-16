<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * Who gets the dev server and who the built app: the public address always
 * the built one; with SERVE_BUILT on, everyone but a dev address too, so a
 * person using the app is not reloaded each time a file is saved.
 */
class BuiltAssetsTest extends TestCase
{
    use RefreshDatabase;

    private const NEVER = 'framework/no-dev-server-here';

    private function hotFileAt(string $url): string
    {
        Vite::useHotFile(public_path('hot'));
        $this->get($url);

        return Vite::hotFile();
    }

    public function test_the_built_app_is_served_outside_the_dev_addresses_once_it_is_on()
    {
        config(['services.workflows.public_url' => 'https://tunnel.example.com', 'app.serve_built' => false]);

        // Off: only the public address is built
        $this->assertSame(storage_path(self::NEVER), $this->hotFileAt('https://tunnel.example.com/login'));
        $this->assertSame(public_path('hot'), $this->hotFileAt('https://zyrenn.example.com/login'));

        config(['app.serve_built' => true, 'app.dev_hosts' => ['127.0.0.1', 'localhost', 'web']]);

        $this->assertSame(storage_path(self::NEVER), $this->hotFileAt('https://zyrenn.example.com/login'));
        $this->assertSame(public_path('hot'), $this->hotFileAt('http://127.0.0.1:8001/login'));
        $this->assertSame(public_path('hot'), $this->hotFileAt('http://web:8080/login'));
    }
}
