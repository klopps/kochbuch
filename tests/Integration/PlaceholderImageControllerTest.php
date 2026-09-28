<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Http\Controllers\PlaceholderImageController;
use Kochbuch\Service\PlaceholderImageStorage;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class PlaceholderImageControllerTest extends ControllerTestCase
{
    private PlaceholderImageController $controller;
    private PlaceholderImageRepository $placeholders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->placeholders = new PlaceholderImageRepository($this->pdo);
        $this->controller = new PlaceholderImageController(
            $this->placeholders,
            new PlaceholderImageStorage(sys_get_temp_dir() . '/kochbuch-test-placeholder-images')
        );
    }

    private function fakeUploadedImage(): UploadedFile
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        // PlaceholderImageStorage::store() calls moveTo(), which (outside a
        // real SAPI request) does a plain rename() - that needs $this->file
        // to be a real path on disk, not an in-memory php://temp stream (the
        // stream a plain createStream($bytes) would produce).
        $path = tempnam(sys_get_temp_dir(), 'kochbuch-test-upload-');
        file_put_contents($path, $bytes);

        return new UploadedFile((new StreamFactory())->createStreamFromFile($path), 'photo.png', 'image/png', strlen($bytes), UPLOAD_ERR_OK);
    }

    public function testIndexRequiresAdmin(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->index($this->request('GET', '/api/v1/admin/placeholder-images', authPayload: $this->authPayload($userId)), $this->response());
    }

    public function testCreateStoresKeywordsAcrossBothLocales(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $request = $this->request('POST', '/api/v1/admin/placeholder-images', authPayload: $this->authPayload($adminId, ['is_admin' => true]))
            ->withUploadedFiles(['image' => $this->fakeUploadedImage()])
            ->withParsedBody(['keywords_de' => 'Kekse, Plätzchen', 'keywords_en' => 'cookies']);

        $result = $this->decode($this->controller->create($request, $this->response()));

        $this->assertSame(201, $result['status']);
        $this->assertFalse($result['data']['is_default']);
        $keywords = array_map(static fn (array $k) => $k['keyword'], $result['data']['keywords']);
        sort($keywords);
        $this->assertSame(['Kekse', 'Plätzchen', 'cookies'], $keywords);
    }

    public function testMarkingARowDefaultUnsetsThePreviousDefault(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $auth = $this->authPayload($adminId, ['is_admin' => true]);

        $first = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/admin/placeholder-images', authPayload: $auth)
                ->withUploadedFiles(['image' => $this->fakeUploadedImage()])
                ->withParsedBody(['is_default' => '1']),
            $this->response()
        ));
        $this->assertTrue($first['data']['is_default']);

        $second = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/admin/placeholder-images', authPayload: $auth)
                ->withUploadedFiles(['image' => $this->fakeUploadedImage()])
                ->withParsedBody(['is_default' => '1']),
            $this->response()
        ));
        $this->assertTrue($second['data']['is_default']);

        $refreshedFirst = $this->placeholders->find($first['data']['id']);
        $this->assertFalse($refreshedFirst['is_default']);
    }

    public function testUpdateReplacesKeywordsWithoutRequiringANewImage(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $auth = $this->authPayload($adminId, ['is_admin' => true]);
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/admin/placeholder-images', authPayload: $auth)
                ->withUploadedFiles(['image' => $this->fakeUploadedImage()])
                ->withParsedBody(['keywords_de' => 'Kekse']),
            $this->response()
        ));

        $result = $this->decode($this->controller->update(
            $this->request('POST', '/api/v1/admin/placeholder-images/' . $created['data']['id'], authPayload: $auth)
                ->withParsedBody(['keywords_de' => 'Torte']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame($created['data']['filename'], $result['data']['filename']);
        $this->assertSame(['Torte'], array_map(static fn (array $k) => $k['keyword'], $result['data']['keywords']));
    }

    public function testDeleteRemovesTheRow(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $auth = $this->authPayload($adminId, ['is_admin' => true]);
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/admin/placeholder-images', authPayload: $auth)
                ->withUploadedFiles(['image' => $this->fakeUploadedImage()]),
            $this->response()
        ));

        $this->controller->delete(
            $this->request('DELETE', '/api/v1/admin/placeholder-images/' . $created['data']['id'], authPayload: $auth),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );

        $this->assertNull($this->placeholders->find($created['data']['id']));
    }

    public function testDeleteThrowsNotFoundForAMissingRow(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $this->expectException(NotFoundException::class);
        $this->controller->delete(
            $this->request('DELETE', '/api/v1/admin/placeholder-images/999999', authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response(),
            ['id' => '999999']
        );
    }
}
