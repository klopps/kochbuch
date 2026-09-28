<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Psr\Http\Message\UploadedFileInterface;
use Kochbuch\Exception\ValidationException;

/**
 * Validates and stores admin-uploaded placeholder images (todo.md
 * "Placeholders for Missing Images") directly under the webroot
 * (public/storage/placeholder-images/) as plain static files - unlike
 * RecipeImageService's per-recipe, auth-gated-serving storage, these images
 * are never privacy-sensitive (the same image is shown to every visitor),
 * so there's no need for a private directory or a serving endpoint.
 */
final class PlaceholderImageStorage
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $storageDir)
    {
    }

    public function store(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Image upload failed.', 'placeholder_image.upload_failed');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationException('Image is larger than 5 MB.', 'placeholder_image.too_large');
        }

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            throw new ValidationException('Could not save the uploaded image.', 'placeholder_image.save_failed');
        }

        $tmpPath = $this->storageDir . '/tmp_' . bin2hex(random_bytes(8));
        $file->moveTo($tmpPath);

        // Trust the actual file bytes, not the client-supplied MIME type.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        if (!isset(self::ALLOWED_MIME_TO_EXT[$mime])) {
            unlink($tmpPath);
            throw new ValidationException('Only JPEG, PNG or WebP images are allowed.', 'placeholder_image.invalid_type');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME_TO_EXT[$mime];
        rename($tmpPath, $this->storageDir . '/' . $filename);

        return $filename;
    }

    public function delete(string $filename): void
    {
        $path = $this->storageDir . '/' . basename($filename);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
