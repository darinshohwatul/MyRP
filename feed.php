<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getCurrentUser();

// Handle delete post
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_post'])) {
    $post_id = (int)$_POST['post_id'];
    
    // Check if user owns the post
    $stmt = $pdo->prepare("SELECT user_id, image FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    
    if ($post && $post['user_id'] == $current_user['id']) {
        // Delete image file if exists
        if ($post['image'] && file_exists("uploads/posts/" . $post['image'])) {
            unlink("uploads/posts/" . $post['image']);
        }
        
        // Delete from database (cascade will handle likes, comments, hashtags)
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// Handle edit post
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_post'])) {
    $post_id = (int)$_POST['post_id'];
    $new_caption = sanitize($_POST['new_caption']);
    
    // Check if user owns the post
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    
    if ($post && $post['user_id'] == $current_user['id']) {
        // Update post caption
        $stmt = $pdo->prepare("UPDATE posts SET caption = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_caption, $post_id]);
        
        // Delete old hashtag associations
        $stmt = $pdo->prepare("DELETE FROM post_hashtags WHERE post_id = ?");
        $stmt->execute([$post_id]);
        
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
                $stmt->execute([$post_id, $hashtag_id]);
            }
        }
        
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// Handle new post
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_post'])) {
    $caption = sanitize($_POST['caption']);
    $image = '';
    
    // Handle image upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $target_dir = "uploads/posts/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image = uniqid() . '.' . $file_extension;
        $target_file = $target_dir . $image;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            // Image uploaded successfully
        } else {
            $image = '';
        }
    }
    
    // Insert post (caption can be empty)
    $stmt = $pdo->prepare("INSERT INTO posts (user_id, caption, image, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$current_user['id'], $caption, $image]);
    
    // Extract and save hashtags if caption exists
    if (!empty($caption)) {
        preg_match_all('/#([a-zA-Z0-9_]+)/', $caption, $matches);
        if (!empty($matches[1])) {
            $post_id = $pdo->lastInsertId();
            foreach ($matches[1] as $tag) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO hashtags (tag_name) VALUES (?)");
                $stmt->execute([strtolower($tag)]);
                
                $stmt = $pdo->prepare("SELECT id FROM hashtags WHERE tag_name = ?");
                $stmt->execute([strtolower($tag)]);
                $hashtag_id = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("INSERT INTO post_hashtags (post_id, hashtag_id) VALUES (?, ?)");
                $stmt->execute([$post_id, $hashtag_id]);
            }
        }
    }
    
    redirect('feed.php');
}

// Handle like/unlike
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
    }
    
    redirect('feed.php');
}

// Get posts from followed users and own posts
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.full_name, u.profile_picture,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_liked,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.user_id IN (
        SELECT following_id FROM follows WHERE follower_id = ?
        UNION
        SELECT ?
    )
    ORDER BY p.created_at DESC
    LIMIT 20
");
$stmt->execute([$current_user['id'], $current_user['id'], $current_user['id']]);
$posts = $stmt->fetchAll();

