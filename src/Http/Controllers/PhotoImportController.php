<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\GeminiRecipeExtractor;
use Kochbuch\Service\RecipeDataValidator;
use Kochbuch\Service\RecipeOcrParser;
use Kochbuch\Service\VisionOcrService;

/**
 * Admin bulk photo import (todo.md "Bulk Photo Import", page
 * /admin/photo-import): ONE photo per request becomes ONE recipe, saved
 * directly for the chosen owner. The page sends the selected photos one
 * after another - each request stays short (a single recognition call), so
 * no request runs into PHP's execution time limit however many photos are
 * imported, and a rate limit (HTTP 429 from GeminiRecipeExtractor, with
 * retry_at) can be waited out between photos.
 *
 * Recognition is the same as the normal photo import: Gemini when a key is
 * configured, else Google Vision + RecipeOcrParser. Every recipe gets the
 * tag "foto-import" so the imports stay findable for checking afterwards;
 * the photo itself is not attached (user's choice).
 */
final class PhotoImportController extends BaseController
{
    public const IMPORT_TAG = 'foto-import';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly UserRepository $users,
        private readonly ?GeminiRecipeExtractor $gemini,
        private readonly VisionOcrService $visionOcr = new VisionOcrService(),
        private readonly RecipeOcrParser $ocrParser = new RecipeOcrParser(),
        private readonly RecipeDataValidator $validator = new RecipeDataValidator(),
    ) {
    }

    public function import(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        @set_time_limit(120);

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $ownerId = (int) ($body['owner_id'] ?? 0);
        if ($ownerId <= 0 || $this->users->findById($ownerId) === null) {
            throw new ValidationException('Unknown owner.', 'photo_import.owner_invalid');
        }
        $visibility = (string) ($body['visibility'] ?? 'internal');

        $file = $request->getUploadedFiles()['image'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('No image file provided.', 'recipe.image_missing');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationException('Image is larger than 5 MB.', 'recipe.image_too_large');
        }
        $bytes = (string) $file->getStream()->getContents();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException('Only JPEG, PNG or WebP images are allowed.', 'recipe.image_invalid_type');
        }

        $draft = $this->gemini !== null
            ? $this->gemini->extract($bytes, $mime)
            : $this->ocrParser->parse($this->visionOcr->recognizeDocument($bytes));
        if (($draft['ingredients'] ?? []) === [] && ($draft['steps'] ?? []) === []) {
            throw new ValidationException('No recipe was recognized on the photo.', 'photo_import.nothing_recognized');
        }

        $name = trim((string) ($draft['name'] ?? ''));
        if ($name === '') {
            // A recipe needs a name - fall back to the file name, so it can
            // still be found and renamed afterwards.
            $fileName = pathinfo((string) ($file->getClientFilename() ?? ''), PATHINFO_FILENAME);
            $name = 'Foto-Import ' . ($fileName !== '' ? $fileName : date('Y-m-d H:i'));
        }

        $data = $this->validator->validate([
            'name' => $name,
            'visibility' => $visibility,
            'notes' => $draft['notes'] ?? null,
            'ingredients' => $draft['ingredients'],
            'steps' => $draft['steps'],
            'tags' => [self::IMPORT_TAG],
        ]);
        $id = $this->recipes->create($ownerId, $data);

        return $this->json($response, ['data' => [
            'id' => $id,
            'name' => $data['name'],
            'ingredient_count' => count($data['ingredients']),
            'step_count' => count($data['steps']),
        ]], 201);
    }
}
