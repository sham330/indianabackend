<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use Exception;

class DashboardController
{
    /**
     * Get dashboard statistics (Admin only)
     */
    public function getStats(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $db = Database::connect();

            // Total counts
            $totalUsers = (int)$db->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
            $totalVendors = (int)$db->query("SELECT COUNT(*) as count FROM vendors")->fetch()['count'];
            $totalBlogs = (int)$db->query("SELECT COUNT(*) as count FROM blogs")->fetch()['count'];
            $totalPages = (int)$db->query("SELECT COUNT(*) as count FROM html_pages")->fetch()['count'];
            $totalNewsletters = (int)$db->query("SELECT COUNT(*) as count FROM newsletter_subscribers")->fetch()['count'];

            // This month counts
            $thisMonthUsers = (int)$db->query("
                SELECT COUNT(*) as count FROM users 
                WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ")->fetch()['count'];

            $thisMonthVendors = (int)$db->query("
                SELECT COUNT(*) as count FROM vendors 
                WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ")->fetch()['count'];

            $thisMonthBlogs = (int)$db->query("
                SELECT COUNT(*) as count FROM blogs 
                WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ")->fetch()['count'];

            $thisMonthPages = (int)$db->query("
                SELECT COUNT(*) as count FROM html_pages 
                WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ")->fetch()['count'];

            $thisMonthNewsletters = (int)$db->query("
                SELECT COUNT(*) as count FROM newsletter_subscribers 
                WHERE MONTH(subscribed_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(subscribed_at) = YEAR(CURRENT_DATE())
            ")->fetch()['count'];

            // Status breakdown
            $usersByStatus = $db->query("
                SELECT status, COUNT(*) as count 
                FROM users 
                GROUP BY status
            ")->fetchAll();

            $vendorsByStatus = $db->query("
                SELECT status, COUNT(*) as count 
                FROM vendors 
                GROUP BY status
            ")->fetchAll();

            $blogsByStatus = $db->query("
                SELECT status, COUNT(*) as count 
                FROM blogs 
                GROUP BY status
            ")->fetchAll();

            $pagesByStatus = $db->query("
                SELECT status, COUNT(*) as count 
                FROM html_pages 
                GROUP BY status
            ")->fetchAll();

            // Recent activity (last 7 days)
            $recentUsers = (int)$db->query("
                SELECT COUNT(*) as count FROM users 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetch()['count'];

            $recentVendors = (int)$db->query("
                SELECT COUNT(*) as count FROM vendors 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetch()['count'];

            $recentBlogs = (int)$db->query("
                SELECT COUNT(*) as count FROM blogs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetch()['count'];

            $recentPages = (int)$db->query("
                SELECT COUNT(*) as count FROM html_pages 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetch()['count'];

            // Last month counts for growth calculation
            $lastMonthUsers = (int)$db->query("
                SELECT COUNT(*) as count FROM users 
                WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
            ")->fetch()['count'];

            $lastMonthVendors = (int)$db->query("
                SELECT COUNT(*) as count FROM vendors 
                WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
            ")->fetch()['count'];

            $lastMonthBlogs = (int)$db->query("
                SELECT COUNT(*) as count FROM blogs 
                WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
            ")->fetch()['count'];

            $lastMonthPages = (int)$db->query("
                SELECT COUNT(*) as count FROM html_pages 
                WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
            ")->fetch()['count'];

            // Calculate growth percentages
            $userGrowth = $lastMonthUsers > 0 ? round((($thisMonthUsers - $lastMonthUsers) / $lastMonthUsers) * 100, 2) : 0;
            $vendorGrowth = $lastMonthVendors > 0 ? round((($thisMonthVendors - $lastMonthVendors) / $lastMonthVendors) * 100, 2) : 0;
            $blogGrowth = $lastMonthBlogs > 0 ? round((($thisMonthBlogs - $lastMonthBlogs) / $lastMonthBlogs) * 100, 2) : 0;
            $pageGrowth = $lastMonthPages > 0 ? round((($thisMonthPages - $lastMonthPages) / $lastMonthPages) * 100, 2) : 0;

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'stats' => [
                    'totals' => [
                        'users' => $totalUsers,
                        'vendors' => $totalVendors,
                        'blogs' => $totalBlogs,
                        'pages' => $totalPages,
                        'newsletters' => $totalNewsletters
                    ],
                    'this_month' => [
                        'users' => $thisMonthUsers,
                        'vendors' => $thisMonthVendors,
                        'blogs' => $thisMonthBlogs,
                        'pages' => $thisMonthPages,
                        'newsletters' => $thisMonthNewsletters
                    ],
                    'growth' => [
                        'users' => $userGrowth,
                        'vendors' => $vendorGrowth,
                        'blogs' => $blogGrowth,
                        'pages' => $pageGrowth
                    ],
                    'recent_7_days' => [
                        'users' => $recentUsers,
                        'vendors' => $recentVendors,
                        'blogs' => $recentBlogs,
                        'pages' => $recentPages
                    ],
                    'breakdown' => [
                        'users_by_status' => $usersByStatus,
                        'vendors_by_status' => $vendorsByStatus,
                        'blogs_by_status' => $blogsByStatus,
                        'pages_by_status' => $pagesByStatus
                    ]
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Dashboard stats error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }
}
