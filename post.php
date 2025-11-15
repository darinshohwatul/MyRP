<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getCurrentUser();

// Get post ID
if (!isset($_GET['id'])) {
    redirect('feed.php');
}

$post_id = (int)$_GET['id'];

// Handle delete post
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_post'])) {
    $delete_post_id = (int)$_POST['post_id'];
    
    // Check if user owns the post
    $stmt = $pdo->prepare("SELECT user_id, image FROM posts WHERE id = ?");
    $stmt->execute([$delete_post_id]);
    $post = $stmt->fetch();
    
    if ($post && $post['user_id'] == $current_user['id']) {
        // Delete image file if exists
        if ($post['image'] && file_exists("uploads/posts/" . $post['image'])) {
            unlink("uploads/posts/" . $post['image']);
        }
        
        // Delete from database (cascade will handle likes, comments, hashtags)
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$delete_post_id]);
        
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// Handle edit post
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_post'])) {
    $edit_post_id = (int)$_POST['post_id'];
    $new_caption = sanitize($_POST['new_caption']);
    
    // Check if user owns the post
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
    $stmt->execute([$edit_post_id]);
    $post = $stmt->fetch();
    
    if ($post && $post['user_id'] == $current_user['id']) {
        // Update post caption
        $stmt = $pdo->prepare("UPDATE posts SET caption = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_caption, $edit_post_id]);
        
        // Delete old hashtag associations
        $stmt = $pdo->prepare("DELETE FROM post_hashtags WHERE post_id = ?");
        $stmt->execute([$edit_post_id]);
        
        // Extract and save new hashtags
        preg_match_all('/#([a-zA-Z0-9_]+)/', $new_caption, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $tag) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO hashtags (tag_name) VALUES (?)");
                $stmt->execute([strtolower($tag)]);
                
                $stmt = $pdo->prepare("SELECT id FROM hashtags WHERE tag_name = ?");
                $stmt->execute([strtolower($tag)]);
                $hashtag_id = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("INSERT INTO post_hashtags (post_id, hashtag_id) VALUES (?, ?)");
                $stmt->execute([$edit_post_id, $hashtag_id]);
            }
        }
        
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// Handle new comment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_comment'])) {
    $comment_text = sanitize($_POST['comment']);
    
    if (!empty($comment_text)) {
        $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, comment_text, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$post_id, $current_user['id'], $comment_text]);
    }
    
    redirect('post.php?id=' . $post_id);
}