include 'navbar.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Feed</title>
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
            overflow-x: hidden;
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
        
        .post-form {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .post-form h3 {
            margin-bottom: 1rem;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 10px;
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }
        
        .form-group input[type="file"] {
            width: 100%;
            padding: 0.5rem;
            border: 2px dashed #ddd;
            border-radius: 10px;
            background: #f9f9f9;
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

        /* Tambahan untuk navbar mobile toggle */
        .menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
        }

        .menu-toggle span {
            height: 3px;
            width: 25px;
            background: #667eea;
            margin: 4px 0;
            transition: all 0.3s;
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
                padding: 0 1rem;
            }

            .main-content,
            .sidebar {
                max-width: 100%;
            }

            .search-form {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .search-btn, .search-input {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }

            .post-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .like-btn, .comment-link {
                font-size: 0.85rem;
            }
        }


        @media (max-width: 768px) {

            .nav-links {
                display: none;
                flex-direction: column;
                background: white;
                position: absolute;
                top: 100%;
                left: 0;
                width: 100%;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                padding: 1rem;
            }

            .nav-links.active {
                display: flex;
            }

            .menu-toggle {
                display: flex;
            }

            .nav-container {
                position: relative;
            }

            .user-info {
                display: flex;
                align-items: center;
                gap: 1rem;
                margin-top: 1rem;
            }

        }
        
        @media (max-width: 768px) {
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
        <!-- Post Creation Form -->
        <div class="post-form">
            <h3>Share something with MyRP!</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <textarea name="caption" placeholder="What's on your mind? Use #hashtags to categorize your post!"></textarea>
                </div>
                <div class="form-group">
                    <input type="file" name="image" accept="image/*" required>
                </div>
                <button type="submit" name="create_post" class="btn">
                    <i class="fas fa-plus"></i>
                    Create Post
                </button>
            </form>
        </div>

        <!-- Posts Feed -->
        <?php if (empty($posts)): ?>
            <div class="no-posts">
                <i class="fas fa-image"></i>
                <h3>No posts yet!</h3>
                <p>Start following people or create your first post.</p>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
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
                        <a href="post.php?id=<?php echo $post['id']; ?>" class="comment-link">
                            <i class="far fa-comment"></i>
                            <span><?php echo $post['comment_count']; ?> Comments</span>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this post? This action cannot be undone.</p>
            <div class="modal-actions">
                <button onclick="closeDeleteModal()" class="btn btn-outline">Cancel</button>
                <button onclick="deletePost()" class="btn btn-danger">Delete</button>
            </div>
        </div>
    </div>

    <script>
        let postToDelete = null;

        //Responsive navbar
        function toggleMenu() {
        document.querySelector('.nav-links').classList.toggle('active');
        }
        
        // Toggle post menu
        function togglePostMenu(postId) {
            const menu = document.getElementById('menu-' + postId);
            const allMenus = document.querySelectorAll('.post-menu-dropdown');
            
            // Close all other menus
            allMenus.forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
            
            menu.classList.toggle('show');
        }
        
        // Close post menus when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.post-menu')) {
                document.querySelectorAll('.post-menu-dropdown').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });
        
        // Show edit form
        function showEditForm(postId) {
            const content = document.getElementById('content-' + postId);
            const editForm = document.getElementById('edit-form-' + postId);
            const menu = document.getElementById('menu-' + postId);
            
            if (content) content.style.display = 'none';
            editForm.classList.add('show');
            menu.classList.remove('show');
        }
        
        // Cancel edit
        function cancelEdit(postId) {
            const content = document.getElementById('content-' + postId);
            const editForm = document.getElementById('edit-form-' + postId);
            
            if (content) content.style.display = 'block';
            editForm.classList.remove('show');
        }
        
        // Save edit
        function saveEdit(postId) {
            const textarea = document.getElementById('edit-caption-' + postId);
            const newCaption = textarea.value.trim();
            
            // Send AJAX request
            fetch('feed.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'edit_post=1&post_id=' + postId + '&new_caption=' + encodeURIComponent(newCaption)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the content and show it
                    const content = document.getElementById('content-' + postId);
                    const editForm = document.getElementById('edit-form-' + postId);
                    
                    if (newCaption) {
                        // Process hashtags in the new caption
                        let processedCaption = newCaption
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                        
                        processedCaption = processedCaption.replace(/#([a-zA-Z0-9_]+)/g, '<a href="hashtag.php?tag=$1" class="hashtag">#$1</a>');
                        processedCaption = processedCaption.replace(/\n/g, '<br>');
                        
                        if (content) {
                            content.innerHTML = processedCaption;
                            content.style.display = 'block';
                        }
                    } else {
                        // Hide content if caption is empty
                        if (content) content.style.display = 'none';
                    }
                    
                    editForm.classList.remove('show');
                    alert('Post updated successfully!');
                } else {
                    alert('Error: ' + (data.message || 'Failed to update post'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred');
            });
        }
        
        // Confirm delete
        function confirmDelete(postId) {
            postToDelete = postId;
            document.getElementById('deleteModal').style.display = 'block';
            
            // Close options menu
            const optionsMenu = document.getElementById('options-' + postId);
            optionsMenu.classList.remove('show');
        }
        
        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            postToDelete = null;
        }
        
        // Delete post
        function deletePost() {
            if (!postToDelete) return;
            
            fetch('feed.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'delete_post=1&post_id=' + postToDelete
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove post from DOM
                    const postElement = document.getElementById('post-' + postToDelete);
                    postElement.remove();
                    closeDeleteModal();
                    alert('Post deleted successfully!');
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete post'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred');
            });
        }
        
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
    </script>
</body>
</html>