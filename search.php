<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getCurrentUser();

// Get search parameters
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recent';

$results = [
    'posts' => [],
    'users' => [],
    'hashtags' => []
];

if (!empty($query)) {
    // Search Posts
    if ($type === 'all' || $type === 'posts') {
        $post_query = "
            SELECT p.*, u.username, u.full_name, u.profile_picture,
                   (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                   (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_liked,
                   (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.caption LIKE ?
            ORDER BY " . ($sort === 'popular' ? 'like_count DESC, p.created_at DESC' : 'p.created_at DESC') . "
            LIMIT 20
        ";
        
        $stmt = $pdo->prepare($post_query);
        $stmt->execute([$current_user['id'], '%' . $query . '%']);
        $results['posts'] = $stmt->fetchAll();
    }
    
    // Search Users
    if ($type === 'all' || $type === 'users') {
        $user_query = "
            SELECT u.*, 
                   (SELECT COUNT(*) FROM posts WHERE user_id = u.id) as post_count,
                   (SELECT COUNT(*) FROM follows WHERE following_id = u.id) as follower_count,
                   (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) as is_following
            FROM users u 
            WHERE u.id != ? AND (u.username LIKE ? OR u.full_name LIKE ? OR u.bio LIKE ?)
            ORDER BY " . ($sort === 'popular' ? 'follower_count DESC' : 'u.created_at DESC') . "
            LIMIT 20
        ";
        
        $search_param = '%' . $query . '%';
        $stmt = $pdo->prepare($user_query);
        $stmt->execute([$current_user['id'], $current_user['id'], $search_param, $search_param, $search_param]);
        $results['users'] = $stmt->fetchAll();
    }
    
    // Search Hashtags
    if ($type === 'all' || $type === 'hashtags') {
        $hashtag_query = "
            SELECT h.*, COUNT(ph.post_id) as post_count,
                   COUNT(CASE WHEN p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_posts
            FROM hashtags h
            LEFT JOIN post_hashtags ph ON h.id = ph.hashtag_id
            LEFT JOIN posts p ON ph.post_id = p.id
            WHERE h.tag_name LIKE ?
            GROUP BY h.id
            ORDER BY " . ($sort === 'popular' ? 'post_count DESC' : 'recent_posts DESC') . "
            LIMIT 20
        ";
        
        $stmt = $pdo->prepare($hashtag_query);
        $stmt->execute(['%' . $query . '%']);
        $results['hashtags'] = $stmt->fetchAll();
    }
}

// Handle follow/unfollow
if (isset($_POST['toggle_follow'])) {
    $target_user_id = (int)$_POST['user_id'];
    
    $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$current_user['id'], $target_user_id]);
    
    if ($stmt->fetch()) {
        // Unfollow
        $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$current_user['id'], $target_user_id]);
    } else {
        // Follow
        $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$current_user['id'], $target_user_id]);
    }
    
    // Redirect back with search parameters
    $redirect_params = [];
    if (!empty($query)) $redirect_params['q'] = $query;
    if ($type !== 'all') $redirect_params['type'] = $type;
    if ($sort !== 'recent') $redirect_params['sort'] = $sort;
    
    $redirect_url = 'search.php';
    if (!empty($redirect_params)) {
        $redirect_url .= '?' . http_build_query($redirect_params);
    }
    redirect($redirect_url);
}

// Handle post like/unlike
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
    
    // Redirect back with search parameters
    $redirect_params = [];
    if (!empty($query)) $redirect_params['q'] = $query;
    if ($type !== 'all') $redirect_params['type'] = $type;
    if ($sort !== 'recent') $redirect_params['sort'] = $sort;
    
    $redirect_url = 'search.php';
    if (!empty($redirect_params)) {
        $redirect_url .= '?' . http_build_query($redirect_params);
    }
    redirect($redirect_url);
}

// Get recent searches for current user (you'd need to implement this table)
$recent_searches = [];

