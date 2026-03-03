<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use PDO;
use RuntimeException;
use Exception;

class PublicHtmlPageController
{
    private const DEFAULT_PER_PAGE = 12;
    private const MAX_PER_PAGE = 100;
    private const ADS_PER_SECTION = 4;
    private const VALID_TYPES = [
        'restaurant', 'real-estate', 'jobs', 'services', 
        'electronics', 'vehicles', 'fashion', 'events'
    ];
    private const VALID_SORTS = [
        'latest' => 'p.created_at DESC',
        'oldest' => 'p.created_at ASC',
        'popular' => 'p.view_count DESC'
    ];

    /**
     * Homepage endpoint - 4 popular + 4 latest ads
     */
    public function getHomePageData(): void
    {
        try {
            $db = Database::connect();

            // Get 4 latest published pages
            $latestStmt = $db->prepare("
                SELECT 
                    id, slug, seo_title, seo_description, 
                    screenshot_image, type, created_at, 
                    COALESCE(view_count, 0) as view_count
                FROM html_pages 
                WHERE status = 'published'
                ORDER BY created_at DESC
                LIMIT :limit
            ");
            $latestStmt->bindValue(':limit', self::ADS_PER_SECTION, PDO::PARAM_INT);
            $latestStmt->execute();
            $latestAds = $latestStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get 4 most popular published pages
            $popularStmt = $db->prepare("
                SELECT 
                    id, slug, seo_title, seo_description, 
                    screenshot_image, type, created_at, 
                    COALESCE(view_count, 0) as view_count
                FROM html_pages 
                WHERE status = 'published'
                ORDER BY view_count DESC, created_at DESC
                LIMIT :limit
            ");
            $popularStmt->bindValue(':limit', self::ADS_PER_SECTION, PDO::PARAM_INT);
            $popularStmt->execute();
            $popularAds = $popularStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get type distribution
            $typeStatsStmt = $db->prepare("
                SELECT type, COUNT(*) as count
                FROM html_pages 
                WHERE status = 'published'
                GROUP BY type
                ORDER BY count DESC
            ");
            $typeStatsStmt->execute();
            $typeStats = $typeStatsStmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            http_response_code(200);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'latest_ads' => $latestAds,
                    'popular_ads' => $popularAds,
                    'type_stats' => $typeStats,
                    'stats' => [
                        'ads_per_section' => self::ADS_PER_SECTION,
                        'total_types' => count(self::VALID_TYPES)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load homepage data'
            ]);
        }
    }

    /**
     * List published pages with filters and pagination
     */
    public function listPublishedPages(): void
    {
        try {
            // Get query parameters
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(self::MAX_PER_PAGE, max(1, (int)($_GET['per_page'] ?? self::DEFAULT_PER_PAGE)));
            $type = trim($_GET['type'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $sort = trim($_GET['sort'] ?? 'latest');

            $db = Database::connect();

            // Build WHERE conditions
            $whereConditions = ['p.status = :published'];
            $params = ['published' => 'published'];

            // Type filter
            if (in_array($type, self::VALID_TYPES, true)) {
                $whereConditions[] = 'p.type = :type';
                $params['type'] = $type;
            }

            // Search filter
            if (!empty($search)) {
                $whereConditions[] = '(p.seo_title ILIKE :search OR p.seo_description ILIKE :search)';
                $params['search'] = "%{$search}%";
            }

            $whereClause = implode(' AND ', $whereConditions);
            $orderBy = self::VALID_SORTS[$sort] ?? self::VALID_SORTS['latest'];

            // Count total
            $countStmt = $db->prepare("
                SELECT COUNT(*) as total
                FROM html_pages p
                WHERE {$whereClause}
            ");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch()['total'];
            $totalPages = (int)ceil($total / $perPage);

            // Adjust page
            if ($page > $totalPages) {
                $page = $totalPages ?: 1;
            }

            $offset = ($page - 1) * $perPage;

            // Fetch results
            $stmt = $db->prepare("
                SELECT 
                    p.id, p.slug, p.seo_title, p.seo_description,
                    p.screenshot_image, p.type, p.created_at,
                    COALESCE(p.view_count, 0) as view_count
                FROM html_pages p
                WHERE {$whereClause}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset
            ");

            foreach ($params as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            http_response_code(200);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'pages' => $pages,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' => $totalPages,
                        'has_next' => $page < $totalPages,
                        'has_prev' => $page > 1,
                    ],
                    'filters' => [
                        'type' => $type ?: null,
                        'search' => $search ?: null,
                        'sort' => $sort
                    ]
                ]
            ]);

        } catch (Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Internal server error'
            ]);
        }
    }

    /**
     * Get single page by slug
     */
    public function getPageBySlug(): void
    {
        try {
            $slug = trim($_GET['slug'] ?? '');

            if (empty($slug)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Slug required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT id, slug, seo_title, seo_description, 
                       screenshot_image, type, created_at,
                       COALESCE(view_count, 0) as view_count
                FROM html_pages 
                WHERE slug = :slug AND status = 'published'
            ");
            $stmt->execute(['slug' => $slug]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$page) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Page not found']);
                return;
            }

            header('Content-Type: application/json');
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'page' => $page
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Internal server error'
            ]);
        }
    }
    /**
 * Increment view count for a page by ID (string)
 * Call this on every page hit/view
 */
public function incrementPageView(): void
{
    try {
        $pageId = trim($_GET['id'] ?? '');

        if (empty($pageId)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false, 
                'message' => 'Valid page ID required'
            ]);
            return;
        }

        $db = Database::connect();
        
        // Atomic increment using INSERT ... ON CONFLICT
        $stmt = $db->prepare("
            INSERT INTO html_pages (id, view_count) 
            VALUES (:id, COALESCE(view_count, 0) + 1)
            ON CONFLICT (id) DO UPDATE SET 
                view_count = html_pages.view_count + 1
        ");
        
        $stmt->bindValue(':id', $pageId, PDO::PARAM_STR);
        $stmt->execute();

        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'View count incremented'
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to increment view count'
        ]);
    }
}

}
