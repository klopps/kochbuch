<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\TranslationController;
use Kochbuch\Service\TranslationRepository;
use Kochbuch\Service\TranslationUsageScanner;

/**
 * TranslationController touches no database - this still extends
 * ControllerTestCase (like every other controller test) purely for its
 * request()/response()/decode() helpers, not for TestCase's transaction
 * wrapping. TranslationRepository points at a throwaway temp directory, so
 * a test can never write to the app's real resources/i18n/ files.
 */
final class TranslationControllerTest extends ControllerTestCase
{
    private string $tempDir;
    private TranslationController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/kochbuch-translations-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir);
        // Points at a directory with no .js/.php files, so scan() always
        // returns an empty usage map - irrelevant to what these tests check.
        $this->controller = new TranslationController(
            new TranslationRepository($this->tempDir),
            new TranslationUsageScanner($this->tempDir)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*.json') as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);

        parent::tearDown();
    }

    public function testIndexRequiresAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->index($this->request('GET', '/api/v1/translations', authPayload: $this->authPayload(1)), $this->response());
    }

    public function testIndexReturnsBothLocalesAndUsage(): void
    {
        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/translations', authPayload: $this->authPayload(1, ['is_admin' => true])),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['en']);
        $this->assertSame([], $result['data']['de']);
        $this->assertSame([], $result['data']['usage']);
    }

    public function testUpdateSavesBothLocales(): void
    {
        $result = $this->decode($this->controller->update(
            $this->request('PUT', '/api/v1/translations', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: [
                'en' => ['greeting' => 'Hello'],
                'de' => ['greeting' => 'Hallo'],
            ]),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);

        $repository = new TranslationRepository($this->tempDir);
        $this->assertSame(['greeting' => 'Hello'], $repository->load('en'));
        $this->assertSame(['greeting' => 'Hallo'], $repository->load('de'));
    }

    public function testUpdateRejectsAKeyMismatchWithThePayloadAttached(): void
    {
        try {
            $this->controller->update(
                $this->request('PUT', '/api/v1/translations', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: [
                    'en' => ['only_en' => 'x'],
                    'de' => [],
                ]),
                $this->response()
            );
            $this->fail('Expected a translation.key_mismatch ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('translation.key_mismatch', $e->getErrorCode());
        }
    }

    public function testUpdateRequiresBothMapsToBePresent(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->update(
            $this->request('PUT', '/api/v1/translations', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: ['en' => []]),
            $this->response()
        );
    }
}
