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

// Handle follow/unfollow
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_follow'])) {
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
        
        // Create notification
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, message, created_at) VALUES (?, 'follow', ?, 'started following you', NOW())");
        $stmt->execute([$target_user_id, $current_user['id']]);
    }
    
    redirect('following.php?user=' . $username);
}

// Get following
$stmt = $pdo->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) as is_following
    FROM users u 
    JOIN follows f ON u.id = f.following_id 
    WHERE f.follower_id = ?
    ORDER BY f.created_at DESC
");
$stmt->execute([$current_user['id'], $profile_user['id']]);
$following = $stmt->fetchAll();

// Get following count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
$stmt->execute([$profile_user['id']]);
$following_count = $stmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Following by <?php echo htmlspecialchars($profile_user['full_name']); ?></title>
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
            max-width: 600px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .header {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            color: #333;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .header p {
            color: #666;
        }
        
        .back-link {
            color: #667eea;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .user-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .user-info-card {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #667eea;
        }
        
        .user-details h3 {
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .user-details .username {
            color: #666;
            font-size: 0.9rem;
        }
        
        .user-details .bio {
            color: #666;
            font-size: 0.85rem;
            margin-top: 0.5rem;
            max-width: 300px;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.7rem 1.5rem;
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
        
        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .online-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }
        
        .status-online {
            background: #4caf50;
        }
        
        .status-offline {
            background: #ccc;
        }
        
        .no-users {
            background: white;
            padding: 3rem 2rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .no-users i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 1rem;
        }
        
        .no-users h3 {
            color: #666;
            margin-bottom: 0.5rem;
        }
        
        .no-users p {
            color: #999;
        }
        
        @media (max-width: 768px) {
            .user-card {
                flex-direction: column;
                gap: 1rem;
            }
            
            .user-info-card {
                width: 100%;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .nav-links {
                gap: 1rem;
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
        <div class="header">
            <a href="profile.php?user=<?php echo $username; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Back to Profile
            </a>
            <h1>
                <i class="fas fa-user-friends"></i>
                Following
            </h1>
            <p><?php echo $following_count; ?> people followed by <?php echo htmlspecialchars($profile_user['full_name']); ?></p>
        </div>
        
        <?php if (empty($following)): ?>
            <div class="no-users">
                <i class="fas fa-user-friends"></i>
                <h3>Not following anyone yet</h3>
                <p>
                    <?php if ($is_own_profile): ?>
                        Discover and follow interesting people!
                    <?php else: ?>
                        <?php echo htmlspecialchars($profile_user['full_name']); ?> isn't following anyone yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($following as $user): ?>
                <div class="user-card">
                    <div class="user-info-card">
                        <a href="profile.php?user=<?php echo $user['username']; ?>">
                            <img src="<?php echo $user['profile_picture'] ? 'uploads/profiles/' . $user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                                 alt="Profile" class="user-avatar">
                        </a>
                        <div class="user-details">
                            <h3>
                                <a href="profile.php?user=<?php echo $user['username']; ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </a>
                            </h3>
                            <div class="username">@<?php echo htmlspecialchars($user['username']); ?></div>
                            <div class="online-status">
                                <span class="status-dot <?php echo $user['is_online'] ? 'status-online' : 'status-offline'; ?>"></span>
                                <?php if ($user['is_online']): ?>
                                    Online
                                <?php else: ?>
                                    Last seen <?php echo $user['last_seen'] ? timeAgo($user['last_seen']) : 'Never'; ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($user['bio']): ?>
                                <div class="bio"><?php echo htmlspecialchars(substr($user['bio'], 0, 100)); ?><?php echo strlen($user['bio']) > 100 ? '...' : ''; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($user['id'] != $current_user['id']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" name="toggle_follow" class="btn <?php echo $user['is_following'] ? 'btn-outline' : ''; ?>">
                                <i class="fas <?php echo $user['is_following'] ? 'fa-user-minus' : 'fa-user-plus'; ?>"></i>
                                <?php echo $user['is_following'] ? 'Unfollow' : 'Follow'; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>