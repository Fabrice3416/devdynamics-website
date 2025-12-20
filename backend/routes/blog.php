<?php
/**
 * Blog Routes
 * GET    /api/blog - List published blog posts (paginated)
 * GET    /api/blog/:slug - Get blog post by slug
 * POST   /api/blog - Create blog post (admin)
 * PUT    /api/blog/:id - Update blog post (admin)
 * DELETE /api/blog/:id - Delete blog post (admin)
 */

$router = Router::getInstance();
$db = Database::getInstance();

// List published blog posts with pagination
$router->get('\/blog', function($params) use ($db) {
    try {
        $page = max(1, (int)Router::getQueryParam('page', 1));
        $limit = min(50, max(1, (int)Router::getQueryParam('limit', 10)));
        $offset = ($page - 1) * $limit;

        // Get total count
        $total = $db->fetchOne(
            "SELECT COUNT(*) as count FROM blog_posts WHERE is_published = 1"
        );

        // Get posts
        $posts = $db->fetchAll(
            "SELECT * FROM blog_posts WHERE is_published = 1 ORDER BY published_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        Response::success([
            'posts' => $posts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total['count'],
                'pages' => ceil($total['count'] / $limit)
            ]
        ]);
    } catch (Exception $e) {
        Response::error('Failed to fetch blog posts: ' . $e->getMessage(), 500);
    }
});

// Get blog post by slug
$router->get('\/blog/:slug', function($params) use ($db) {
    try {
        $post = $db->fetchOne(
            "SELECT * FROM blog_posts WHERE slug = ? AND is_published = 1 LIMIT 1",
            [$params['slug']]
        );

        if (!$post) {
            Response::notFound('Blog post not found');
        }

        Response::success($post);
    } catch (Exception $e) {
        Response::error('Failed to fetch blog post: ' . $e->getMessage(), 500);
    }
});

// Create blog post (admin only)
$router->post('\/blog', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['title']) || empty($body['content']) || empty($body['slug'])) {
        Response::error('Title, content and slug are required', 400);
    }

    try {
        // Check if slug exists
        $existing = $db->fetchOne(
            "SELECT id FROM blog_posts WHERE slug = ? LIMIT 1",
            [$body['slug']]
        );

        if ($existing) {
            Response::error('Slug already exists', 409);
        }

        $user = getCurrentUser();
        $status = $body['status'] ?? 'draft';
        $publishedAt = ($status === 'published') ? date('Y-m-d H:i:s') : null;

        $db->query(
            "INSERT INTO blog_posts (title, slug, content, excerpt, featured_image, author_id, status, published_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $body['title'],
                $body['slug'],
                $body['content'],
                $body['excerpt'] ?? null,
                $body['featured_image'] ?? null,
                $user['id'],
                $status,
                $publishedAt
            ]
        );

        $postId = $db->lastInsertId();

        Response::success(['id' => $postId], 'Blog post created successfully', 201);
    } catch (Exception $e) {
        Response::error('Failed to create blog post: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Update blog post (admin only)
$router->put('\/blog/:id', function($params) use ($db) {
    $body = Router::getBody();

    try {
        $post = $db->fetchOne("SELECT * FROM blog_posts WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$post) {
            Response::notFound('Blog post not found');
        }

        $updates = [];
        $values = [];

        $fields = ['title', 'slug', 'content', 'excerpt', 'featured_image', 'status'];
        foreach ($fields as $field) {
            if (isset($body[$field])) {
                $updates[] = "$field = ?";
                $values[] = $body[$field];
            }
        }

        // Update published_at if changing to published
        if (isset($body['status']) && $body['status'] === 'published' && $post['status'] !== 'published') {
            $updates[] = "published_at = ?";
            $values[] = date('Y-m-d H:i:s');
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
        }

        $values[] = $params['id'];
        $sql = "UPDATE blog_posts SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->query($sql, $values);

        Response::success(null, 'Blog post updated successfully');
    } catch (Exception $e) {
        Response::error('Failed to update blog post: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Delete blog post (admin only)
$router->delete('\/blog/:id', function($params) use ($db) {
    try {
        $post = $db->fetchOne("SELECT id FROM blog_posts WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$post) {
            Response::notFound('Blog post not found');
        }

        $db->query("DELETE FROM blog_posts WHERE id = ?", [$params['id']]);

        Response::success(null, 'Blog post deleted successfully');
    } catch (Exception $e) {
        Response::error('Failed to delete blog post: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
