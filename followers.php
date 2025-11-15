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
    
    redirect('followers.php?user=' . $username);
}

// Get followers
$stmt = $pdo->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) as is_following
    FROM users u 
    JOIN follows f ON u.id = f.follower_id 
    WHERE f.following_id = ?
    ORDER BY f.created_at DESC
");
$stmt->execute([$current_user['id'], $profile_user['id']]);
$followers = $stmt->fetchAll();

// Get follower count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
$stmt->execute([$profile_user['id']]);
$follower_count = $stmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Followers of <?php echo htmlspecialchars($profile_user['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
            line-height: 1.6;
        }

        /* Navigation Styles */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 60px;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
            text-decoration: none;
        }

        .nav-links {
            display: flex;
            gap: 30px;
        }

        .nav-links a {
            text-decoration: none;
            color: #555;
            font-weight: 500;
            transition: color 0.3s ease;
            position: relative;
        }

        .nav-links a:hover {
            color: #667eea;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: #667eea;
            transition: width 0.3s ease;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info .profile-pic {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #667eea;
        }

        .user-info a {
            text-decoration: none;
            color: #555;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .user-info a:hover {
            color: #667eea;
        }

        /* Container */
        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }

        /* Header Styles */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #667eea;
            font-weight: 500;
            margin-bottom: 20px;
            padding: 10px 20px;
            border-radius: 25px;
            background: rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .back-link:hover {
            background: rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
            font-weight: 600;
        }

        .header h1 i {
            margin-right: 10px;
            color: #667eea;
        }

        .header p {
            color: #666;
            font-size: 16px;
        }

        /* No Users Message */
        .no-users {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 60px 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .no-users i {
            font-size: 60px;
            color: #667eea;
            margin-bottom: 20px;
            opacity: 0.7;
        }

        .no-users h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 24px;
        }

        .no-users p {
            color: #666;
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
        }

        /* User Card Styles */
        .user-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .user-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .user-info-card {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #667eea;
            transition: transform 0.3s ease;
        }

        .user-avatar:hover {
            transform: scale(1.05);
        }

        .user-details h3 {
            margin-bottom: 5px;
            color: #333;
            font-size: 18px;
            font-weight: 600;
        }

        .user-details h3 a {
            text-decoration: none;
            color: inherit;
            transition: color 0.3s ease;
        }

        .user-details h3 a:hover {
            color: #667eea;
        }

        .username {
            color: #667eea;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .online-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .status-online {
            background: #4CAF50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.3);
        }

        .status-offline {
            background: #999;
        }

        .bio {
            color: #666;
            font-size: 14px;
            font-style: italic;
            margin-top: 5px;
        }

        /* Button Styles */
        .btn {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 25px;
            padding: 12px 25px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 120px;
            justify-content: center;
            font-family: inherit;
        }

        .btn:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
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

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-container {
                padding: 0 15px;
                flex-direction: column;
                height: auto;
                padding: 15px;
            }
            
            .nav-links {
                gap: 20px;
                margin: 10px 0;
            }
            
            .user-info {
                gap: 10px;
            }
            
            .container {
                margin: 20px auto;
                padding: 0 15px;
            }
            
            .header {
                padding: 25px 20px;
                margin-bottom: 25px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .user-card {
                flex-direction: column;
                gap: 20px;
                align-items: stretch;
                padding: 20px;
            }
            
            .user-info-card {
                gap: 15px;
            }
            
            .user-avatar {
                width: 50px;
                height: 50px;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .no-users {
                padding: 40px 20px;
            }
            
            .no-users i {
                font-size: 50px;
            }
        }

        @media (max-width: 480px) {
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
            }
            
            .nav-links a {
                font-size: 14px;
            }
            
            .header {
                padding: 20px 15px;
            }
            
            .header h1 {
                font-size: 22px;
            }
            
            .back-link {
                padding: 8px 15px;
                font-size: 14px;
            }
            
            .user-card {
                padding: 15px;
            }
            
            .user-info-card {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .user-details {
                text-align: center;
            }
            
            .user-avatar {
                align-self: center;
            }
            
            .online-status {
                justify-content: center;
            }
        }

        /* Animation for page load */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .user-card {
            animation: fadeInUp 0.5s ease forwards;
        }

        .user-card:nth-child(even) {
            animation-delay: 0.1s;
        }

        .user-card:nth-child(odd) {
            animation-delay: 0.2s;
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(102, 126, 234, 0.5);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(102, 126, 234, 0.7);
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
                <i class="fas fa-users"></i>
                Followers
            </h1>
            <p><?php echo $follower_count; ?> followers of <?php echo htmlspecialchars($profile_user['full_name']); ?></p>
        </div>
        
        <?php if (empty($followers)): ?>
            <div class="no-users">
                <i class="fas fa-users"></i>
                <h3>No followers yet</h3>
                <p>
                    <?php if ($is_own_profile): ?>
                        Share your content to gain followers!
                    <?php else: ?>
                        <?php echo htmlspecialchars($profile_user['full_name']); ?> doesn't have any followers yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($followers as $follower): ?>
                <div class="user-card">
                    <div class="user-info-card">
                        <a href="profile.php?user=<?php echo $follower['username']; ?>">
                            <img src="<?php echo $follower['profile_picture'] ? 'uploads/profiles/' . $follower['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                                 alt="Profile" class="user-avatar">
                        </a>
                        <div class="user-details">
                            <h3>
                                <a href="profile.php?user=<?php echo $follower['username']; ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo htmlspecialchars($follower['full_name']); ?>
                                </a>
                            </h3>
                            <div class="username">@<?php echo htmlspecialchars($follower['username']); ?></div>
                            <div class="online-status">
                                <span class="status-dot <?php echo $follower['is_online'] ? 'status-online' : 'status-offline'; ?>"></span>
                                <?php if ($follower['is_online']): ?>
                                    Online
                                <?php else: ?>
                                    Last seen <?php echo $follower['last_seen'] ? timeAgo($follower['last_seen']) : 'Never'; ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($follower['bio']): ?>
                                <div class="bio"><?php echo htmlspecialchars(substr($follower['bio'], 0, 100)); ?><?php echo strlen($follower['bio']) > 100 ? '...' : ''; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($follower['id'] != $current_user['id']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $follower['id']; ?>">
                            <button type="submit" name="toggle_follow" class="btn <?php echo $follower['is_following'] ? 'btn-outline' : ''; ?>">
                                <i class="fas <?php echo $follower['is_following'] ? 'fa-user-minus' : 'fa-user-plus'; ?>"></i>
                                <?php echo $follower['is_following'] ? 'Unfollow' : 'Follow'; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>