// Handle delete comment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_comment'])) {
    $comment_id = (int)$_POST['comment_id'];
    
    // Check if user owns the comment or the post
    $stmt = $pdo->prepare("
        SELECT c.user_id as comment_user_id, p.user_id as post_user_id 
        FROM comments c 
        JOIN posts p ON c.post_id = p.id 
        WHERE c.id = ?
    ");
    $stmt->execute([$comment_id]);
    $result = $stmt->fetch();
    
    if ($result && ($result['comment_user_id'] == $current_user['id'] || $result['post_user_id'] == $current_user['id'])) {
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
        $stmt->execute([$comment_id]);
        
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// Handle like/unlike
if (isset($_POST['toggle_like'])) {
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
    }
    
    redirect('post.php?id=' . $post_id);
}

// Get post details
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.full_name, u.profile_picture,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_liked,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.id = ?
");
$stmt->execute([$current_user['id'], $post_id]);
$post = $stmt->fetch();

if (!$post) {
    redirect('feed.php');
}

// Get comments
$stmt = $pdo->prepare("
    SELECT c.*, u.username, u.full_name, u.profile_picture
    FROM comments c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.post_id = ? 
    ORDER BY c.created_at ASC
");
$stmt->execute([$post_id]);
$comments = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Post</title>
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
        
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .back-btn {
            background: white;
            color: #667eea;
            padding: 0.8rem 1.5rem;
            border: 2px solid #667eea;
            border-radius: 25px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
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
        
        .post {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            position: relative;
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
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .post-menu-btn:hover {
            background: rgba(0, 0, 0, 0.1);
            color: #333;
        }

        .post-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            min-width: 140px;
            z-index: 10;
            display: none;
            overflow: hidden;
        }

        .post-menu-dropdown.show {
            display: block;
        }

        .post-menu-item {
            width: 100%;
            padding: 12px 16px;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .post-menu-item:hover {
            background: #f8f9fa;
        }

        .post-menu-item.edit {
            color: #ffc107;
        }

        .post-menu-item.delete {
            color: #dc3545;
        }

        .post-menu-item i {
            width: 16px;
            font-size: 14px;
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

        .edit-form.show {
            display: block;
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
        
        /* Comments Section */
        .comments-section {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .comments-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .comments-header h3 {
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .comment-form {
            margin-bottom: 2rem;
        }
        
        .comment-form form {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }
        
        .comment-form .comment-input {
            flex: 1;
            padding: 0.8rem;
            border: 2px solid #ddd;
            border-radius: 10px;
            resize: vertical;
            min-height: 60px;
            font-family: inherit;
        }
        
        .comment-form .comment-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .comment {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .comment-user-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        
        .comment-content {
            flex: 1;
        }
        
        .comment-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .comment-header .full-name {
            font-weight: bold;
            color: #333;
        }
        
        .comment-header .username {
            color: #666;
            font-size: 0.9rem;
        }
        
        .comment-header .time {
            color: #999;
            font-size: 0.8rem;
            margin-left: auto;
        }
        
        .comment-text {
            color: #333;
            line-height: 1.5;
        }
        
        .comment-menu {
            position: absolute;
            top: 0;
            right: 0;
        }

        .comment-menu-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 50%;
            transition: all 0.3s ease;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        .comment-menu-btn:hover {
            background: rgba(0, 0, 0, 0.1);
            color: #333;
        }

        .comment-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            min-width: 120px;
            z-index: 10;
            display: none;
            overflow: hidden;
        }

        .comment-menu-dropdown.show {
            display: block;
        }

        .comment-menu-item {
            width: 100%;
            padding: 8px 12px;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 500;
        }

        .comment-menu-item:hover {
            background: #f8f9fa;
        }

        .comment-menu-item.delete {
            color: #dc3545;
        }

        .comment-menu-item i {
            width: 12px;
            font-size: 12px;
        }
        
        .no-comments {
            text-align: center;
            color: #666;
            padding: 2rem;
        }
        
        .no-comments i {
            font-size: 2rem;
            color: #ccc;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
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
            
            .comment-form form {
                flex-direction: column;
            }
            
            .comment-form .comment-input {
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo">MyRP</div>
            <div class="nav-links">
                <a href="feed.php">Feed</a>
                <a href="explore.php">Explore</a>
                <a href="messages.php">Messages</a>
                <a href="search.php">Search</a>
            </div>
            <div class="user-info">
                <img src="<?php echo $current_user['profile_picture'] ? 'uploads/profiles/' . $current_user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                     alt="Profile" class="profile-pic">
                <a href="profile.php?user=<?php echo $current_user['username']; ?>">
                    <?php echo htmlspecialchars($current_user['full_name']); ?>
                </a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <a href="feed.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Back to Feed
        </a>

        <!-- Post Detail -->
        <div class="post" id="post-<?php echo $post['id']; ?>">
            <!-- Post Menu (only for own posts) -->
            <?php if ($post['user_id'] == $current_user['id']): ?>
                <div class="post-menu">
                    <button class="post-menu-btn" onclick="togglePostMenu(<?php echo $post['id']; ?>)">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="post-menu-dropdown" id="menu-<?php echo $post['id']; ?>">
                        <div class="post-menu-item edit" onclick="showEditForm(<?php echo $post['id']; ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </div>
                        <div class="post-menu-item delete" onclick="confirmDelete(<?php echo $post['id']; ?>)">
                            <i class="fas fa-trash"></i> Delete
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
            
            <!-- Post content (caption first, then image) -->
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
            <div class="edit-form" id="edit-form-<?php echo $post['id']; ?>">
                <textarea id="edit-caption-<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['caption']); ?></textarea>
                <div class="form-actions">
                    <button onclick="cancelEdit(<?php echo $post['id']; ?>)" class="btn btn-outline btn-small">Cancel</button>
                    <button onclick="saveEdit(<?php echo $post['id']; ?>)" class="btn btn-small">
                        <i class="fas fa-save"></i>
                        Save Changes
                    </button>
                </div>
            </div>
            
            <div class="post-actions">
                <button type="button" onclick="toggleLike(<?php echo $post['id']; ?>, this)" 
                        class="like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>">
                    <i class="<?php echo $post['user_liked'] ? 'fas' : 'far'; ?> fa-heart"></i>
                    <span class="like-count"><?php echo $post['like_count']; ?></span>
                </button>
                <div class="comment-link" style="color: #667eea;">
                    <i class="far fa-comment"></i>
                    <span><?php echo $post['comment_count']; ?> Comments</span>
                </div>
            </div>
        </div>

        <!-- Comments Section -->
        <div class="comments-section">
            <div class="comments-header">
                <h3>
                    <i class="far fa-comments"></i>
                    Comments (<?php echo count($comments); ?>)
                </h3>
            </div>

            <!-- Add Comment Form -->
            <div class="comment-form">
                <form method="POST">
                    <textarea name="comment" class="comment-input" placeholder="Write a comment..." required></textarea>
                    <button type="submit" name="add_comment" class="btn">
                        <i class="fas fa-paper-plane"></i>
                        Post
                    </button>
                </form>
            </div>

            <!-- Comments List -->
            <?php if (empty($comments)): ?>
                <div class="no-comments">
                    <i class="far fa-comment"></i>
                    <h4>No comments yet</h4>
                    <p>Be the first to comment on this post!</p>
                </div>
            <?php else: ?>
                <?php foreach ($comments as $comment): ?>
                    <div class="comment" id="comment-<?php echo $comment['id']; ?>">
                        <!-- Comment Menu (for comment owner or post owner) -->
                        <?php if ($comment['user_id'] == $current_user['id'] || $post['user_id'] == $current_user['id']): ?>
                            <div class="comment-menu">
                                <button class="comment-menu-btn" onclick="toggleCommentMenu(<?php echo $comment['id']; ?>)">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="comment-menu-dropdown" id="comment-menu-<?php echo $comment['id']; ?>">
                                    <div class="comment-menu-item delete" onclick="confirmDeleteComment(<?php echo $comment['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <img src="<?php echo $comment['profile_picture'] ? 'uploads/profiles/' . $comment['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                             alt="Profile" class="comment-user-pic">
                        <div class="comment-content">
                            <div class="comment-header">
                                <span class="full-name">
                                    <a href="profile.php?user=<?php echo $comment['username']; ?>" style="text-decoration: none; color: inherit;">
                                        <?php echo htmlspecialchars($comment['full_name']); ?>
                                    </a>
                                </span>
                                <span class="username">@<?php echo htmlspecialchars($comment['username']); ?></span>
                                <span class="time"><?php echo timeAgo($comment['created_at']); ?></span>
                            </div>
                            <div class="comment-text">
                                <?php 
                                $comment_text = htmlspecialchars($comment['comment_text']);
                                $comment_text = preg_replace('/#([a-zA-Z0-9_]+)/', '<a href="hashtag.php?tag=$1" class="hashtag">#$1</a>', $comment_text);
                                echo nl2br($comment_text);
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Post Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Delete Post</h3>
            <p>Are you sure you want to delete this post? This action cannot be undone.</p>
            <div class="modal-actions">
                <button onclick="closeDeleteModal()" class="btn btn-outline">Cancel</button>
                <button onclick="executeDelete()" class="btn btn-danger">Delete</button>
            </div>
        </div>
    </div>

    <!-- Delete Comment Modal -->
    <div id="deleteCommentModal" class="modal">
        <div class="modal-content">
            <h3>Delete Comment</h3>
            <p>Are you sure you want to delete this comment? This action cannot be undone.</p>
            <div class="modal-actions">
                <button onclick="closeDeleteCommentModal()" class="btn btn-outline">Cancel</button>
                <button onclick="executeDeleteComment()" class="btn btn-danger">Delete</button>
            </div>
        </div>
    </div>

    <script>
        let deletePostId = null;
        let deleteCommentId = null;

        // Toggle Post Menu
        function togglePostMenu(postId) {
            const menu = document.getElementById('menu-' + postId);
            menu.classList.toggle('show');
            
            // Close other menus
            document.querySelectorAll('.post-menu-dropdown').forEach(dropdown => {
                if (dropdown.id !== 'menu-' + postId) {
                    dropdown.classList.remove('show');
                }
            });
        }

        // Toggle Comment Menu
        function toggleCommentMenu(commentId) {
            const menu = document.getElementById('comment-menu-' + commentId);
            menu.classList.toggle('show');
            
            // Close other menus
            document.querySelectorAll('.comment-menu-dropdown').forEach(dropdown => {
                if (dropdown.id !== 'comment-menu-' + commentId) {
                    dropdown.classList.remove('show');
                }
            });
        }

        // Show Edit Form
        function showEditForm(postId) {
            const content = document.getElementById('content-' + postId);
            const editForm = document.getElementById('edit-form-' + postId);
            const menu = document.getElementById('menu-' + postId);
            
            content.style.display = 'none';
            editForm.classList.add('show');
            menu.classList.remove('show');
        }

        // Cancel Edit
        function cancelEdit(postId) {
            const content = document.getElementById('content-' + postId);
            const editForm = document.getElementById('edit-form-' + postId);
            
            content.style.display = 'block';
            editForm.classList.remove('show');
        }

        // Save Edit
        function saveEdit(postId) {
            const newCaption = document.getElementById('edit-caption-' + postId).value;
            
            const formData = new FormData();
            formData.append('edit_post', '1');
            formData.append('post_id', postId);
            formData.append('new_caption', newCaption);
            
            fetch('post.php?id=<?php echo $post_id; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error updating post: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating post');
            });
        }

        // Confirm Delete Post
        function confirmDelete(postId) {
            deletePostId = postId;
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('menu-' + postId).classList.remove('show');
        }

        // Close Delete Modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            deletePostId = null;
        }

        // Execute Delete Post
        function executeDelete() {
            if (deletePostId) {
                const formData = new FormData();
                formData.append('delete_post', '1');
                formData.append('post_id', deletePostId);
                
                fetch('post.php?id=<?php echo $post_id; ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'feed.php';
                    } else {
                        alert('Error deleting post: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting post');
                });
            }
            closeDeleteModal();
        }

        // Confirm Delete Comment
        function confirmDeleteComment(commentId) {
            deleteCommentId = commentId;
            document.getElementById('deleteCommentModal').style.display = 'block';
            document.getElementById('comment-menu-' + commentId).classList.remove('show');
        }

        // Close Delete Comment Modal
        function closeDeleteCommentModal() {
            document.getElementById('deleteCommentModal').style.display = 'none';
            deleteCommentId = null;
        }

        // Execute Delete Comment
        function executeDeleteComment() {
            if (deleteCommentId) {
                const formData = new FormData();
                formData.append('delete_comment', '1');
                formData.append('comment_id', deleteCommentId);
                
                fetch('post.php?id=<?php echo $post_id; ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('comment-' + deleteCommentId).remove();
                        // Update comment count
                        location.reload();
                    } else {
                        alert('Error deleting comment: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting comment');
                });
            }
            closeDeleteCommentModal();
        }

        // Toggle Like
        function toggleLike(postId, button) {
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('toggle_like', '1');
            
            fetch('post.php?id=<?php echo $post_id; ?>', {
                method: 'POST',
                body: formData
            })
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                button.disabled = false;
            });
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.post-menu') && !event.target.closest('.comment-menu')) {
                document.querySelectorAll('.post-menu-dropdown, .comment-menu-dropdown').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
            }
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            const deleteModal = document.getElementById('deleteModal');
            const deleteCommentModal = document.getElementById('deleteCommentModal');
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
            if (event.target == deleteCommentModal) {
                closeDeleteCommentModal();
            }
        }

        // Auto-resize textarea
        document.addEventListener('DOMContentLoaded', function() {
            const textareas = document.querySelectorAll('textarea');
            textareas.forEach(textarea => {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = this.scrollHeight + 'px';
                });
            });
        });
    </script>
</body>
</html>