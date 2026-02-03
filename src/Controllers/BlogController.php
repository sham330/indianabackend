<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use InvalidArgumentException;
use RuntimeException;
use Exception;

class BlogController
{
    /**
     * Create blog post (ADMIN ONLY)
     */
    public function createBlog(): void
    {
        try {
            // 1️⃣ ADMIN ONLY CHECK
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['title']) || 
                empty($input['content'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Title and content required']);
                return;
            }

            $title = trim($input['title']);
            $slug = $this->generateSlug($title);
            $content = trim($input['content']);
            $featuredImage = trim($input['featured_image'] ?? '');
            $status = in_array($input['status'] ?? 'draft', ['draft', 'published', 'archived']) 
                ? $input['status'] 
                : 'draft';
            $seoTitle = trim($input['seo_title'] ?? $title);
            $seoDescription = trim($input['seo_description'] ?? '');

            $db = Database::connect();

            // 2️⃣ Check slug uniqueness
            $checkStmt = $db->prepare("SELECT id FROM blogs WHERE slug = :slug");
            $checkStmt->execute(['slug' => $slug]);
            
            if ($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['message' => 'Slug already exists']);
                return;
            }

            // 3️⃣ Insert blog post
            $stmt = $db->prepare("
                INSERT INTO blogs (title, slug, content, featured_image, status, seo_title, seo_description) 
                VALUES (:title, :slug, :content, :featured_image, :status, :seo_title, :seo_description)
            ");
            
            $result = $stmt->execute([
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'featured_image' => $featuredImage,
                'status' => $status,
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription
            ]);

            if (!$result) {
                throw new RuntimeException('Failed to create blog');
            }

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Blog post created successfully',
                'blog' => [
                    'id' => $db->lastInsertId(),
                    'title' => $title,
                    'slug' => $slug,
                    'status' => $status
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Create blog error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * List all blog posts (ADMIN ONLY)
     */
    public function listBlogs(): void
    {
        try {
            // 1️⃣ ADMIN ONLY CHECK
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $db = Database::connect();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 20;
            $status = $_GET['status'] ?? null;
            $offset = ($page - 1) * $limit;

            // 2️⃣ Build query with optional status filter
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if ($status && in_array($status, ['draft', 'published', 'archived'])) {
                $whereClause .= " AND status = :status";
                $params['status'] = $status;
            }

            $query = "
                SELECT id, title, slug, featured_image, status, seo_title, 
                       published_at, created_at, updated_at
                FROM blogs 
                $whereClause 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $db->prepare($query);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            $stmt->execute();
            $blogs = $stmt->fetchAll();

            // 3️⃣ Total count
            $countQuery = "SELECT COUNT(*) as total FROM blogs $whereClause";
            $countStmt = $db->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue(":$key", $value);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetch()['total'];

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'blogs' => $blogs,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'last_page' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("List blogs error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get single blog post (ADMIN ONLY)
     */
    public function getBlog(): void
    {
        try {
            // 1️⃣ ADMIN ONLY CHECK
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $blogId = (int)($_GET['id'] ?? 0);
            if (!$blogId) {
                http_response_code(400);
                echo json_encode(['message' => 'Blog ID required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT * FROM blogs WHERE id = :id
            ");
            $stmt->execute(['id' => $blogId]);
            $blog = $stmt->fetch();

            if (!$blog) {
                http_response_code(404);
                echo json_encode(['message' => 'Blog not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'blog' => $blog
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get blog error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update blog post (ADMIN ONLY)
     */
    public function updateBlog(): void
    {
        try {
            // 1️⃣ ADMIN ONLY CHECK
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['id']) || 
                empty($input['title']) || 
                empty($input['content'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Blog ID, title, and content required']);
                return;
            }

            $blogId = (int)$input['id'];
            $title = trim($input['title']);
            $slug = $this->generateSlug($title);
            $content = trim($input['content']);
            $featuredImage = trim($input['featured_image'] ?? '');
            $status = in_array($input['status'] ?? 'draft', ['draft', 'published', 'archived']) 
                ? $input['status'] 
                : 'draft';
            $seoTitle = trim($input['seo_title'] ?? $title);
            $seoDescription = trim($input['seo_description'] ?? '');

            $db = Database::connect();

            // 2️⃣ Check blog exists
            $checkStmt = $db->prepare("SELECT id FROM blogs WHERE id = :id");
            $checkStmt->execute(['id' => $blogId]);
            
            if (!$checkStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['message' => 'Blog not found']);
                return;
            }

            // 3️⃣ Update blog (set published_at if status = published)
            $extraFields = $status === 'published' && empty($input['published_at']) 
                ? ', published_at = CURRENT_TIMESTAMP' 
                : '';
            
            $stmt = $db->prepare("
                UPDATE blogs 
                SET title = :title, slug = :slug, content = :content, 
                    featured_image = :featured_image, status = :status,
                    seo_title = :seo_title, seo_description = :seo_description {$extraFields}
                WHERE id = :id
            ");
            
            $result = $stmt->execute([
                'id' => $blogId,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'featured_image' => $featuredImage,
                'status' => $status,
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription
            ]);

            if (!$result) {
                throw new RuntimeException('Failed to update blog');
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Blog post updated successfully',
                'blog_id' => $blogId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update blog error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update blog status (ADMIN ONLY)
     */
    public function updateStatus(): void
    {
        try {
            // 1️⃣ ADMIN ONLY CHECK
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['id']) || 
                !isset($input['status'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Blog ID and status required']);
                return;
            }

            $blogId = (int)$input['id'];
            $status = $input['status'];

            if (!in_array($status, ['draft', 'published', 'archived'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status']);
                return;
            }

            $db = Database::connect();

            // 2️⃣ Update status + set published_at if published
            $extraFields = $status === 'published' ? ', published_at = CURRENT_TIMESTAMP' : '';
            $stmt = $db->prepare("
                UPDATE blogs 
                SET status = :status {$extraFields}
                WHERE id = :id
            ");

            $result = $stmt->execute([
                'status' => $status,
                'id' => $blogId
            ]);

            if (!$result || !$stmt->rowCount()) {
                http_response_code(404);
                echo json_encode(['message' => 'Blog not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => "Blog status updated to '{$status}'",
                'blog_id' => $blogId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update blog status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Delete blog post (ADMIN ONLY)
     */
    public function deleteBlog(): void
    {
        try {
            // 1️⃣ ADMIN ONLY CHECK
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $blogId = (int)($_GET['id'] ?? 0);
            if (!$blogId) {
                http_response_code(400);
                echo json_encode(['message' => 'Blog ID required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("DELETE FROM blogs WHERE id = :id");
            $result = $stmt->execute(['id' => $blogId]);

            if (!$result || !$stmt->rowCount()) {
                http_response_code(404);
                echo json_encode(['message' => 'Blog not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Blog post deleted successfully',
                'blog_id' => $blogId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Delete blog error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }
    /**
 * PUBLIC: List published blogs with search + date filter
 * Returns: title, content, created_at
 */
public function listPublishedBlogs(): void
{
    try {
        $db = Database::connect();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 10;
        $search = trim($_GET['search'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $offset = ($page - 1) * $limit;

        // 1️⃣ Base query - ONLY PUBLISHED
        $whereClause = "WHERE status = 'published'";
        $params = [];

        // 2️⃣ Search filter (title OR content)
        if (!empty($search)) {
            $whereClause .= " AND (title LIKE :search OR content LIKE :search)";
            $params['search'] = "%{$search}%";
        }

        // 3️⃣ Date range filter
        if (!empty($dateFrom)) {
            $whereClause .= " AND DATE(created_at) >= :date_from";
            $params['date_from'] = $dateFrom;
        }
        
        if (!empty($dateTo)) {
            $whereClause .= " AND DATE(created_at) <= :date_to";
            $params['date_to'] = $dateTo;
        }

        // 4️⃣ Main query (title, content, created_at only)
        $query = "
            SELECT id, title, slug, LEFT(content, 300) as excerpt, created_at
            FROM blogs 
            $whereClause 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->execute();
        $blogs = $stmt->fetchAll();

        // 5️⃣ Total count
        $countQuery = "SELECT COUNT(*) as total FROM blogs $whereClause";
        $countStmt = $db->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(":$key", $value);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetch()['total'];

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'blogs' => $blogs,
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'last_page' => ceil($total / $limit)
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Public list blogs error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}

/**
 * PUBLIC: Get single published blog by slug
 * Returns: title, content, created_at
 */
public function getBlogBySlug(): void
{
    try {
        $slug = trim($_GET['slug'] ?? '');
        if (empty($slug)) {
            http_response_code(400);
            echo json_encode(['message' => 'Slug required']);
            return;
        }

        $db = Database::connect();
        
        // 1️⃣ Get ONLY PUBLISHED blog by slug
        $stmt = $db->prepare("
            SELECT id, title, slug, content, featured_image, seo_title, seo_description, created_at
            FROM blogs 
            WHERE slug = :slug AND status = 'published'
        ");
        $stmt->execute(['slug' => $slug]);
        $blog = $stmt->fetch();

        if (!$blog) {
            http_response_code(404);
            echo json_encode(['message' => 'Published blog not found']);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'blog' => [
                'id' => $blog['id'],
                'title' => $blog['title'],
                'slug' => $blog['slug'],
                'content' => $blog['content'],
                'featured_image' => $blog['featured_image'],
                'seo_title' => $blog['seo_title'],
                'seo_description' => $blog['seo_description'],
                'created_at' => $blog['created_at']
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Public get blog by slug error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}


    /**
     * Generate SEO-friendly slug
     */
    private function generateSlug(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return substr($slug, 0, 255);
    }
}
