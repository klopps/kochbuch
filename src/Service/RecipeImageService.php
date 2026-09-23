<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Psr\Http\Message\UploadedFileInterface;
use Kochbuch\Exception\ValidationException;

/**
 * Validates and stores uploaded recipe images on disk under
 * storage/recipe-images/{recipeId}/ (outside the webroot - see
 * database/migrations/002_create_recipe_schema.sql's recipe_image comment).
 * The DB row (RecipeRepository::addImage()) only ever holds the generated
 * filename; this class owns the actual bytes.
 */
final class RecipeImageService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_IMAGES_PER_RECIPE = 8;
    private const ALLOWED_MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $storageDir)
    {
    }

    public function store(int $recipeId, UploadedFileInterface $file, int $existingImageCount): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Image upload failed.', 'recipe.image_upload_failed');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationException('Image is larger than 5 MB.', 'recipe.image_too_large');
        }
        if ($existingImageCount >= self::MAX_IMAGES_PER_RECIPE) {
            throw new ValidationException('A recipe can have at most 8 images.', 'recipe.too_many_images');
        }

        $dir = $this->storageDir . '/' . $recipeId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ValidationException('Could not save the uploaded image.', 'recipe.image_save_failed');
        }

        $tmpPath = $dir . '/tmp_' . bin2hex(random_bytes(8));
        $file->moveTo($tmpPath);

        // Trust the actual file bytes, not the client-supplied MIME type.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        if (!isset(self::ALLOWED_MIME_TO_EXT[$mime])) {
            unlink($tmpPath);
            throw new ValidationException('Only JPEG, PNG or WebP images are allowed.', 'recipe.image_invalid_type');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME_TO_EXT[$mime];
        rename($tmpPath, $dir . '/' . $filename);

        return $filename;
    }

    public function delete(int $recipeId, string $filename): void
    {
        $path = $this->storageDir . '/' . $recipeId . '/' . basename($filename);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function path(int $recipeId, string $filename): string
    {
        return $this->storageDir . '/' . $recipeId . '/' . basename($filename);
    }

    public function mimeTypeFor(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
