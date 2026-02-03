<?php

use src\Controllers\AdminController;
use src\Controllers\AuthController;
use src\Controllers\BlogController;
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
$router->delete('/newsletter/{id}', [NewsletterController::class, 'deleteSubscriber'], ['auth', 'role:admin']);
$router->get('/newsletter/export', [NewsletterController::class, 'exportCsv'], ['auth', 'role:admin']);


// ✅ Html page - VENDOR or ADMIN role only
$router->post('/pages/create', [HtmlPageController::class, 'createPage'], ['auth', 'role:vendor|admin']);
$router->get('/pages/list', [HtmlPageController::class, 'listPages'], ['auth', 'role:vendor|admin']);
$router->post('/pages/status', [HtmlPageController::class, 'updateStatus'], ['auth', 'role:vendor|admin']);
$router->post('/pages/upload-content', [HtmlPageController::class, 'uploadContent'], ['auth', 'role:vendor|admin']);
$router->get('/pages/content', [HtmlPageController::class, 'getPageContent'], ['auth', 'role:vendor|admin']);
$router->post('/pages/upload-image', [HtmlPageController::class, 'uploadImage'], ['auth', 'role:vendor|admin']);
$router->get('/pages/{slug}', [HtmlPageController::class, 'getPage']); // Public endpoint




// ✅ PUBLIC BLOG ENDPOINTS (No auth - Published only)
$router->get('/blogs', [BlogController::class, 'listPublishedBlogs']);
$router->get('/blogs/{slug}', [BlogController::class, 'getBlogBySlug']);

// ✅ Blog CRUD - ADMIN ONLY
$router->post('/admin/blogs', [BlogController::class, 'createBlog'], ['auth', 'role:admin']);
$router->get('/admin/blogs', [BlogController::class, 'listBlogs'], ['auth', 'role:admin']);
$router->get('/admin/blogs/{id}', [BlogController::class, 'getBlog'], ['auth', 'role:admin']);
$router->put('/admin/blogs/{id}', [BlogController::class, 'updateBlog'], ['auth', 'role:admin']);
$router->post('/admin/blogs/status', [BlogController::class, 'updateStatus'], ['auth', 'role:admin']);
$router->delete('/admin/blogs/{id}', [BlogController::class, 'deleteBlog'], ['auth', 'role:admin']);


//admin
// Admin user management
$router->post('/admin/users/status', [AdminController::class, 'updateUserStatus'], ['auth', 'role:admin']);
$router->get('/admin/users', [AdminController::class, 'listUsers'], ['auth', 'role:admin']);
$router->get('/admin/users/{user_id}', [AdminController::class, 'getUser'], ['auth', 'role:admin']);
$router->get('/admin/users/status/{status}', [AdminController::class, 'getUsersByStatus'], ['auth', 'role:admin']);
