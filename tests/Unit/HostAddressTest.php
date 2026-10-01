<?php

namespace Tests\Unit;

use App\Support\Chat\HostAddress;
use Tests\TestCase;

class HostAddressTest extends TestCase
{
    public function test_localhost_is_read_as_the_machine_the_container_runs_on_when_told_where_that_is()
    {
        config(['services.chat.localhost_as' => '172.16.8.1']);

        $this->assertSame('http://172.16.8.1:11434/v1', HostAddress::reachable('http://localhost:11434/v1'));
        $this->assertSame('http://172.16.8.1:11434', HostAddress::reachable('http://127.0.0.1:11434'));
        $this->assertSame('http://172.16.8.1:1234/v1', HostAddress::reachable('http://LocalHost:1234/v1'));
        $this->assertSame('http://172.16.8.1:11434', HostAddress::reachable('http://[::1]:11434'));
        $this->assertSame('http://u:p@172.16.8.1:80/localhost', HostAddress::reachable('http://u:p@localhost:80/localhost'));
        $this->assertSame('http://host.docker.internal:80/x', (function () {
            config(['services.chat.localhost_as' => 'host.docker.internal']);

            return HostAddress::reachable('http://localhost:80/x');
        })());
    }

    public function test_any_other_host_is_left_alone()
    {
        config(['services.chat.localhost_as' => '172.16.8.1']);

        foreach ([
            'http://ollama:11434/v1',
            'http://localhost.evil.test:11434',
            'https://openrouter.ai/api/v1',
            'http://192.168.1.5:11434',
            'http://user@localhost.test/localhost',
        ] as $url) {
            $this->assertSame($url, HostAddress::reachable($url));
        }
    }

    public function test_the_word_can_be_taken_literally()
    {
        config(['services.chat.localhost_as' => 'off']);

        $this->assertSame('http://localhost:11434/v1', HostAddress::reachable('http://localhost:11434/v1'));
    }

    public function test_the_way_out_is_the_default_route_of_the_container()
    {
        $routes = "Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\tMTU\tWindow\tIRTT\n"
            ."eth0\t00000000\t010810AC\t0003\t0\t0\t0\t00000000\t0\t0\t0\n"
            ."eth0\t000810AC\t00000000\t0001\t0\t0\t0\t00FFFFFF\t0\t0\t0\n";

        $this->assertSame('172.16.8.1', HostAddress::gateway($routes));
        $this->assertNull(HostAddress::gateway("Iface\tDestination\tGateway\n"));
        $this->assertNull(HostAddress::gateway(''));
    }
}
