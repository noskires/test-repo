<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Two `php artisan test` runs on the same checkout must not wipe each other's fake disks (issue 36).
 * Each test process fakes disks under its own root, and faking never empties another process's root
 * or the shared storage/framework/testing/disks folder.
 */
class ConcurrentSafeFakeStorageTest extends TestCase
{
    private Filesystem $files;

    /** @var list<string> */
    private array $planted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
    }

    protected function tearDown(): void
    {
        foreach ($this->planted as $directory) {
            $this->files->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    private function sharedStoragePath(string $path = ''): string
    {
        return rtrim(base_path('storage'), '\\/').($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
    }

    private function plant(string $directory, string $fileName): string
    {
        $this->files->ensureDirectoryExists($directory);
        $this->files->put($directory.DIRECTORY_SEPARATOR.$fileName, 'keep me');

        return $directory.DIRECTORY_SEPARATOR.$fileName;
    }

    private function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public function test_a_faked_disk_resolves_to_a_per_process_root(): void
    {
        $root = $this->normalise(Storage::fake('local')->path(''));

        $this->assertStringContainsString('/framework/testing/process-'.TestCase::processToken().'/', $root);
        $this->assertStringContainsString((string) getmypid(), TestCase::processToken());
        $this->assertStringStartsNotWith($this->normalise($this->sharedStoragePath('framework/testing/disks')), $root);
    }

    public function test_the_process_root_is_stable_across_tests_in_one_process(): void
    {
        $this->assertSame(TestCase::processToken(), TestCase::processToken());
        $this->assertSame(
            $this->normalise($this->sharedStoragePath('framework/testing/process-'.TestCase::processToken())),
            $this->normalise(storage_path()),
        );
    }

    public function test_faking_never_empties_a_sibling_process_root_or_the_shared_folder(): void
    {
        $siblingRoot = $this->sharedStoragePath('framework/testing/process-sibling-'.bin2hex(random_bytes(4)));
        $this->planted[] = $siblingRoot;
        $siblingFile = $this->plant($siblingRoot.DIRECTORY_SEPARATOR.'framework/testing/disks/local', 'sibling.txt');

        $sharedDirectory = $this->sharedStoragePath('framework/testing/disks/local');
        $sharedFile = $this->plant($sharedDirectory, 'shared-'.bin2hex(random_bytes(4)).'.txt');

        Storage::fake('local');
        Storage::fake('local')->put('mine.txt', 'x');
        Storage::fake('local');

        $this->assertFileExists($siblingFile);
        $this->assertFileExists($sharedFile);
        $this->files->delete($sharedFile);
    }

    public function test_faking_still_empties_this_processes_own_root(): void
    {
        Storage::fake('local')->put('stale.txt', 'x');

        Storage::fake('local');

        Storage::disk('local')->assertMissing('stale.txt');
    }
}
