<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getCurrentUser();

// Handle sending new messages
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $recipient_id = (int)$_POST['recipient_id'];
    $message = sanitize($_POST['message']);
    
    if (!empty($message) && $recipient_id > 0) {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$current_user['id'], $recipient_id, $message]);
        
        // Create notification for recipient
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, message, created_at) VALUES (?, 'message', ?, 'sent you a message', NOW())");
        $stmt->execute([$recipient_id, $current_user['id']]);
        
        // Redirect to avoid form resubmission
        redirect('messages.php?chat=' . $recipient_id);
    }
}

// Handle marking messages as read
if (isset($_GET['mark_read']) && isset($_GET['chat'])) {
    $chat_user_id = (int)$_GET['chat'];
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ?");
    $stmt->execute([$chat_user_id, $current_user['id']]);
}

// Get user's conversations
$stmt = $pdo->prepare("
    SELECT DISTINCT 
        u.id, u.username, u.full_name, u.profile_picture, u.is_online, u.last_seen,
        (SELECT message FROM messages 
         WHERE (sender_id = u.id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.id)
         ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT created_at FROM messages 
         WHERE (sender_id = u.id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.id)
         ORDER BY created_at DESC LIMIT 1) as last_message_time,
        (SELECT COUNT(*) FROM messages 
         WHERE sender_id = u.id AND recipient_id = ? AND is_read = 0) as unread_count
    FROM users u
    WHERE u.id IN (
        SELECT DISTINCT sender_id FROM messages WHERE recipient_id = ?
        UNION
        SELECT DISTINCT recipient_id FROM messages WHERE sender_id = ?
    ) AND u.id != ?
    ORDER BY last_message_time DESC
");
$stmt->execute([
    $current_user['id'], $current_user['id'], 
    $current_user['id'], $current_user['id'], 
    $current_user['id'], 
    $current_user['id'], $current_user['id'], 
    $current_user['id']
]);
$conversations = $stmt->fetchAll();

// Get current chat messages if chat parameter is set
$chat_messages = [];
$chat_user = null;
if (isset($_GET['chat'])) {
    $chat_user_id = (int)$_GET['chat'];
    
    // Get chat user info
    $stmt = $pdo->prepare("SELECT id, username, full_name, profile_picture, is_online, last_seen FROM users WHERE id = ?");
    $stmt->execute([$chat_user_id]);
    $chat_user = $stmt->fetch();
    
    if ($chat_user) {
        // Get messages between current user and chat user
        $stmt = $pdo->prepare("
            SELECT m.*, u.username, u.full_name, u.profile_picture
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$current_user['id'], $chat_user_id, $chat_user_id, $current_user['id']]);
        $chat_messages = $stmt->fetchAll();
        
        // Mark messages as read
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ?");
        $stmt->execute([$chat_user_id, $current_user['id']]);
    }
}

// Get all users for new conversation
$stmt = $pdo->prepare("SELECT id, username, full_name, profile_picture FROM users WHERE id != ? ORDER BY full_name");
$stmt->execute([$current_user['id']]);
$all_users = $stmt->fetchAll();

