    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo">MyRP</div>
            <div class="menu-toggle" onclick="toggleMenu()">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <div class="nav-links" id="navMenu">
                <a href="feed.php">Feed</a>
                <a href="explore.php">Explore</a>
                <a href="messages.php">Messages</a>
                <a href="search.php">Search</a>
            
                <div class="user-info">
                    <img src="<?php echo $current_user['profile_picture'] ? 'uploads/profiles/' . $current_user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                        alt="Profile" class="profile-pic">
                    <a href="profile.php?user=<?php echo $current_user['username']; ?>" class="username-link">
                        <?php echo htmlspecialchars($current_user['full_name']); ?>
                    </a>
                </div>
            </div>
        </div>
    </nav>