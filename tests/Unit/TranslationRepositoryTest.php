<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kochbuch\Exception\TranslationKeyMismatchException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\TranslationRepository;

/**
 * Pure filesystem test - points TranslationRepository at a throwaway temp
 * directory (never the real resources/i18n/) so a test can never corrupt
 * the app's actual translation files.
 */
final class TranslationRepositoryTest extends TestCase
{
    private string $tempDir;
    private TranslationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/kochbuch-translations-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir);
        $this->repository = new TranslationRepository($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*.json') as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);

        parent::tearDown();
    }

    public function testLoadReturnsAnEmptyArrayWhenTheFileDoesNotExist(): void
    {
        $this->assertSame([], $this->repository->load('en'));
    }

    public function testSaveWritesBothLocaleFilesAsPrettyPrintedJson(): void
    {
        $this->repository->save(['a.b' => 'Hello'], ['a.b' => 'Hallo']);

        $this->assertSame(['a.b' => 'Hello'], $this->repository->load('en'));
        $this->assertSame(['a.b' => 'Hallo'], $this->repository->load('de'));
        $this->assertStringEndsWith("\n", file_get_contents($this->tempDir . '/en.json'));
    }

    public function testSaveRefusesAKeySetMismatchUnlessForced(): void
    {
        try {
            $this->repository->save(['only.en' => 'x', 'both' => 'x'], ['only.de' => 'y', 'both' => 'y']);
            $this->fail('Expected TranslationKeyMismatchException.');
        } catch (TranslationKeyMismatchException $e) {
            $this->assertSame(['only.en'], $e->getPayload()['only_in_en']);
            $this->assertSame(['only.de'], $e->getPayload()['only_in_de']);
        }

        // Nothing was written - a rejected save must not touch the files.
        $this->assertSame([], $this->repository->load('en'));
    }

    public function testSaveWithForceIgnoresAKeySetMismatch(): void
    {
        $this->repository->save(['only.en' => 'x'], ['only.de' => 'y'], force: true);

        $this->assertSame(['only.en' => 'x'], $this->repository->load('en'));
        $this->assertSame(['only.de' => 'y'], $this->repository->load('de'));
    }

    public function testSaveRefusesAValueContainingScriptCloseTag(): void
    {
        $this->expectException(ValidationException::class);

        $this->repository->save(['a' => 'x</script><script>alert(1)'], ['a' => 'y']);
    }
}
