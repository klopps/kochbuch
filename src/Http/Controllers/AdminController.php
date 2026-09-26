<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Plain aggregate counts for the admin dashboard - deliberately raw totals
 * (not visibility-filtered like RecipeRepository::search()), since an admin
 * dashboard should reflect everything that exists, not just what the
 * current viewer could otherwise see.
 */
final class AdminController extends BaseController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function dashboardStats(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => [
            'recipe_count' => (int) $this->pdo->query('SELECT COUNT(*) FROM recipe')->fetchColumn(),
            'user_count' => (int) $this->pdo->query('SELECT COUNT(*) FROM user')->fetchColumn(),
            'category_count' => (int) $this->pdo->query('SELECT COUNT(*) FROM category')->fetchColumn(),
        ]]);
    }
}
