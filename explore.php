<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getCurrentUser();

// Handle follow/unfollow
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_follow'])) {
    $user_id = (int)$_POST['user_id'];
    
    $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$current_user['id'], $user_id]);
    
    if ($stmt->fetch()) {
        // Unfollow
        $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$current_user['id'], $user_id]);
        echo json_encode(['success' => true, 'following' => false]);
    } else {
        // Follow
        $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$current_user['id'], $user_id]);
        echo json_encode(['success' => true, 'following' => true]);
    }
    exit;
}

// Handle like/unlike with AJAX response
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_like'])) {
    $post_id = (int)$_POST['post_id'];
    
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$current_user['id'], $post_id]);
    
    if ($stmt->fetch()) {
        // Unlike
        $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
        $stmt->execute([$current_user['id'], $post_id]);
        $liked = false;
    } else {
        // Like
        $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$current_user['id'], $post_id]);
        $liked = true;
    }
    
    // Get updated like count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $like_count = $stmt->fetchColumn();
    
    echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $like_count]);
    exit;
}

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$hashtag_filter = isset($_GET['hashtag']) ? trim($_GET['hashtag']) : '';

// Build query based on filters
$where_conditions = [];
$params = [$current_user['id']];

if (!empty($search)) {
    $where_conditions[] = "(p.caption LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($hashtag_filter)) {
    $where_conditions[] = "p.id IN (
        SELECT ph.post_id FROM post_hashtags ph 
        JOIN hashtags h ON ph.hashtag_id = h.id 
        WHERE h.tag_name = ?
    )";
    $params[] = strtolower($hashtag_filter);
}

$where_clause = !empty($where_conditions) ? 'AND ' . implode(' AND ', $where_conditions) : '';

