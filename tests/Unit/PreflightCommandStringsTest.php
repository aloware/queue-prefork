<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Horizon\SupervisorCommandString;
use Laravel\Horizon\WorkerCommandString;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tevkert\QueuePrefork\Preflight;

/** The entry script preflight looks for is the one Horizon's command strings name, as the shell reads them. */
class PreflightCommandStringsTest extends TestCase
{
    private string $supervisor;

    private string $worker;

    private string $dir;

    protected function setUp(): void
    {
        $this->supervisor = SupervisorCommandString::$command;
        $this->worker = WorkerCommandString::$command;
        $this->dir = sys_get_temp_dir().'/qp-preflight-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir.'/with space', 0700, true);
        file_put_contents($this->dir.'/artisan', "<?php\n");
        file_put_contents($this->dir.'/with space/artisan', "<?php\n");
    }

    protected function tearDown(): void
    {
        SupervisorCommandString::$command = $this->supervisor;
        WorkerCommandString::$command = $this->worker;
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function test_the_default_command_strings_name_the_apps_artisan(): void
    {
        $this->assertSame([], $this->reasons());
    }

    public function test_a_quoted_absolute_script_path_is_found(): void
    {
        // An app that points Horizon at an entry script elsewhere quotes the path, as for any shell line.
        SupervisorCommandString::$command = 'exec @php '.escapeshellarg($this->dir.'/artisan').' horizon:supervisor';
        WorkerCommandString::$command = 'exec @php '.escapeshellarg($this->dir.'/artisan').' horizon:work';

        $this->assertSame([], $this->reasons());
    }

    public function test_a_script_that_is_not_there_is_a_reason(): void
    {
        WorkerCommandString::$command = 'exec @php '.escapeshellarg($this->dir.'/missing').' horizon:work';

        $this->assertSame(["worker entry script {$this->dir}/missing not found"], $this->reasons());
    }

    /** @return list<string> */
    private function reasons(): array
    {
        $app = $this->createStub(Application::class);
        $app->method('basePath')->willReturnCallback(fn (string $path = '') => $this->dir.($path === '' ? '' : '/'.$path));

        return (new ReflectionMethod(Preflight::class, 'commandStrings'))->invoke(null, $app);
    }
}