// Get popular hashtags for suggestions
$popular_hashtags_stmt = $pdo->prepare("
    SELECT h.tag_name, COUNT(ph.hashtag_id) as usage_count
    FROM hashtags h
    JOIN post_hashtags ph ON h.id = ph.hashtag_id
    JOIN posts p ON ph.post_id = p.id
    WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY h.id, h.tag_name
    ORDER BY usage_count DESC
    LIMIT 8
");
$popular_hashtags_stmt->execute();
$popular_hashtags = $popular_hashtags_stmt->fetchAll();

include 'navbar.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Search</title>
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
            display: flex;
            flex-direction: column;
        }
        
        .search-header {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 25px;
            font-size: 1.1rem;
            background: #f8f9ff;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .search-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: bold;
            font-size: 1rem;
        }
        
        .search-filters {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-select {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
        }
        
        .search-stats {
            color: #666;
            font-size: 0.9rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }
        
        .search-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .tab-btn {
            background: white;
            border: 2px solid #ddd;
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            text-decoration: none;
            color: #666;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .results-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.3rem;
            color: #333;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .post-result {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .post-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .post-user-pic {
            width: 45px;
            height: 45px;
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
            max-height: 300px;
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
            transition: color 0.3s;
        }
        
        .like-btn:hover, .like-btn.liked {
            color: #e74c3c;
        }
        
        .user-result {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .user-result-pic {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-result-info {
            flex: 1;
        }
        
        .user-result-info h4 {
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .user-result-info .username {
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .user-result-info .bio {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .user-stats {
            color: #999;
            font-size: 0.8rem;
        }
        
        .follow-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s;
        }
        
        .follow-btn.following {
            background: #95a5a6;
        }
        
        .hashtag-result {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .hashtag-info h4 {
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .hashtag-stats {
            color: #666;
            font-size: 0.9rem;
        }
        
        .explore-hashtag-btn {
            background: #f8f9ff;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: bold;
        }
        
        .hashtag {
            color: #667eea;
            text-decoration: none;
            font-weight: bold;
        }
        
        .hashtag:hover {
            text-decoration: underline;
        }
        
        .no-results {
            text-align: center;
            padding: 3rem;
            color: #666;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            margin-bottom: 1rem;
            color: #333;
        }
        
        .suggestion-hashtag {
            display: inline-block;
            background: #f8f9ff;
            color: #667eea;
            padding: 0.4rem 0.8rem;
            border-radius: 15px;
            text-decoration: none;
            margin: 0.2rem;
            font-size: 0.9rem;
            border: 1px solid #e0e6ff;
        }
        
        .suggestion-hashtag:hover {
            background: #667eea;
            color: white;
        }
        
        .search-tip {
            background: #e8f4fd;
            border-left: 4px solid #667eea;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0 8px 8px 0;
        }
        
        .search-tip h4 {
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .search-tip p {
            color: #666;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .nav-container {
                padding: 0 1rem;
            }
            
            .nav-links {
                gap: 1rem;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-filters {
                justify-content: center;
            }
            
            .search-tabs {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="main-content">
            <!-- Search Header -->
            <div class="search-header">
                <form method="GET" class="search-form">
                    <input type="text" name="q" class="search-input" 
                           placeholder="Search for posts, people, hashtags..." 
                           value="<?php echo htmlspecialchars($query); ?>"
                           autofocus>
                    <button type="submit" class="search-btn">🔍 Search</button>
                </form>
                
                <div class="search-filters">
                    <div class="filter-group">
                        <label>Type:</label>
                        <select name="type" class="filter-select" onchange="updateSearch()">
                            <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="posts" <?php echo $type === 'posts' ? 'selected' : ''; ?>>Posts</option>
                            <option value="users" <?php echo $type === 'users' ? 'selected' : ''; ?>>People</option>
                            <option value="hashtags" <?php echo $type === 'hashtags' ? 'selected' : ''; ?>>Hashtags</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Sort by:</label>
                        <select name="sort" class="filter-select" onchange="updateSearch()">
                            <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                            <option value="popular" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                        </select>
                    </div>
                </div>
                
                <?php if (!empty($query)): ?>
                    <div class="search-stats">
                        Showing results for "<strong><?php echo htmlspecialchars($query); ?></strong>"
                        <?php 
                        $total_results = count($results['posts']) + count($results['users']) + count($results['hashtags']);
                        echo " - {$total_results} results found";
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($query)): ?>
                <!-- Search Tabs -->
                <div class="search-tabs">
                    <a href="?q=<?php echo urlencode($query); ?>&type=all&sort=<?php echo $sort; ?>" 
                       class="tab-btn <?php echo $type === 'all' ? 'active' : ''; ?>">
                        All (<?php echo count($results['posts']) + count($results['users']) + count($results['hashtags']); ?>)
                    </a>
                    <a href="?q=<?php echo urlencode($query); ?>&type=posts&sort=<?php echo $sort; ?>" 
                       class="tab-btn <?php echo $type === 'posts' ? 'active' : ''; ?>">
                        Posts (<?php echo count($results['posts']); ?>)
                    </a>
                    <a href="?q=<?php echo urlencode($query); ?>&type=users&sort=<?php echo $sort; ?>" 
                       class="tab-btn <?php echo $type === 'users' ? 'active' : ''; ?>">
                        People (<?php echo count($results['users']); ?>)
                    </a>
                    <a href="?q=<?php echo urlencode($query); ?>&type=hashtags&sort=<?php echo $sort; ?>" 
                       class="tab-btn <?php echo $type === 'hashtags' ? 'active' : ''; ?>">
                        Hashtags (<?php echo count($results['hashtags']); ?>)
                    </a>
                </div>

                <!-- Results -->
                <?php if (count($results['posts']) + count($results['users']) + count($results['hashtags']) === 0): ?>
                    <div class="no-results">
                        <h3>No results found</h3>
                        <p>Try different keywords or check your spelling.</p>
                    </div>
                <?php else: ?>
                    <!-- Posts Results -->
                    <?php if (($type === 'all' || $type === 'posts') && !empty($results['posts'])): ?>
                        <div class="results-section">
                            <h2 class="section-title">📝 Posts</h2>
                            <?php foreach ($results['posts'] as $post): ?>
                                <div class="post-result">
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
                                        <div style="margin-left: auto; color: #999; font-size: 0.9rem;">
                                            <?php echo timeAgo($post['created_at']); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($post['image']): ?>
                                        <img src="uploads/posts/<?php echo $post['image']; ?>" alt="Post image" class="post-image">
                                    <?php endif; ?>
                                    
                                    <div class="post-content">
                                        <?php 
                                        $caption = htmlspecialchars($post['caption']);
                                        $caption = preg_replace('/#([a-zA-Z0-9_]+)/', '<a href="explore.php?hashtag=$1" class="hashtag">#$1</a>', $caption);
                                        echo nl2br($caption);
                                        ?>
                                    </div>
                                    
                                    <div class="post-actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                            <button type="submit" name="toggle_like" class="like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>">
                                                ❤️ <?php echo $post['like_count']; ?>
                                            </button>
                                        </form>
                                        <a href="post.php?id=<?php echo $post['id']; ?>" style="color: #666; text-decoration: none;">
                                            💬 <?php echo $post['comment_count']; ?> Comments
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Users Results -->
                    <?php if (($type === 'all' || $type === 'users') && !empty($results['users'])): ?>
                        <div class="results-section">
                            <h2 class="section-title">👥 People</h2>
                            <?php foreach ($results['users'] as $user): ?>
                                <div class="user-result">
                                    <img src="<?php echo $user['profile_picture'] ? 'uploads/profiles/' . $user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                                         alt="Profile" class="user-result-pic">
                                    <div class="user-result-info">
                                        <h4>
                                            <a href="profile.php?user=<?php echo $user['username']; ?>" style="text-decoration: none; color: inherit;">
                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                            </a>
                                        </h4>
                                        <div class="username">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        <?php if ($user['bio']): ?>
                                            <div class="bio"><?php echo htmlspecialchars($user['bio']); ?></div>
                                        <?php endif; ?>
                                        <div class="user-stats">
                                            <?php echo $user['post_count']; ?> posts • <?php echo $user['follower_count']; ?> followers
                                        </div>
                                    </div>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="toggle_follow" 
                                                class="follow-btn <?php echo $user['is_following'] ? 'following' : ''; ?>">
                                            <?php echo $user['is_following'] ? 'Following' : 'Follow'; ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Hashtags Results -->
                    <?php if (($type === 'all' || $type === 'hashtags') && !empty($results['hashtags'])): ?>
                        <div class="results-section">
                            <h2 class="section-title">#️⃣ Hashtags</h2>
                            <?php foreach ($results['hashtags'] as $hashtag): ?>
                                <div class="hashtag-result">
                                    <div class="hashtag-info">
                                        <h4>#<?php echo htmlspecialchars($hashtag['tag_name']); ?></h4>
                                        <div class="hashtag-stats">
                                            <?php echo $hashtag['post_count']; ?> posts
                                            <?php if ($hashtag['recent_posts'] > 0): ?>
                                                • <?php echo $hashtag['recent_posts']; ?> this week
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="explore.php?hashtag=<?php echo urlencode($hashtag['tag_name']); ?>" class="explore-hashtag-btn">
                                        Explore
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <?php if (empty($query)): ?>
                <!-- Search Tips -->
                <div class="sidebar-section">
                    <div class="search-tip">
                        <h4>💡 Search Tips</h4>
                        <p>Use keywords to find posts, @ to find people, or # to find hashtags.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Popular Hashtags -->
            <div class="sidebar-section">
                <h3>🔥 Popular Hashtags</h3>
                <?php if (empty($popular_hashtags)): ?>
                    <p style="color: #666; font-size: 0.9rem;">No popular hashtags yet.</p>
                <?php else: ?>
                    <?php foreach ($popular_hashtags as $hashtag): ?>
                        <a href="?q=%23<?php echo urlencode($hashtag['tag_name']); ?>" class="suggestion-hashtag">
                            #<?php echo htmlspecialchars($hashtag['tag_name']); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Search History -->
            <div class="sidebar-section">
                <h3>🕒 Recent Searches</h3>
                <?php if (empty($recent_searches)): ?>
                    <p style="color: #666; font-size: 0.9rem;">No recent searches.</p>
                <?php else: ?>
                    <?php foreach ($recent_searches as $search): ?>
                        <a href="?q=<?php echo urlencode($search); ?>" 
                           style="display: block; color: #667eea; text-decoration: none; padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0;">
                            <?php echo htmlspecialchars($search); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Advanced Search Tips -->
            <div class="sidebar-section">
                <h3>🔍 Advanced Search</h3>
                <div style="font-size: 0.9rem; color: #666; line-height: 1.6;">
                    <p><strong>@username</strong> - Find specific users</p>
                    <p><strong>#hashtag</strong> - Find posts with hashtags</p>
                    <p><strong>"exact phrase"</strong> - Find exact matches</p>
                    <p><strong>keyword1 keyword2</strong> - Find posts with both words</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateSearch() {
            const form = document.querySelector('.search-form');
            const typeSelect = document.querySelector('select[name="type"]');
            const sortSelect = document.querySelector('select[name="sort"]');
            const queryInput = document.querySelector('input[name="q"]');
            
            if (queryInput.value.trim()) {
                const url = new URL(window.location.href);
                url.searchParams.set('q', queryInput.value);
                url.searchParams.set('type', typeSelect.value);
                url.searchParams.set('sort', sortSelect.value);
                window.location.href = url.toString();
            }
        }

        // Auto-submit search when filters change
        document.querySelectorAll('.filter-select').forEach(select => {
            select.addEventListener('change', updateSearch);
        });

        // Highlight search terms in results
        function highlightSearchTerms() {
            const query = '<?php echo addslashes($query); ?>';
            if (query) {
                const terms = query.split(' ').filter(term => term.length > 2);
                terms.forEach(term => {
                    const regex = new RegExp(`(${term})`, 'gi');
                    document.querySelectorAll('.post-content, .user-result-info .bio').forEach(element => {
                        element.innerHTML = element.innerHTML.replace(regex, '<mark style="background: #fff3cd; padding: 0.1rem 0.2rem; border-radius: 3px;">$1</mark>');
                    });
                });
            }
        }

        // Call highlight function when page loads
        document.addEventListener('DOMContentLoaded', highlightSearchTerms);

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                document.querySelector('.search-input').focus();
            }
            
            // Escape to clear search
            if (e.key === 'Escape' && document.activeElement === document.querySelector('.search-input')) {
                document.querySelector('.search-input').value = '';
            }
        });

        // Add search suggestions (basic implementation)
        const searchInput = document.querySelector('.search-input');
        let searchTimeout;

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length > 2) {
                searchTimeout = setTimeout(() => {
                    // You could implement live search suggestions here
                    console.log('Searching for:', query);
                }, 300);
            }
        });

        // Add loading state for search button
        document.querySelector('.search-form').addEventListener('submit', function() {
            const btn = document.querySelector('.search-btn');
            btn.innerHTML = '🔄 Searching...';
            btn.disabled = true;
        });
    </script>
</body>
</html>