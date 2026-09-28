<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\PlaceholderImageStorage;

/**
 * Admin CRUD for the keyword->image mappings behind todo.md "Placeholders
 * for Missing Images" - RecipeRepository is the read-side consumer of this
 * data (see its placeholders()/resolvePlaceholderImage()).
 */
final class PlaceholderImageController extends BaseController
{
    public function __construct(
        private readonly PlaceholderImageRepository $placeholders,
        private readonly PlaceholderImageStorage $storage,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->placeholders->all()]);
    }

    public function create(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $uploaded = $request->getUploadedFiles();
        if (!isset($uploaded['image'])) {
            throw new ValidationException('No image file provided.', 'placeholder_image.image_missing');
        }
        $filename = $this->storage->store($uploaded['image']);

        $body = (array) $request->getParsedBody();
        $id = $this->placeholders->create($filename, $this->isDefaultFlag($body), $this->parseKeywords($body));

        return $this->json($response, ['data' => $this->placeholders->find($id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);

        $existing = $this->placeholders->find((int) $args['id']);
        if ($existing === null) {
            throw new NotFoundException('Placeholder image not found.');
        }

        $uploaded = $request->getUploadedFiles();
        $filename = isset($uploaded['image']) && $uploaded['image']->getError() === UPLOAD_ERR_OK
            ? $this->storage->store($uploaded['image'])
            : null;

        $body = (array) $request->getParsedBody();
        $this->placeholders->update($existing['id'], $filename, $this->isDefaultFlag($body), $this->parseKeywords($body));

        if ($filename !== null) {
            $this->storage->delete($existing['filename']);
        }

        return $this->json($response, ['data' => $this->placeholders->find($existing['id'])]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);

        $existing = $this->placeholders->find((int) $args['id']);
        if ($existing === null) {
            throw new NotFoundException('Placeholder image not found.');
        }

        $this->placeholders->delete($existing['id']);
        $this->storage->delete($existing['filename']);

        return $response->withStatus(204);
    }

    private function isDefaultFlag(array $body): bool
    {
        return !empty($body['is_default']);
    }

    /**
     * @return array<int,array{locale:string,keyword:string}>
     */
    private function parseKeywords(array $body): array
    {
        $keywords = [];
        foreach (['de', 'en'] as $locale) {
            $raw = (string) ($body['keywords_' . $locale] ?? '');
            foreach (explode(',', $raw) as $keyword) {
                $keyword = trim($keyword);
                if ($keyword !== '') {
                    $keywords[] = ['locale' => $locale, 'keyword' => $keyword];
                }
            }
        }

        return $keywords;
    }
}