include 'navbar.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Messages</title>
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

        .messages-container {
            max-width: 1200px;
            margin: 2rem auto;
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            height: calc(100vh - 120px);
            padding: 0 1rem;
        }

        .conversations-panel {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .conversations-header {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .conversations-header h3 {
            color: #333;
            margin: 0;
        }

        .new-chat-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: transform 0.3s;
        }

        .new-chat-btn:hover {
            transform: translateY(-2px);
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }

        /* FIXED: Conversation items with proper height */
        .conversation-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: inherit;
            min-height: 80px;
        }

        .conversation-item:hover {
            background-color: #f8f9fa;
        }

        .conversation-item.active {
            background-color: #667eea;
            color: white;
        }

        /* FIXED: Conversation avatar container */
        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }

        /* FIXED: Conversation avatar image */
        .conversation-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-name {
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .conversation-preview {
            font-size: 0.9rem;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-item.active .conversation-preview {
            color: rgba(255, 255, 255, 0.8);
        }

        .conversation-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
        }

        .conversation-time {
            font-size: 0.8rem;
            color: #999;
        }

        .conversation-item.active .conversation-time {
            color: rgba(255, 255, 255, 0.8);
        }

        .unread-badge {
            background: #e91e63;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .online-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            background: #4caf50;
            border: 2px solid white;
            border-radius: 50%;
        }

        .chat-panel {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* FIXED: Chat user avatar container */
        .chat-user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }

        /* FIXED: Chat user avatar image */
        .chat-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .chat-user-info h4 {
            margin: 0;
            color: #333;
        }

        .chat-user-status {
            font-size: 0.9rem;
            color: #666;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* FIXED: Message layout */
        .message {
            max-width: 70%;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        /* FIXED: Message avatar */
        .message-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        /* FIXED: Message content */
        .message-content {
            background: #f0f0f0;
            padding: 0.75rem 1rem;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
            max-width: calc(100% - 45px);
        }

        .message.sent .message-content {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .message-text {
            margin: 0;
            line-height: 1.4;
        }

        .message-time {
            font-size: 0.8rem;
            color: #999;
            margin-top: 0.25rem;
        }

        .message.sent .message-time {
            color: rgba(255, 255, 255, 0.8);
        }

        .chat-input {
            padding: 1.5rem;
            border-top: 1px solid #eee;
        }

        .input-group {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .input-group textarea {
            flex: 1;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 20px;
            resize: none;
            font-family: inherit;
            max-height: 100px;
        }

        .input-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .send-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 20px;
            cursor: pointer;
            font-weight: bold;
            transition: transform 0.3s;
        }

        .send-btn:hover {
            transform: translateY(-2px);
        }

        .send-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .empty-chat {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #666;
            text-align: center;
        }

        .empty-chat i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #ddd;
        }

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
            max-width: 500px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #000;
        }

        .user-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .user-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            border-radius: 10px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .user-item:hover {
            background-color: #f0f0f0;
        }

        /* FIXED: User item avatar */
        .user-item-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        /* FIXED: General image constraint */
        img {
            max-width: 100%;
            height: auto;
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
            .messages-container {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .conversations-panel {
                order: 2;
            }
            
            .chat-panel {
                order: 1;
                height: 70vh;
            }
        }
    </style>
</head>
<body>

    <div class="messages-container">
        <!-- Conversations Panel -->
        <div class="conversations-panel">
            <div class="conversations-header">
                <h3>Messages</h3>
                <button class="new-chat-btn" onclick="showNewChatModal()">
                    <i class="fas fa-plus"></i> New
                </button>
            </div>
            <div class="conversations-list">
                <?php if (empty($conversations)): ?>
                    <div style="padding: 2rem; text-align: center; color: #666;">
                        <i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 1rem; display: block; color: #ddd;"></i>
                        No conversations yet.<br>
                        Start a new conversation!
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                        <a href="messages.php?chat=<?php echo $conv['id']; ?>" 
                           class="conversation-item <?php echo (isset($_GET['chat']) && $_GET['chat'] == $conv['id']) ? 'active' : ''; ?>">
                            <div class="conversation-avatar">
                                <img src="<?php echo $conv['profile_picture'] ? 'uploads/profiles/' . $conv['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                                     alt="<?php echo htmlspecialchars($conv['full_name']); ?>">
                                <?php if ($conv['is_online']): ?>
                                    <div class="online-indicator"></div>
                                <?php endif; ?>
                            </div>
                            <div class="conversation-info">
                                <div class="conversation-name"><?php echo htmlspecialchars($conv['full_name']); ?></div>
                                <div class="conversation-preview">
                                    <?php echo htmlspecialchars(substr($conv['last_message'], 0, 30)) . (strlen($conv['last_message']) > 30 ? '...' : ''); ?>
                                </div>
                            </div>
                            <div class="conversation-meta">
                                <div class="conversation-time"><?php echo timeAgo($conv['last_message_time']); ?></div>
                                <?php if ($conv['unread_count'] > 0): ?>
                                    <div class="unread-badge"><?php echo $conv['unread_count']; ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Panel -->
        <div class="chat-panel">
            <?php if ($chat_user): ?>
                <div class="chat-header">
                    <div class="chat-user-avatar">
                        <img src="<?php echo $chat_user['profile_picture'] ? 'uploads/profiles/' . $chat_user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                             alt="<?php echo htmlspecialchars($chat_user['full_name']); ?>">
                        <?php if ($chat_user['is_online']): ?>
                            <div class="online-indicator"></div>
                        <?php endif; ?>
                    </div>
                    <div class="chat-user-info">
                        <h4><?php echo htmlspecialchars($chat_user['full_name']); ?></h4>
                        <div class="chat-user-status">
                            <?php if ($chat_user['is_online']): ?>
                                <i class="fas fa-circle" style="color: #4caf50; font-size: 0.7rem;"></i> Online
                            <?php else: ?>
                                Last seen <?php echo timeAgo($chat_user['last_seen']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($chat_messages)): ?>
                        <div style="text-align: center; color: #666; margin-top: 2rem;">
                            <i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 1rem; display: block; color: #ddd;"></i>
                            Start your conversation with <?php echo htmlspecialchars($chat_user['full_name']); ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($chat_messages as $msg): ?>
                            <div class="message <?php echo ($msg['sender_id'] == $current_user['id']) ? 'sent' : 'received'; ?>">
                                <img src="<?php echo $msg['profile_picture'] ? 'uploads/profiles/' . $msg['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                                     alt="Avatar" class="message-avatar">
                                <div class="message-content">
                                    <p class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                    <div class="message-time"><?php echo timeAgo($msg['created_at']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="chat-input">
                    <form method="POST" id="messageForm">
                        <input type="hidden" name="recipient_id" value="<?php echo $chat_user['id']; ?>">
                        <div class="input-group">
                            <textarea name="message" placeholder="Type your message..." required rows="1" id="messageInput"></textarea>
                            <button type="submit" name="send_message" class="send-btn" id="sendBtn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="empty-chat">
                    <i class="fas fa-comments"></i>
                    <h3>Welcome to MyRP Messages</h3>
                    <p>Select a conversation to start messaging</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Chat Modal -->
    <div id="newChatModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Start New Conversation</h3>
                <span class="close" onclick="hideNewChatModal()">&times;</span>
            </div>
            <div class="user-list">
                <?php foreach ($all_users as $user): ?>
                    <div class="user-item" onclick="startChat(<?php echo $user['id']; ?>)">
                        <img src="<?php echo $user['profile_picture'] ? 'uploads/profiles/' . $user['profile_picture'] : 'assets/default-avatar.png'; ?>" 
                             alt="<?php echo htmlspecialchars($user['full_name']); ?>" class="user-item-avatar">
                        <div>
                            <div style="font-weight: bold;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div style="color: #666; font-size: 0.9rem;">@<?php echo htmlspecialchars($user['username']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        //Responsive navbar
        function toggleMenu() {
        document.querySelector('.nav-links').classList.toggle('active');
        }
        
        // Auto-resize textarea
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            });

            // Send message with Enter key
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    document.getElementById('messageForm').submit();
                }
            });
        }

        // Auto-scroll to bottom of chat
        function scrollToBottom() {
            const chatMessages = document.getElementById('chatMessages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        }

        // Scroll to bottom on page load
        document.addEventListener('DOMContentLoaded', function() {
            scrollToBottom();
        });

        // New chat modal functions
        function showNewChatModal() {
            document.getElementById('newChatModal').style.display = 'block';
        }

        function hideNewChatModal() {
            document.getElementById('newChatModal').style.display = 'none';
        }

        function startChat(userId) {
            window.location.href = 'messages.php?chat=' + userId;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('newChatModal');
            if (event.target == modal) {
                hideNewChatModal();
            }
        }

        // Auto-refresh conversations every 30 seconds
        setInterval(function() {
            // Only refresh if not currently typing
            if (document.activeElement !== messageInput) {
                // You can implement AJAX refresh here if needed
                // For now, we'll keep it simple
            }
        }, 30000);
    </script>
</body>
</html>