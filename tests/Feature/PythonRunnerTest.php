<?php

namespace Tests\Feature;

use App\Support\Workflow\Code\PythonRunner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Python runner's client: code handed to the runner with what it reads,
 * and libraries fetched here -- the runner has no way out -- and handed over.
 */
class PythonRunnerTest extends TestCase
{
    private const CDN = 'https://cdn.jsdelivr.net/pyodide/v'.PythonRunner::PYODIDE.'/full/';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.python.url' => 'http://python:3000']);
        Http::preventStrayRequests();
    }

    /** Pyodide's lock: pandas, which needs numpy and pytz. */
    private function lock(): array
    {
        return ['packages' => [
            'pandas' => ['name' => 'pandas', 'version' => '2.2.3', 'file_name' => 'pandas-2.2.3-cp313-cp313-pyodide_2025_0_wasm32.whl', 'depends' => ['numpy', 'pytz'], 'sha256' => hash('sha256', 'pandas bytes')],
            'numpy' => ['name' => 'numpy', 'version' => '2.2.5', 'file_name' => 'numpy-2.2.5-cp313-cp313-pyodide_2025_0_wasm32.whl', 'depends' => [], 'sha256' => hash('sha256', 'numpy bytes')],
            'pytz' => ['name' => 'pytz', 'version' => '2025.1', 'file_name' => 'pytz-2025.1-py2.py3-none-any.whl', 'depends' => [], 'sha256' => hash('sha256', 'pytz bytes')],
        ]];
    }

    public function test_code_is_handed_to_the_runner_with_what_it_reads_and_its_answer_given_back()
    {
        Http::fake(['python:3000/run' => Http::response(['ok' => true, 'value' => ['total' => 150], 'logs' => ['adding up']])]);

        $answer = PythonRunner::run('result = {"total": sum(trigger["n"])}', ['trigger' => ['n' => [100, 50]], 'input' => null, 'steps' => []], 5, ['pandas'], 256);

        $this->assertSame(['ok' => true, 'value' => ['total' => 150], 'logs' => ['adding up'], 'error' => null, 'line' => null], $answer);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://python:3000/run'
            && $request['seconds'] === 5 && $request['memory'] === 256 && $request['libraries'] === ['pandas']
            // No step before it is {}, not []
            && str_contains($request->body(), '"steps":{}'));
    }

    public function test_what_went_wrong_in_the_code_comes_back_with_its_line_and_an_unreachable_runner_can_be_tried_again()
    {
        Http::fake(['python:3000/run' => Http::response(['ok' => false, 'error' => 'ZeroDivisionError: division by zero', 'line' => 3, 'logs' => []])]);
        $failed = PythonRunner::run('1/0', [], 5, [], 256);
        $this->assertSame([false, 'ZeroDivisionError: division by zero', 3], [$failed['ok'], $failed['error'], $failed['line']]);

        Http::fake(['python:3000/run' => fn () => throw new ConnectionException('refused')]);
        $gone = PythonRunner::run('1', [], 5, [], 256);
        $this->assertFalse($gone['ok']);
        $this->assertTrue($gone['retryable']);
        $this->assertStringContainsString('"python" container', $gone['error']);
    }

    public function test_a_package_pyodide_ships_comes_with_what_it_depends_on_each_file_checked()
    {
        Http::fake([
            self::CDN.'pyodide-lock.json' => Http::response($this->lock()),
            self::CDN.'pandas-*' => Http::response('pandas bytes'),
            self::CDN.'numpy-*' => Http::response('numpy bytes'),
            // Kept already: not fetched again
            'python:3000/files' => Http::response([['name' => 'pytz-2025.1-py2.py3-none-any.whl', 'size' => 10]]),
            'python:3000/files/*' => Http::response(['ok' => true]),
            'python:3000/check' => Http::response(['ok' => true, 'error' => null]),
        ]);

        $done = PythonRunner::install('Pandas', null);

        $this->assertSame(['ok' => true, 'version' => '2.2.3', 'size' => 12 + 11 + 10, 'error' => null], $done);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), rawurlencode('numpy-2.2.5-cp313-cp313-pyodide_2025_0_wasm32.whl')) && $request->body() === 'numpy bytes');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'pytz') && $request->method() === 'GET');
        Http::assertSent(fn (Request $request) => $request->url() === 'http://python:3000/check' && $request['libraries'] === ['Pandas']);

        // Pyodide's version is the one there is
        $this->assertStringContainsString('Pyodide ships pandas 2.2.3', PythonRunner::install('pandas', '1.0.0')['error']);
    }

    public function test_a_file_that_comes_back_other_than_it_should_is_not_kept()
    {
        Http::fake([
            self::CDN.'pyodide-lock.json' => Http::response($this->lock()),
            self::CDN.'*' => Http::response('tampered'),
            'python:3000/files' => Http::response([]),
        ]);

        $this->assertStringContainsString('came back other than Pyodide says it is', PythonRunner::install('numpy', null)['error']);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_anything_else_is_a_pure_python_wheel_from_pypi_or_cannot_run_here()
    {
        Http::fake([
            self::CDN.'pyodide-lock.json' => Http::response($this->lock()),
            'python:3000/files' => Http::response([]),
            'python:3000/files/*' => Http::response(['ok' => true]),
            'python:3000/check' => Http::response(['ok' => true, 'error' => null]),
            'pypi.org/pypi/humanize/json' => Http::response(['info' => ['version' => '4.11.0'], 'urls' => [
                ['packagetype' => 'sdist', 'filename' => 'humanize-4.11.0.tar.gz', 'url' => 'https://files.example/humanize.tar.gz'],
                ['packagetype' => 'bdist_wheel', 'filename' => 'humanize-4.11.0-py3-none-any.whl', 'url' => 'https://files.example/humanize.whl', 'digests' => ['sha256' => hash('sha256', 'wheel bytes')]],
            ]]),
            'files.example/humanize.whl' => Http::response('wheel bytes'),
            'pypi.org/pypi/lxml/json' => Http::response(['info' => ['version' => '5.3.0'], 'urls' => [
                ['packagetype' => 'bdist_wheel', 'filename' => 'lxml-5.3.0-cp313-cp313-manylinux_2_28_x86_64.whl', 'url' => 'https://files.example/lxml.whl'],
            ]]),
            'pypi.org/pypi/nothing-like-it/json' => Http::response([], 404),
        ]);

        $this->assertSame(['ok' => true, 'version' => '4.11.0', 'size' => 11, 'error' => null], PythonRunner::install('humanize', null));
        Http::assertSent(fn (Request $request) => $request->url() === 'http://python:3000/check' && $request['libraries'] === ['humanize==4.11.0']);

        $this->assertStringContainsString('no pure-Python wheel', PythonRunner::install('lxml', null)['error']);
        $this->assertStringContainsString('not in Pyodide, nor on PyPI', PythonRunner::install('nothing-like-it', null)['error']);
        $this->assertStringContainsString('not a library', PythonRunner::install('rm -rf /', null)['error']);
    }
}
