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
            transition: all 0.3s ease;
        }

        .logo:hover {
            color: #764ba2;
            transform: scale(1.05);
        }

        .nav-links {
            display: flex;
            gap: 30px;
        }

        .nav-links a {
            text-decoration: none;
            color: #555;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            padding: 8px 0;
        }

        .nav-links a:hover {
            color: #667eea;
            transform: translateY(-2px);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #667eea, #764ba2);
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
            transition: all 0.3s ease;
        }

        .user-info .profile-pic:hover {
            border-color: #764ba2;
            transform: scale(1.1);
        }

        .user-info a {
            text-decoration: none;
            color: #555;
            font-weight: 500;
            transition: all 0.3s ease;
            padding: 5px 10px;
            border-radius: 15px;
        }

        .user-info a:hover {
            color: #667eea;
            background: rgba(102, 126, 234, 0.1);
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
            backdrop-filter: blur(15px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #667eea;
            font-weight: 500;
            margin-bottom: 20px;
            padding: 12px 24px;
            border-radius: 25px;
            background: rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .back-link:hover {
            background: rgba(102, 126, 234, 0.2);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .back-link i {
            transition: transform 0.3s ease;
        }

        .back-link:hover i {
            transform: translateX(-3px);
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
            font-weight: 600;
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header h1 i {
            margin-right: 10px;
            color: #667eea;
            -webkit-text-fill-color: #667eea;
        }

        .header p {
            color: #666;
            font-size: 16px;
            opacity: 0.8;
        }

        /* No Users Message */
        .no-users {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 20px;
            padding: 60px 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .no-users::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .no-users i {
            font-size: 60px;
            color: #667eea;
            margin-bottom: 20px;
            opacity: 0.7;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .no-users h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 24px;
            font-weight: 600;
        }

        .no-users p {
            color: #666;
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* User Card Styles */
        .user-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: all 0.4s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .user-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(102, 126, 234, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .user-card:hover::before {
            left: 100%;
        }

        .user-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .user-info-card {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            z-index: 1;
            position: relative;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #667eea;
            transition: all 0.4s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .user-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            border-color: #764ba2;
            box-shadow: 0 6px 20px rgba(118, 75, 162, 0.4);
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
            transition: all 0.3s ease;
            position: relative;
        }

        .user-details h3 a:hover {
            color: #667eea;
        }

        .username {
            color: #667eea;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .user-card:hover .username {
            color: #764ba2;
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
            animation: breathe 2s infinite;
        }

        @keyframes breathe {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }

        .status-online {
            background: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.3);
        }

        .status-offline {
            background: #999;
            animation: none;
        }

        .bio {
            color: #666;
            font-size: 14px;
            font-style: italic;
            margin-top: 5px;
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .user-card:hover .bio {
            opacity: 1;
        }

        /* Button Styles */
        .btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
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
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, #764ba2, #667eea);
            transition: left 0.3s ease;
            z-index: -1;
        }

        .btn:hover::before {
            left: 0;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline::before {
            background: linear-gradient(45deg, #667eea, #764ba2);
        }

        .btn-outline:hover {
            color: white;
            border-color: #667eea;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-container {
                padding: 0 15px;
                flex-wrap: wrap;
                height: auto;
                padding: 15px;
                gap: 15px;
            }
            
            .nav-links {
                gap: 20px;
                order: 3;
                width: 100%;
                justify-content: center;
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid rgba(102, 126, 234, 0.2);
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
            .nav-container {
                padding: 10px;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
            }
            
            .nav-links a {
                font-size: 14px;
                padding: 6px 12px;
                background: rgba(102, 126, 234, 0.1);
                border-radius: 15px;
            }
            
            .header {
                padding: 20px 15px;
            }
            
            .header h1 {
                font-size: 22px;
            }
            
            .back-link {
                padding: 10px 20px;
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
            
            .no-users {
                padding: 30px 15px;
            }
            
            .no-users h3 {
                font-size: 20px;
            }
            
            .no-users p {
                font-size: 14px;
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
            animation: fadeInUp 0.6s ease forwards;
        }

        .user-card:nth-child(2n) {
            animation-delay: 0.1s;
        }

        .user-card:nth-child(3n) {
            animation-delay: 0.2s;
        }

        .user-card:nth-child(4n) {
            animation-delay: 0.3s;
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, #764ba2, #667eea);
        }

        /* Loading Animation */
        @keyframes shimmer {
            0% { background-position: -200px 0; }
            100% { background-position: calc(200px + 100%) 0; }
        }

        .loading {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }

        /* Hover effects for better UX */
        .user-card:hover .user-details h3 a {
            text-shadow: 0 0 10px rgba(102, 126, 234, 0.3);
        }

        .user-card:hover .status-online {
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.4);
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