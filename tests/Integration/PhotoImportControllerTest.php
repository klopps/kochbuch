<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\PhotoImportController;
use Kochbuch\Service\GeminiRecipeExtractor;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * todo.md "Bulk Photo Import": one photo per request becomes one recipe for
 * the chosen owner, tagged "foto-import". Gemini is faked - no network.
 */
final class PhotoImportControllerTest extends ControllerTestCase
{
    private RecipeRepository $recipes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recipes = new RecipeRepository($this->pdo, new PlaceholderImageRepository($this->pdo));
    }

    private function controller(array $recipe): PhotoImportController
    {
        $gemini = new GeminiRecipeExtractor('k', 'm', fn () => ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($recipe)]]]]]])]);

        return new PhotoImportController($this->recipes, new UserRepository($this->pdo), $gemini);
    }

    private function photo(string $name = 'kuchen.png'): UploadedFile
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return new UploadedFile((new StreamFactory())->createStream($bytes), $name, 'image/png', strlen($bytes), UPLOAD_ERR_OK);
    }

    private function importRequest(int $adminId, array $fields, ?UploadedFile $file = null, bool $admin = true)
    {
        $request = $this->request('POST', '/api/v1/admin/photo-import', authPayload: $this->authPayload($adminId, ['is_admin' => $admin]))
            ->withParsedBody($fields);

        return $file !== null ? $request->withUploadedFiles(['image' => $file]) : $request;
    }

    public function testCreatesARecipeForTheChosenOwnerWithTheImportTag(): void
    {
        $adminId = $this->createUser();
        $ownerId = $this->createUser();
        $controller = $this->controller([
            'name' => 'Apfelkuchen',
            'ingredients' => [['name' => 'Mehl', 'amount' => 200, 'unit' => 'g', 'note' => null, 'is_heading' => false]],
            'steps' => [['instruction' => 'Backen.', 'is_heading' => false]],
            'raw_text' => 'x',
        ]);

        $result = $this->decode($controller->import($this->importRequest($adminId, ['owner_id' => $ownerId, 'visibility' => 'private'], $this->photo()), $this->response()));

        $this->assertSame(201, $result['status']);
        $recipe = $this->recipes->find($result['data']['id']);
        $this->assertSame('Apfelkuchen', $recipe['name']);
        $this->assertSame($ownerId, $recipe['user_id']);
        $this->assertSame('private', $recipe['visibility']);
        $this->assertContains('foto-import', $recipe['tags']);
        $this->assertSame('Mehl', $recipe['ingredients'][0]['name']);
        $this->assertSame([], $recipe['images'], 'the photo is not attached');
    }

    public function testFallsBackToTheFileNameWhenNoTitleWasRecognized(): void
    {
        $adminId = $this->createUser();
        $controller = $this->controller(['name' => null, 'ingredients' => [], 'steps' => [['instruction' => 'Rühren.', 'is_heading' => false]], 'raw_text' => 'x']);

        $result = $this->decode($controller->import($this->importRequest($adminId, ['owner_id' => $adminId], $this->photo('IMG_0042.png')), $this->response()));

        $this->assertSame('Foto-Import IMG_0042', $result['data']['name']);
    }

    public function testRejectsAPhotoWithoutRecipeAndAnUnknownOwner(): void
    {
        $adminId = $this->createUser();
        $empty = $this->controller(['name' => 'Urlaubsfoto', 'ingredients' => [], 'steps' => [], 'raw_text' => '']);

        try {
            $empty->import($this->importRequest($adminId, ['owner_id' => $adminId], $this->photo()), $this->response());
            $this->fail('Expected nothing_recognized.');
        } catch (ValidationException $e) {
            $this->assertSame('photo_import.nothing_recognized', $e->getErrorCode());
        }

        try {
            $empty->import($this->importRequest($adminId, ['owner_id' => 999999], $this->photo()), $this->response());
            $this->fail('Expected owner_invalid.');
        } catch (ValidationException $e) {
            $this->assertSame('photo_import.owner_invalid', $e->getErrorCode());
        }
    }

    public function testRequiresAnAdmin(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller([])->import($this->importRequest($userId, ['owner_id' => $userId], $this->photo(), false), $this->response());
    }
}