// Get all posts (not just from followed users) with search and filter
$query = "
    SELECT p.*, u.username, u.full_name, u.profile_picture,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_liked,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    WHERE 1=1 $where_clause
    ORDER BY p.created_at DESC
    LIMIT 50
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Get trending hashtags (most used in last 7 days)
$trending_stmt = $pdo->prepare("
    SELECT h.tag_name, COUNT(ph.hashtag_id) as usage_count
    FROM hashtags h
    JOIN post_hashtags ph ON h.id = ph.hashtag_id
    JOIN posts p ON ph.post_id = p.id
    WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY h.id, h.tag_name
    ORDER BY usage_count DESC
    LIMIT 10
");
$trending_stmt->execute();
$trending_hashtags = $trending_stmt->fetchAll();

// Get random users to suggest following with follow status
$suggest_stmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.profile_picture,
           (SELECT COUNT(*) FROM posts WHERE user_id = u.id) as post_count,
           (SELECT COUNT(*) FROM follows WHERE following_id = u.id) as follower_count,
           (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) as is_following
    FROM users u
    WHERE u.id != ? 
    ORDER BY RAND()
    LIMIT 5
");
$suggest_stmt->execute([$current_user['id'], $current_user['id']]);
$suggested_users = $suggest_stmt->fetchAll();

include 'navbar.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Explore</title>
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
        
        .nav-links a:hover, .nav-links a.active {
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
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
        }
        
        .main-content {
            max-width: 800px;
        }
        
        .search-section {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .search-input {
            flex: 1;
            padding: 0.8rem 1rem;
            border: 2px solid #ddd;
            border-radius: 25px;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s;
        }
        
        .search-input:focus {
            border-color: #667eea;
        }
        
        .search-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: bold;
            transition: transform 0.3s;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
        }
        
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .filter-tag {
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            border: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-tag a {
            color: #666;
            text-decoration: none;
            margin-left: 0.5rem;
        }
        
        .post {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
        
        .post-meta {
            color: #999;
            font-size: 0.85rem;
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
        
        .hashtag {
            color: #667eea;
            text-decoration: none;
            font-weight: bold;
        }
        
        .hashtag:hover {
            text-decoration: underline;
        }
        
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .sidebar-section {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-section h3 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .trending-hashtag {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.3s;
        }
        
        .trending-hashtag:hover {
            background: #f8f9fa;
        }
        
        .hashtag-count {
            color: #666;
            font-size: 0.85rem;
        }
        
        .user-suggestion {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .user-suggestion:last-child {
            border-bottom: none;
        }
        
        .suggest-pic {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .suggest-info {
            flex: 1;
        }
        
        .suggest-info h5 {
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .suggest-stats {
            color: #666;
            font-size: 0.85rem;
        }
        
        .follow-btn, .unfollow-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.85rem;
            transition: all 0.3s;
            min-width: 80px;
        }
        
        .follow-btn:hover, .unfollow-btn:hover {
            transform: translateY(-1px);
        }
        
        .unfollow-btn {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }
        
        .follow-btn:disabled, .unfollow-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
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

    </style>
</head>
<body>
    <div class="container">
        <div class="main-content">
            <!-- Search Section -->
            <div class="search-section">
                <form method="GET" class="search-form">
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search posts, users, or hashtags..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <?php if (!empty($hashtag_filter)): ?>
                        <input type="hidden" name="hashtag" value="<?php echo htmlspecialchars($hashtag_filter); ?>">
                    <?php endif; ?>
                    <button type="submit" class="search-btn">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
                
                <?php if (!empty($search) || !empty($hashtag_filter)): ?>
                    <div class="active-filters">
                        <?php if (!empty($search)): ?>
                            <span class="filter-tag">
                                Search: "<?php echo htmlspecialchars($search); ?>"
                                <a href="explore.php<?php echo !empty($hashtag_filter) ? '?hashtag=' . urlencode($hashtag_filter) : ''; ?>">✕</a>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($hashtag_filter)): ?>
                            <span class="filter-tag">
                                #<?php echo htmlspecialchars($hashtag_filter); ?>
                                <a href="explore.php<?php echo !empty($search) ? '?search=' . urlencode($search) : ''; ?>">✕</a>
                            </span>
                        <?php endif; ?>
                        <a href="explore.php" class="filter-tag" style="background: #e74c3c; color: white;">
                            Clear All ✕
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Posts -->
            <?php if (empty($posts)): ?>
                <div class="no-posts">
                    <i class="fas fa-search"></i>
                    <h3>No posts found</h3>
                    <p>Try adjusting your search terms or filters.</p>
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
                        
                        <!-- Post content (caption first, then image) -->
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

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Trending Hashtags -->
            <div class="sidebar-section">
                <h3><i class="fas fa-fire"></i> Trending Hashtags</h3>
                <?php if (empty($trending_hashtags)): ?>
                    <p style="color: #666; font-size: 0.9rem;">No trending hashtags yet.</p>
                <?php else: ?>
                    <?php foreach ($trending_hashtags as $hashtag): ?>
                        <a href="explore.php?hashtag=<?php echo urlencode($hashtag['tag_name']); ?>" class="trending-hashtag">
                            <span>#<?php echo htmlspecialchars($hashtag['tag_name']); ?></span>
                            <span class="hashtag-count"><?php echo $hashtag['usage_count']; ?> posts</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Suggested Users -->
            <div class="sidebar-section">
                <h3><i class="fas fa-users"></i> People to Follow</h3>
                <?php if (empty($suggested_users)): ?>
                    <p style="color: #666; font-size: 0.9rem;">No suggestions available.</p>
                <?php else: ?>
                    <?php foreach ($suggested_users as $user): ?>
                        <div class="user-suggestion">
                            <img src="<?php echo $user['profile_picture'] ? 'uploads/profiles/' . $user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                                 alt="Profile" class="suggest-pic">
                            <div class="suggest-info">
                                <h5>
                                    <a href="profile.php?user=<?php echo $user['username']; ?>" style="text-decoration: none; color: inherit;">
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </a>
                                </h5>
                                <div class="suggest-stats">
                                    <?php echo $user['post_count']; ?> posts • <?php echo $user['follower_count']; ?> followers
                                </div>
                            </div>
                            <button class="<?php echo $user['is_following'] ? 'unfollow-btn' : 'follow-btn'; ?>" 
                                    onclick="toggleFollow(<?php echo $user['id']; ?>, this)"
                                    data-user-id="<?php echo $user['id']; ?>">
                                <?php echo $user['is_following'] ? 'Following' : 'Follow'; ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        //Responsive navbar
        function toggleMenu() {
        document.querySelector('.nav-links').classList.toggle('active');
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
            fetch('explore.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'toggle_like=1&post_id=' + postId
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
                    alert('Error: Failed to update like');
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

        // Function untuk handle follow/unfollow dengan AJAX
        function toggleFollow(userId, button) {
            const isFollowing = button.classList.contains('unfollow-btn');
            
            // Disable button temporarily
            button.disabled = true;
            button.textContent = isFollowing ? 'Unfollowing...' : 'Following...';
            
            // Send AJAX request
            fetch('explore.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'toggle_follow=1&user_id=' + userId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.following) {
                        button.classList.remove('follow-btn');
                        button.classList.add('unfollow-btn');
                        button.textContent = 'Following';
                    } else {
                        button.classList.remove('unfollow-btn');
                        button.classList.add('follow-btn');
                        button.textContent = 'Follow';
                    }
                } else {
                    alert('Error: Failed to update follow status');
                    // Revert button text
                    button.textContent = isFollowing ? 'Following' : 'Follow';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Revert button text on error
                button.textContent = isFollowing ? 'Following' : 'Follow';
                alert('Network error occurred');
            })
            .finally(() => {
                // Re-enable button
                button.disabled = false;
            });
        }

        // Auto-submit search form dengan delay
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    // Auto-submit setelah user berhenti mengetik selama 500ms
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.form.submit();
                    }
                }, 500);
            });
        }

        // Smooth scroll untuk hashtag clicks
        document.querySelectorAll('.hashtag').forEach(link => {
            link.addEventListener('click', function(e) {
                // Add loading animation
                const originalText = this.textContent;
                this.style.opacity = '0.6';
                setTimeout(() => {
                    this.style.opacity = '1';
                }, 200);
            });
        });

        // Infinite scroll (opsional - untuk load more posts)
        let isLoading = false;
        let currentPage = 1;

        function loadMorePosts() {
            if (isLoading) return;
            
            isLoading = true;
            const loadingIndicator = document.createElement('div');
            loadingIndicator.className = 'loading-indicator';
            loadingIndicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading more posts...';
            loadingIndicator.style.textAlign = 'center';
            loadingIndicator.style.padding = '2rem';
            loadingIndicator.style.color = '#666';
            
            document.querySelector('.main-content').appendChild(loadingIndicator);
            
            // Simulate loading (replace with actual AJAX call)
            setTimeout(() => {
                loadingIndicator.remove();
                isLoading = false;
            }, 1000);
        }

        // Detect when user scrolls near bottom
        window.addEventListener('scroll', () => {
            if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 1000) {
                loadMorePosts();
            }
        });

        // Image lazy loading untuk performa yang lebih baik
        const images = document.querySelectorAll('img');
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                }
            });
        });

        images.forEach(img => {
            if (img.dataset.src) {
                imageObserver.observe(img);
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K untuk focus ke search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Escape untuk clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput && document.activeElement === searchInput) {
                    searchInput.blur();
                }
            }
        });

        // Toast notification system
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#28a745' : '#dc3545'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 5px;
                z-index: 1000;
                transform: translateX(100%);
                transition: transform 0.3s ease;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            `;
            
            document.body.appendChild(toast);
            
            // Animate in
            setTimeout(() => {
                toast.style.transform = 'translateX(0)';
            }, 100);
            
            // Animate out and remove
            setTimeout(() => {
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 3000);
        }

        // Double-click to like (Instagram-style)
        document.querySelectorAll('.post-image').forEach(img => {
            let lastTap = 0;
            
            img.addEventListener('click', function(e) {
                const currentTime = new Date().getTime();
                const tapLength = currentTime - lastTap;
                
                if (tapLength < 500 && tapLength > 0) {
                    // Double tap detected
                    e.preventDefault();
                    
                    // Find the like button for this post
                    const post = this.closest('.post');
                    const likeButton = post.querySelector('.like-btn');
                    
                    if (likeButton && !likeButton.classList.contains('liked')) {
                        likeButton.click();
                        
                        // Show heart animation
                        const heart = document.createElement('i');
                        heart.className = 'fas fa-heart';
                        heart.style.cssText = `
                            position: absolute;
                            top: 50%;
                            left: 50%;
                            transform: translate(-50%, -50%) scale(0);
                            color: #e91e63;
                            font-size: 3rem;
                            pointer-events: none;
                            z-index: 10;
                            animation: heartPop 0.6s ease-out forwards;
                        `;
                        
                        this.parentElement.style.position = 'relative';
                        this.parentElement.appendChild(heart);
                        
                        setTimeout(() => {
                            if (heart.parentNode) {
                                heart.parentNode.removeChild(heart);
                            }
                        }, 600);
                    }
                }
                lastTap = currentTime;
            });
        });

        // Add CSS animation for heart pop
        const style = document.createElement('style');
        style.textContent = `
            @keyframes heartPop {
                0% {
                    transform: translate(-50%, -50%) scale(0);
                    opacity: 0;
                }
                50% {
                    transform: translate(-50%, -50%) scale(1.2);
                    opacity: 1;
                }
                100% {
                    transform: translate(-50%, -50%) scale(1);
                    opacity: 0;
                }
            }
            
            .loading-indicator {
                background: white;
                padding: 2rem;
                border-radius: 15px;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                margin-bottom: 1rem;
            }
        `;
        document.head.appendChild(style);

        // Service Worker registration untuk offline functionality (opsional)
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(err => {
                console.log('ServiceWorker registration failed: ', err);
            });
        }
    </script>
</body>
</html>
                