<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\ValidationException;

/**
 * Admin quick editor (todo.md "Quick Editor", page /admin/quickeditor): changes
 * single fields of a recipe straight from the list -
 *
 *   PUT /api/v1/admin/recipes/{id}/quick
 *   {owner_id?, name?, servings?, diet?: none|vegan|vegetarian|pescetarian, visibility?}
 *
 * Only the sent fields change; ingredients, steps and tags stay untouched
 * (tags have their own PUT /recipes/{id}/tags, deleting goes through the
 * normal soft-delete DELETE /recipes/{id} into the admin trash). Same rules
 * as RecipeDataValidator for each field.
 */
final class QuickEditController extends BaseController
{
    private const VISIBILITIES = ['private', 'internal', 'public'];
    private const DIETS = ['none', 'vegan', 'vegetarian', 'pescetarian'];

    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly UserRepository $users,
    ) {
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $recipe = $this->recipes->find((int) $args['id']);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }

        $body = $this->jsonBody($request);
        $fields = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new ValidationException('A recipe name is required.', 'recipe.name_required');
            }
            $fields['name'] = mb_substr($name, 0, 255);
        }
        if (array_key_exists('servings', $body)) {
            $servings = (int) $body['servings'];
            if ($servings < 1) {
                throw new ValidationException('Servings must be at least 1.', 'recipe.invalid_servings');
            }
            $fields['servings'] = $servings;
        }
        if (array_key_exists('visibility', $body)) {
            if (!in_array($body['visibility'], self::VISIBILITIES, true)) {
                throw new ValidationException('Invalid visibility.', 'recipe.invalid_visibility');
            }
            $fields['visibility'] = $body['visibility'];
        }
        if (array_key_exists('diet', $body)) {
            if (!in_array($body['diet'], self::DIETS, true)) {
                throw new ValidationException('Invalid diet.', 'recipe.invalid_diet');
            }
            // Mutually exclusive, same as everywhere else (todo.md
            // "Unambiguity of recipe attributes").
            $fields['is_vegan'] = $body['diet'] === 'vegan';
            $fields['is_vegetarian'] = $body['diet'] === 'vegetarian';
            $fields['is_pescetarian'] = $body['diet'] === 'pescetarian';
        }
        if (array_key_exists('owner_id', $body)) {
            $ownerId = (int) $body['owner_id'];
            if ($ownerId <= 0 || $this->users->findById($ownerId) === null) {
                throw new ValidationException('Unknown owner.', 'quick_edit.owner_invalid');
            }
            $fields['user_id'] = $ownerId;
        }

        $this->recipes->quickUpdate($recipe['id'], $fields);

        return $this->json($response, ['data' => $this->recipes->find($recipe['id'])]);
    }
}
