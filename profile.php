<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getCurrentUser();

// Get username from URL parameter
$username = isset($_GET['user']) ? sanitize($_GET['user']) : $current_user['username'];

// Get profile user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$profile_user = $stmt->fetch();

if (!$profile_user) {
    redirect('feed.php');
}

$is_own_profile = ($profile_user['id'] == $current_user['id']);

// Handle delete post
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_post']) && $is_own_profile) {
    $post_id = (int)$_POST['post_id'];
    
    // Verify post belongs to current user
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ? AND user_id = ?");
    $stmt->execute([$post_id, $current_user['id']]);
    $post = $stmt->fetch();
    
    if ($post) {
        // Delete associated files if exists
        if ($post['image'] && file_exists('uploads/posts/' . $post['image'])) {
            unlink('uploads/posts/' . $post['image']);
        }
        
        // Delete from database (foreign key constraints will handle likes, comments, etc.)
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
        $stmt->execute([$post_id, $current_user['id']]);
        
        $_SESSION['success'] = 'Post deleted successfully!';
    } else {
        $_SESSION['error'] = 'Post not found or you do not have permission to delete it.';
    }
    
    redirect('profile.php?user=' . $username);
}

// Handle edit post
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_post']) && $is_own_profile) {
    $post_id = (int)$_POST['post_id'];
    $new_caption = trim($_POST['caption']);
    
    // Verify post belongs to current user
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND user_id = ?");
    $stmt->execute([$post_id, $current_user['id']]);
    
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE posts SET caption = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$new_caption, $post_id, $current_user['id']]);
        
        $_SESSION['success'] = 'Post updated successfully!';
    } else {
        $_SESSION['error'] = 'Post not found or you do not have permission to edit it.';
    }
    
    redirect('profile.php?user=' . $username);
}

// Handle follow/unfollow
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_follow']) && !$is_own_profile) {
    $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$current_user['id'], $profile_user['id']]);
    
    if ($stmt->fetch()) {
        // Unfollow
        $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$current_user['id'], $profile_user['id']]);
    } else {
        // Follow
        $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$current_user['id'], $profile_user['id']]);
        
        // Create notification
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, message, created_at) VALUES (?, 'follow', ?, 'started following you', NOW())");
        $stmt->execute([$profile_user['id'], $current_user['id']]);
    }
    
    redirect('profile.php?user=' . $username);
}

// Handle like/unlike (same as feed.php)
if (isset($_POST['toggle_like'])) {
    $post_id = (int)$_POST['post_id'];
    
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$current_user['id'], $post_id]);
    
    if ($stmt->fetch()) {
        // Unlike
        $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
        $stmt->execute([$current_user['id'], $post_id]);
    } else {
        // Like
        $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$current_user['id'], $post_id]);
        
        // Create notification if not own post
        if ($profile_user['id'] != $current_user['id']) {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, post_id, message, created_at) VALUES (?, 'like', ?, ?, 'liked your post', NOW())");
            $stmt->execute([$profile_user['id'], $current_user['id'], $post_id]);
        }
    }
    
    redirect('profile.php?user=' . $username);
}

// Check if current user follows this profile
$is_following = false;
if (!$is_own_profile) {
    $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$current_user['id'], $profile_user['id']]);
    $is_following = (bool)$stmt->fetch();
}

// Get follower count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
$stmt->execute([$profile_user['id']]);
$follower_count = $stmt->fetchColumn();

// Get following count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
$stmt->execute([$profile_user['id']]);
$following_count = $stmt->fetchColumn();

// Get post count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ?");
$stmt->execute([$profile_user['id']]);
$post_count = $stmt->fetchColumn();

// Get user's posts
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.full_name, u.profile_picture,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_liked,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
    LIMIT 20
");
$stmt->execute([$current_user['id'], $profile_user['id']]);
$posts = $stmt->fetchAll();

