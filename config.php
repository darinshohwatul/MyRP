<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'myrp_social';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper Functions

/**
 * Sanitize input data
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to another page
 */
if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit();
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// ... rest of your functions remain the same
/**
 * Get current logged-in user information
 */
function getCurrentUser() {
    global $pdo;
    
    if (!isLoggedIn()) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Get user by ID
 */
function getUserById($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Get user by username
 */
function getUserByUsername($username) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

/**
 * Convert timestamp to time ago format
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) {
        return 'just now';
    } elseif ($time < 3600) {
        $minutes = floor($time / 60);
        return $minutes . 'm ago';
    } elseif ($time < 86400) {
        $hours = floor($time / 3600);
        return $hours . 'h ago';
    } elseif ($time < 2592000) {
        $days = floor($time / 86400);
        return $days . 'd ago';
    } elseif ($time < 31536000) {
        $months = floor($time / 2592000);
        return $months . 'mo ago';
    } else {
        $years = floor($time / 31536000);
        return $years . 'y ago';
    }
}

/**
 * Format number (1000 -> 1K, 1000000 -> 1M)
 */
function formatNumber($number) {
    if ($number >= 1000000) {
        return number_format($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return number_format($number / 1000, 1) . 'K';
    }
    return $number;
}

/**
 * Check if user is following another user
 */
function isFollowing($follower_id, $following_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$follower_id, $following_id]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Get user's follower count
 */
function getFollowerCount($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

/**
 * Get user's following count
 */
function getFollowingCount($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

/**
 * Get user's post count
 */
function getPostCount($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

/**
 * Check if user liked a post
 */
function hasUserLikedPost($user_id, $post_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$user_id, $post_id]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Get post like count
 */
function getPostLikeCount($post_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
    $stmt->execute([$post_id]);
    return $stmt->fetchColumn();
}

/**
 * Get post comment count
 */
function getPostCommentCount($post_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = ?");
    $stmt->execute([$post_id]);
    return $stmt->fetchColumn();
}

/**
 * Upload file with validation
 */
function uploadFile($file, $destination_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif']) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    // Create destination directory if it doesn't exist
    if (!file_exists($destination_dir)) {
        mkdir($destination_dir, 0777, true);
    }
    
    // Validate file type
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_types)) {
        return false;
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        return false;
    }
    
    // Generate unique filename
    $filename = uniqid() . '.' . $file_extension;
    $destination = $destination_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $filename;
    }
    
    return false;
}

/**
 * Generate secure random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Validate email format
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Get trending hashtags
 */
function getTrendingHashtags($limit = 10) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT h.tag, COUNT(ph.post_id) as post_count
        FROM hashtags h
        JOIN post_hashtags ph ON h.id = ph.hashtag_id
        JOIN posts p ON ph.post_id = p.id
        WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY h.id, h.tag
        ORDER BY post_count DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * Search users
 */
function searchUsers($query, $limit = 20) {
    global $pdo;
    
    $search_term = '%' . $query . '%';
    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE username LIKE ? OR full_name LIKE ? 
        ORDER BY username
        LIMIT ?
    ");
    $stmt->execute([$search_term, $search_term, $limit]);
    return $stmt->fetchAll();
}

/**
 * Get user's recent activity
 */
function getUserActivity($user_id, $limit = 10) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        (SELECT 'post' as type, id, created_at, content as description FROM posts WHERE user_id = ?)
        UNION
        (SELECT 'like' as type, post_id as id, created_at, 'liked a post' as description FROM likes WHERE user_id = ?)
        UNION
        (SELECT 'comment' as type, post_id as id, created_at, comment as description FROM comments WHERE user_id = ?)
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $limit]);
    return $stmt->fetchAll();
}

/**
 * Clean old files (for maintenance)
 */
function cleanOldFiles($directory, $days = 30) {
    $files = glob($directory . '*');
    $cutoff = time() - ($days * 24 * 60 * 60);
    
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
}

/**
 * Log activity (optional)
 */
function logActivity($user_id, $action, $details = '') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action, $details]);
    } catch (Exception $e) {
        // Silently fail if activity_logs table doesn't exist
    }
}

// Global constants
define('SITE_NAME', 'MyRP');
define('SITE_URL', 'http://localhost/myrp_social/');
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// Error handling
function handleError($message, $redirect_url = null) {
    $_SESSION['error'] = $message;
    if ($redirect_url) {
        redirect($redirect_url);
    }
}

function handleSuccess($message, $redirect_url = null) {
    $_SESSION['success'] = $message;
    if ($redirect_url) {
        redirect($redirect_url);
    }
}

// Get and clear flash messages
function getFlashMessage($type) {
    if (isset($_SESSION[$type])) {
        $message = $_SESSION[$type];
        unset($_SESSION[$type]);
        return $message;
    }
    return null;
}

?>