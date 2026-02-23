<?php

use src\Controllers\AdminController;
use src\Controllers\AdminAuthController;
use src\Controllers\AdminVendorController;
use src\Controllers\AuthController;
use src\Controllers\BlogController;
use src\Controllers\DashboardController;
use src\Controllers\HtmlPageController;
use src\Controllers\NewsletterController;
use src\Controllers\PasswordResetController;
use src\Controllers\VendorController;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

$router->post('/auth/register', [AuthController::class, 'register']);
$router->post('/auth/verify-otp', [AuthController::class, 'verifyOtp']); 
$router->post('/auth/login', [AuthController::class, 'login']);

// Admin Auth Routes (2-step: login sends OTP, verify-otp returns token)
$router->post('/admin/auth/login', [AdminAuthController::class, 'login']);
$router->post('/admin/auth/verify-otp', [AdminAuthController::class, 'verifyOtp']);
$router->get('/profile', [AuthController::class, 'profile'], ['auth']);
$router->put('/auth/profile', [AuthController::class, 'updateProfile'], ['auth']);
$router->post('/auth/resend-otp', [AuthController::class, 'resendOtp']);
// Google Auth Routes (PUBLIC - NO middleware)
$router->get('/auth/google', [AuthController::class, 'googleLogin']);
$router->get('/auth/google/callback', [AuthController::class, 'googleCallback']);



// Forgot Password Routes (PUBLIC)
$router->post('/auth/forgot-password', [PasswordResetController::class, 'sendResetOtp']);
$router->post('/auth/reset-password', [PasswordResetController::class, 'verifyResetOtp']);




//Vendor routes
$router->post('/vendor/create', [VendorController::class, 'createVendor'], ['auth', 'role:vendor|admin']);
// ✅ GET Vendor Profile - Matches user_id via auth middleware
$router->get('/vendor/profile', [VendorController::class, 'getVendor'], ['auth', 'role:vendor|admin']);
$router->post('/vendor/update', [VendorController::class, 'updateVendor'],['auth', 'role:vendor|admin']);



// ✅ Public subscribe (no auth required)
$router->post('/newsletter/subscribe', [NewsletterController::class, 'subscribe']);
$router->get('/newsletter', [NewsletterController::class, 'listSubscribers'], ['auth', 'role:admin']);
$router->post('/newsletter/status', [NewsletterController::class, 'updateSubscriber'], ['auth', 'role:admin']);
$router->delete('/newsletter', [NewsletterController::class, 'deleteSubscriber'], ['auth', 'role:admin']);
$router->get('/newsletter/export', [NewsletterController::class, 'exportCsv'], ['auth', 'role:admin']);


// ✅ Html page - VENDOR or ADMIN role only
$router->post('/pages/create', [HtmlPageController::class, 'createPage'], ['auth', 'role:vendor|admin']);
$router->get('/pages/list', [HtmlPageController::class, 'listPages'], ['auth', 'role:vendor|admin']);
$router->post('/pages/status', [HtmlPageController::class, 'updateStatus'], ['auth', 'role:vendor|admin']);
$router->post('/pages/upload-content', [HtmlPageController::class, 'uploadContent'], ['auth', 'role:vendor|admin']);
$router->get('/pages/content', [HtmlPageController::class, 'getPageContent'], ['auth', 'role:vendor|admin']);
$router->post('/pages/upload-image', [HtmlPageController::class, 'uploadImage'], ['auth', 'role:vendor|admin']);
$router->get('/pages/page', [HtmlPageController::class, 'getPage']); // Public endpoint
$router->delete('/pages/delete', [HtmlPageController::class, 'deletePage'], ['auth', 'role:vendor|admin']);
$router->get('/admin/pages', [HtmlPageController::class, 'listAllPages'], ['auth', 'role:admin']);



// ✅ PUBLIC BLOG ENDPOINTS (No auth - Published only)
$router->get('/blog', [BlogController::class, 'getBlogBySlug']);
$router->get('/blogs', [BlogController::class, 'listPublishedBlogs']);

// ✅ Blog CRUD - ADMIN ONLY
$router->post('/admin/blogs', [BlogController::class, 'createBlog'], ['auth', 'role:admin']);
$router->post('/admin/blogs/status', [BlogController::class, 'updateStatus'], ['auth', 'role:admin']);
$router->get('/admin/blog', [BlogController::class, 'getBlog'], ['auth', 'role:admin']);
$router->put('/admin/blog', [BlogController::class, 'updateBlog'], ['auth', 'role:admin']);
$router->delete('/admin/blog', [BlogController::class, 'deleteBlog'], ['auth', 'role:admin']);
$router->get('/admin/blogs', [BlogController::class, 'listBlogs'], ['auth', 'role:admin']);


//admin
// Admin dashboard
$router->get('/admin/dashboard', [DashboardController::class, 'getStats'], ['auth', 'role:admin']);

// Admin user management
$router->get('/admin/users', [AdminController::class, 'listUsers'], ['auth', 'role:admin']);
$router->get('/admin/user', [AdminController::class, 'getUser'], ['auth', 'role:admin']);
$router->get('/admin/users/by-status', [AdminController::class, 'getUsersByStatus'], ['auth', 'role:admin']);
$router->post('/admin/users/status', [AdminController::class, 'updateUserStatus'], ['auth', 'role:admin']);
$router->post('/admin/user/update', [AdminController::class, 'updateUser'], ['auth', 'role:admin']);
$router->post('/admin/user/password', [AdminController::class, 'updateUserPassword'], ['auth', 'role:admin']);
$router->post('/admin/user/verify-email', [AdminController::class, 'verifyUserEmail'], ['auth', 'role:admin']);
$router->delete('/admin/user', [AdminController::class, 'deleteUser'], ['auth', 'role:admin']);

// Admin vendor management
$router->get('/admin/vendors', [AdminVendorController::class, 'listVendors'], ['auth', 'role:admin']);
$router->get('/admin/vendor', [AdminVendorController::class, 'getVendor'], ['auth', 'role:admin']);
$router->get('/admin/vendors/by-status', [AdminVendorController::class, 'getVendorsByStatus'], ['auth', 'role:admin']);
$router->post('/admin/vendor/update', [AdminVendorController::class, 'updateVendor'], ['auth', 'role:admin']);
$router->post('/admin/vendor/status', [AdminVendorController::class, 'updateVendorStatus'], ['auth', 'role:admin']);
$router->delete('/admin/vendor', [AdminVendorController::class, 'deleteVendor'], ['auth', 'role:admin']);