include 'navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - <?php echo htmlspecialchars($profile_user['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .nav-links a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .nav-links a:hover {
            color: #667eea;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #667eea;
        }

        /*menambahkan style ke link username*/
        .username-link {
        text-decoration: none;
        color: #667eea; 
        font-weight: bold; 
        font-family: inherit; /* Matches parent font */
        }

        /* Optional hover effect */
        .username-link:hover {
            color:rgb(46, 62, 133); /* Slightly darker on hover */
        }
        
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .profile-header {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .profile-info {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #667eea;
        }
        
        .profile-details h1 {
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }
        
        .profile-details .username {
            color: #666;
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }
        
        .profile-stats {
            display: flex;
            gap: 2rem;
            margin-bottom: 1rem;
        }
        
        .stat {
            text-align: center;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: background-color 0.3s;
        }
        
        .stat:hover {
            background-color: rgba(102, 126, 234, 0.1);
        }
        
        .stat a {
            text-decoration: none;
            color: inherit;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .profile-bio {
            color: #333;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        
        .profile-actions {
            display: flex;
            gap: 1rem;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            transition: transform 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #64b5f6 0%, #42a5f5 100%);
        }
        
        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 15px;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffa726 0%, #ff9800 100%);
        }
        
        .online-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .status-online {
            background: #4caf50;
        }
        
        .status-offline {
            background: #ccc;
        }
        
        .posts-section {
            margin-top: 2rem;
        }
        
        .section-title {
            background: white;
            padding: 1rem 2rem;
            border-radius: 15px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .section-title h3 {
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .post {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .post-menu {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }

        .post-menu-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .post-menu-btn:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .post-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            display: none;
            min-width: 120px;
            z-index: 10;
        }

        .post-menu-dropdown.show {
            display: block;
        }

        .post-menu-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .post-menu-item:hover {
            background: #f8f9fa;
        }

        .post-menu-item:first-child {
            border-radius: 8px 8px 0 0;
        }

        .post-menu-item:last-child {
            border-radius: 0 0 8px 8px;
        }

        .post-menu-item.delete {
            color: #dc3545;
        }

        .post-menu-item.edit {
            color: #ffc107;
        }
        
        .post-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .post-user-pic {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .post-user-info h4 {
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .post-user-info .username {
            color: #666;
            font-size: 0.9rem;
        }
        
        .post-content {
            margin-bottom: 1rem;
            line-height: 1.6;
            color: #333;
        }
        
        .post-image {
            width: 100%;
            max-height: 400px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        
        .post-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }
        
        .like-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            padding: 0.5rem;
            border-radius: 5px;
        }
        
        .like-btn:hover {
            color: #e91e63;
            background: rgba(233, 30, 99, 0.1);
        }
        
        .like-btn.liked {
            color: #e91e63 !important;
        }
        
        .like-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .hashtag {
            color: #667eea;
            text-decoration: none;
            font-weight: bold;
        }
        
        .hashtag:hover {
            text-decoration: underline;
        }
        
        .post-meta {
            color: #999;
            font-size: 0.85rem;
        }
        
        .comment-link {
            color: #666;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .comment-link:hover {
            color: #667eea;
            background: rgba(102, 126, 234, 0.1);
        }
        
        .no-posts {
            background: white;
            padding: 3rem 2rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .no-posts i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 1rem;
        }
        
        .no-posts h3 {
            color: #666;
            margin-bottom: 0.5rem;
        }
        
        .no-posts p {
            color: #999;
        }

        /* Edit Form Styles */
        .edit-form {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .edit-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-family: inherit;
            font-size: 0.9rem;
            resize: vertical;
            margin-bottom: 1rem;
        }

        .edit-form textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .edit-form .form-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 2rem;
            border-radius: 15px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .modal-content h3 {
            margin-bottom: 1rem;
            color: #333;
        }

        .modal-content p {
            margin-bottom: 2rem;
            color: #666;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        @media (max-width: 768px) {
            .profile-info {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-picture {
                width: 100px;
                height: 100px;
            }
            
            .profile-stats {
                justify-content: center;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .nav-links {
                gap: 1rem;
            }

            .post-menu {
                position: static;
                text-align: right;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-info">
                <img src="<?php echo $profile_user['profile_picture'] ? 'uploads/profiles/' . $profile_user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                     alt="Profile Picture" class="profile-picture">
                <div class="profile-details">
                    <h1><?php echo htmlspecialchars($profile_user['full_name']); ?></h1>
                    <div class="username">@<?php echo htmlspecialchars($profile_user['username']); ?></div>
                    
                    <div class="profile-stats">
                        <div class="stat">
                            <div class="stat-number"><?php echo $post_count; ?></div>
                            <div class="stat-label">Posts</div>
                        </div>
                        <div class="stat">
                            <a href="followers.php?user=<?php echo $profile_user['username']; ?>">
                                <div class="stat-number"><?php echo $follower_count; ?></div>
                                <div class="stat-label">Followers</div>
                            </a>
                        </div>
                        <div class="stat">
                            <a href="following.php?user=<?php echo $profile_user['username']; ?>">
                                <div class="stat-number"><?php echo $following_count; ?></div>
                                <div class="stat-label">Following</div>
                            </a>
                        </div>
                    </div>
                    
                    <?php if ($profile_user['bio']): ?>
                        <div class="profile-bio">
                            <?php echo nl2br(htmlspecialchars($profile_user['bio'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="online-status">
                        <span class="status-dot <?php echo $profile_user['is_online'] ? 'status-online' : 'status-offline'; ?>"></span>
                        <?php if ($profile_user['is_online']): ?>
                            Online
                        <?php else: ?>
                            Last seen <?php echo $profile_user['last_seen'] ? timeAgo($profile_user['last_seen']) : 'Never'; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="profile-actions">
                <?php if ($is_own_profile): ?>
                    <a href="edit-profile.php" class="btn">
                        <i class="fas fa-edit"></i>
                        Edit Profile
                    </a>
                    <a href="logout.php" class="btn btn-outline">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                <?php else: ?>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="toggle_follow" class="btn <?php echo $is_following ? 'btn-outline' : ''; ?>">
                            <i class="fas <?php echo $is_following ? 'fa-user-minus' : 'fa-user-plus'; ?>"></i>
                            <?php echo $is_following ? 'Unfollow' : 'Follow'; ?>
                        </button>
                    </form>
                    <a href="messages.php?user=<?php echo $profile_user['username']; ?>" class="btn btn-secondary">
                        <i class="fas fa-comment"></i>
                        Message
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Posts Section -->
        <div class="posts-section">
            <div class="section-title">
                <h3>
                    <i class="fas fa-images"></i>
                    Posts (<?php echo $post_count; ?>)
                </h3>
            </div>
            
            <?php if (empty($posts)): ?>
                <div class="no-posts">
                    <i class="fas fa-camera"></i>
                    <h3>No posts yet</h3>
                    <p>
                        <?php if ($is_own_profile): ?>
                            Share your first moment with MyRP!
                        <?php else: ?>
                            <?php echo htmlspecialchars($profile_user['full_name']); ?> hasn't shared anything yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <div class="post">
                        <!-- Post Menu (only for own posts) -->
                        <?php if ($is_own_profile): ?>
                            <div class="post-menu">
                                <button class="post-menu-btn" onclick="togglePostMenu(<?php echo $post['id']; ?>)">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="post-menu-dropdown" id="menu-<?php echo $post['id']; ?>">
                                    <div class="post-menu-item edit" onclick="showEditForm(<?php echo $post['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </div>
                                    <div class="post-menu-item delete" onclick="confirmDelete(<?php echo $post['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                        Delete
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="post-header">
                            <img src="<?php echo $post['profile_picture'] ? 'uploads/profiles/' . $post['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                                 alt="Profile" class="post-user-pic">
                            <div class="post-user-info">
                                <h4>
                                    <a href="profile.php?user=<?php echo $post['username']; ?>" style="text-decoration: none; color: inherit;">
                                        <?php echo htmlspecialchars($post['full_name']); ?>
                                    </a>
                                </h4>
                                <div class="username">@<?php echo htmlspecialchars($post['username']); ?></div>
                            </div>
                            <div class="post-meta" style="margin-left: auto;">
                                <?php echo timeAgo($post['created_at']); ?>
                            </div>
                        </div>
                        
                    <?php if (!empty($post['caption'])): ?>
                        <div class="post-content" id="content-<?php echo $post['id']; ?>">
                            <?php 
                            $caption = htmlspecialchars($post['caption']);
                            $caption = preg_replace('/#([a-zA-Z0-9_]+)/', '<a href="hashtag.php?tag=$1" class="hashtag">#$1</a>', $caption);
                            echo nl2br($caption);
                            ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($post['image']): ?>
                        <img src="uploads/posts/<?php echo $post['image']; ?>" alt="Post image" class="post-image">
                    <?php endif; ?>

                        <!-- Edit Form (hidden by default) -->
                        <?php if ($is_own_profile): ?>
                            <div class="edit-form" id="edit-form-<?php echo $post['id']; ?>">
                                <form method="POST">
                                    <textarea name="caption" placeholder="Edit your caption..." required><?php echo htmlspecialchars($post['caption']); ?></textarea>
                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                    <div class="form-actions">
                                        <button type="button" onclick="cancelEdit(<?php echo $post['id']; ?>)" class="btn btn-small btn-outline">Cancel</button>
                                        <button type="submit" name="edit_post" class="btn btn-small btn-warning">
                                            <i class="fas fa-save"></i>
                                            Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                        
                        <div class="post-actions">
                            <button type="button" onclick="toggleLike(<?php echo $post['id']; ?>, this)" 
                                    class="like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>">
                                <i class="<?php echo $post['user_liked'] ? 'fas' : 'far'; ?> fa-heart"></i>
                                <span class="like-count"><?php echo $post['like_count']; ?></span>
                            </button>
                            <a href="post.php?id=<?php echo $post['id']; ?>" class="comment-link">
                                <i class="far fa-comment"></i>
                                <span><?php echo $post['comment_count']; ?> Comments</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Delete Post</h3>
            <p>Are you sure you want to delete this post? This action cannot be undone.</p>
            <div class="modal-actions">
                <button onclick="closeDeleteModal()" class="btn btn-outline">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="post_id" id="deletePostId">
                    <button type="submit" name="delete_post" class="btn btn-danger">
                        <i class="fas fa-trash"></i>
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Function untuk handle like/unlike dengan AJAX
    function toggleLike(postId, button) {
        // Get current state
        const isLiked = button.classList.contains('liked');
        const likeCountElement = button.querySelector('.like-count');
        const heartIcon = button.querySelector('i');
        let currentCount = parseInt(likeCountElement.textContent);
        
        // Update UI immediately (optimistic update)
        if (isLiked) {
            button.classList.remove('liked');
            heartIcon.classList.remove('fas');
            heartIcon.classList.add('far');
            likeCountElement.textContent = currentCount - 1;
        } else {
            button.classList.add('liked');
            heartIcon.classList.remove('far');
            heartIcon.classList.add('fas');
            likeCountElement.textContent = currentCount + 1;
        }
        
        // Disable button temporarily
        button.disabled = true;
        
        // Send AJAX request
        fetch('like.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'post_id=' + postId + '&action=' + (isLiked ? 'unlike' : 'like')
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update with actual count from server
                if (data.liked) {
                    button.classList.add('liked');
                    heartIcon.classList.remove('far');
                    heartIcon.classList.add('fas');
                } else {
                    button.classList.remove('liked');
                    heartIcon.classList.remove('fas');
                    heartIcon.classList.add('far');
                }
                likeCountElement.textContent = data.like_count;
            } else {
                // Revert UI if request failed
                if (isLiked) {
                    button.classList.add('liked');
                    heartIcon.classList.remove('far');
                    heartIcon.classList.add('fas');
                    likeCountElement.textContent = currentCount;
                } else {
                    button.classList.remove('liked');
                    heartIcon.classList.remove('fas');
                    heartIcon.classList.add('far');
                    likeCountElement.textContent = currentCount;
                }
                alert('Error: ' + (data.message || 'Failed to update like'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Revert UI on error
            if (isLiked) {
                button.classList.add('liked');
                heartIcon.classList.remove('far');
                heartIcon.classList.add('fas');
                likeCountElement.textContent = currentCount;
            } else {
                button.classList.remove('liked');
                heartIcon.classList.remove('fas');
                heartIcon.classList.add('far');
                likeCountElement.textContent = currentCount;
            }
            alert('Network error occurred');
        })
        .finally(() => {
            // Re-enable button
            button.disabled = false;
        });
    }

    // Post menu functions
    function togglePostMenu(postId) {
        const menu = document.getElementById('menu-' + postId);
        const allMenus = document.querySelectorAll('.post-menu-dropdown');
        
        // Close all other menus
        allMenus.forEach(m => {
            if (m !== menu) {
                m.classList.remove('show');
            }
        });
        
        // Toggle current menu
        menu.classList.toggle('show');
    }

    // Close menus when clicking outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.post-menu')) {
            document.querySelectorAll('.post-menu-dropdown').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });

    // Edit functions
    function showEditForm(postId) {
        const content = document.getElementById('content-' + postId);
        const editForm = document.getElementById('edit-form-' + postId);
        const menu = document.getElementById('menu-' + postId);
        
        content.style.display = 'none';
        editForm.style.display = 'block';
        menu.classList.remove('show');
        
        // Focus on textarea
        const textarea = editForm.querySelector('textarea');
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    }

    function cancelEdit(postId) {
        const content = document.getElementById('content-' + postId);
        const editForm = document.getElementById('edit-form-' + postId);
        
        content.style.display = 'block';
        editForm.style.display = 'none';
        
        // Reset textarea to original value
        const textarea = editForm.querySelector('textarea');
        textarea.value = textarea.defaultValue;
    }

    // Delete functions
    function confirmDelete(postId) {
        const menu = document.getElementById('menu-' + postId);
        menu.classList.remove('show');
        
        document.getElementById('deletePostId').value = postId;
        document.getElementById('deleteModal').style.display = 'block';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('deleteModal');
        if (event.target === modal) {
            closeDeleteModal();
        }
    }

    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 5000);
        });
    });
    </script>
</body>
</html>