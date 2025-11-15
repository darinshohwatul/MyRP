<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getCurrentUser();

// Get hashtag from URL
if (!isset($_GET['tag'])) {
    redirect('feed.php');
}

$hashtag = strtolower(trim($_GET['tag']));

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
    
    redirect('hashtag.php?tag=' . urlencode($hashtag));
}

// Get hashtag info and post count
$stmt = $pdo->prepare("
    SELECT h.*, COUNT(ph.post_id) as post_count
    FROM hashtags h
    LEFT JOIN post_hashtags ph ON h.id = ph.hashtag_id
    WHERE h.tag_name = ?
    GROUP BY h.id
");
$stmt->execute([$hashtag]);
$hashtag_info = $stmt->fetch();

// If hashtag doesn't exist, redirect
if (!$hashtag_info) {
    redirect('feed.php');
}

// Get posts with this hashtag
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.full_name, u.profile_picture,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_liked,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
    FROM posts p 
    JOIN users u ON p.user_id = u.id
    JOIN post_hashtags ph ON p.id = ph.post_id
    JOIN hashtags h ON ph.hashtag_id = h.id
    WHERE h.tag_name = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$current_user['id'], $hashtag]);
$posts = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - #<?php echo htmlspecialchars($hashtag); ?></title>
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
        
        .hashtag-header {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .hashtag-title {
            font-size: 2.5rem;
            color: #667eea;
            margin-bottom: 1rem;
            font-weight: bold;
        }
        
        .hashtag-stats {
            color: #666;
            font-size: 1.1rem;
        }
        
        .post {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .post:hover {
            transform: translateY(-2px);
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
        
        .comment-link {
            color: #667eea;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            padding: 0.5rem;
            border-radius: 5px;
        }
        
        .comment-link:hover {
            background: rgba(102, 126, 234, 0.1);
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
        
        .no-posts {
            text-align: center;
            color: #666;
            padding: 3rem;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .no-posts i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 1rem;
        }
        
        .no-posts h3 {
            margin-bottom: 0.5rem;
            color: #333;
        }
        
        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
            }
            
            .nav-links {
                gap: 1rem;
            }
            
            .hashtag-title {
                font-size: 2rem;
            }
            
            .post-header {
                flex-wrap: wrap;
            }
            
            .post-meta {
                width: 100%;
                margin-top: 0.5rem;
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
        <a href="javascript:history.back()" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Back
        </a>

        <!-- Hashtag Header -->
        <div class="hashtag-header">
            <div class="hashtag-title">#<?php echo htmlspecialchars($hashtag); ?></div>
            <div class="hashtag-stats">
                <?php echo $hashtag_info['post_count']; ?> post<?php echo $hashtag_info['post_count'] != 1 ? 's' : ''; ?>
            </div>
        </div>

        <!-- Posts -->
        <?php if (empty($posts)): ?>
            <div class="no-posts">
                <i class="fas fa-hashtag"></i>
                <h3>No posts found</h3>
                <p>There are no posts with this hashtag yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="post">
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
                        <div class="post-content">
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
                    
                    <div class="post-actions">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                            <button type="submit" name="toggle_like" class="like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>">
                                <i class="<?php echo $post['user_liked'] ? 'fas' : 'far'; ?> fa-heart"></i>
                                <span><?php echo $post['like_count']; ?></span>
                            </button>
                        </form>
                        <a href="post.php?id=<?php echo $post['id']; ?>" class="comment-link">
                            <i class="far fa-comment"></i>
                            <span><?php echo $post['comment_count']; ?></span>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>