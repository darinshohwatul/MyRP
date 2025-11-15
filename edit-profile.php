<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);
    $bio = sanitize($_POST['bio']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($full_name) || empty($email)) {
        $message = 'Nama lengkap dan email harus diisi!';
        $message_type = 'error';
    } elseif (!isValidEmail($email)) {
        $message = 'Format email tidak valid!';
        $message_type = 'error';
    } else {
        // Check if email is already taken by another user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        
        if ($stmt->fetch()) {
            $message = 'Email sudah digunakan oleh pengguna lain!';
            $message_type = 'error';
        } else {
            // Handle profile picture upload
            $profile_picture = null;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
                $upload_result = uploadFile($_FILES['profile_picture'], 'uploads/profiles/');
                
                if ($upload_result) {
                    $profile_picture = $upload_result;
                    
                    // Delete old profile picture (except default)
                    $current_user = getCurrentUser();
                    if ($current_user['profile_picture'] && $current_user['profile_picture'] != 'default-avatar.jpg') {
                        $old_file = 'uploads/profiles/' . $current_user['profile_picture'];
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
                } else {
                    $message = 'Gagal mengupload foto profil! Pastikan file berformat JPG, PNG, atau GIF dengan ukuran maksimal 5MB.';
                    $message_type = 'error';
                }
            }
            
            // Handle password change
            $password_hash = null;
            if (!empty($current_password) && !empty($new_password)) {
                if ($new_password !== $confirm_password) {
                    $message = 'Konfirmasi password baru tidak cocok!';
                    $message_type = 'error';
                } elseif (strlen($new_password) < 6) {
                    $message = 'Password baru minimal 6 karakter!';
                    $message_type = 'error';
                } else {
                    // Verify current password
                    $current_user = getCurrentUser();
                    if (password_verify($current_password, $current_user['password'])) {
                        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    } else {
                        $message = 'Password saat ini salah!';
                        $message_type = 'error';
                    }
                }
            }
            
            // Update user data if no errors
            if (empty($message)) {
                try {
                    // Build update query dynamically
                    $update_fields = ['full_name = ?', 'email = ?', 'bio = ?', 'updated_at = NOW()'];
                    $params = [$full_name, $email, $bio];
                    
                    if ($profile_picture) {
                        $update_fields[] = 'profile_picture = ?';
                        $params[] = $profile_picture;
                    }
                    
                    if ($password_hash) {
                        $update_fields[] = 'password = ?';
                        $params[] = $password_hash;
                    }
                    
                    $params[] = $user_id; // For WHERE clause
                    
                    $sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    
                    // Update session data
                    $_SESSION['user_name'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    
                    // Log activity
                    logActivity($user_id, 'profile_updated', 'User updated their profile');
                    
                    $message = 'Profil berhasil diperbarui!';
                    $message_type = 'success';
                    
                } catch (PDOException $e) {
                    $message = 'Gagal memperbarui profil! Silakan coba lagi.';
                    $message_type = 'error';
                }
            }
        }
    }
}

// Get current user data
$user = getCurrentUser();
if (!$user) {
    redirect('login.php');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css"> <!-- pakai style yang sama -->
    <title>Edit Profil - <?php echo SITE_NAME; ?></title>
    
    <style>
        /* Modern Edit Profile Styles */
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px 0;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin: 0 0 10px 0;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }
        
        .header p {
            color: #666;
            font-size: 1.1rem;
            margin: 0;
        }
        
        .message {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 12px;
            font-weight: 500;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease-out;
        }
        
        .message.success {
            background: linear-gradient(135deg, #00c851, #00b347);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .message.error {
            background: linear-gradient(135deg, #ff4444, #cc0000);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        form {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 20px;
        }
        
        .profile-picture-section {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            border-radius: 20px;
            border: 2px dashed rgba(102, 126, 234, 0.3);
        }
        
        .current-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .current-picture:hover {
            transform: scale(1.05);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .form-group:hover {
            transform: translateY(-2px);
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 1rem;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(5px);
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
            transform: translateY(-1px);
        }
        
        .form-group input:disabled {
            background: rgba(244, 244, 244, 0.8);
            color: #999;
            cursor: not-allowed;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-hint {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-button {
            display: inline-block;
            padding: 15px 25px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            text-align: center;
            width: 100%;
            border: none;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .file-input-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        .password-section {
            margin-top: 40px;
            padding: 30px;
            background: linear-gradient(135deg, rgba(118, 75, 162, 0.1), rgba(102, 126, 234, 0.1));
            border-radius: 15px;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }
        
        .password-section h3 {
            margin: 0 0 25px 0;
            color: #333;
            font-size: 1.3rem;
            text-align: center;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 0 15px;
            }
            
            form {
                padding: 25px;
            }
            
            .header {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
        }
        
        .btn {
            display: inline-block;
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            margin: 10px 10px 10px 0;
            min-width: 150px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.9);
            color: #667eea;
            border: 2px solid #667eea;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        form {
            animation: fadeIn 0.5s ease-out;
        }
        
        .form-group {
            animation: slideIn 0.5s ease-out;
            animation-fill-mode: both;
        }
        
        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .form-group:nth-child(3) { animation-delay: 0.3s; }
        .form-group:nth-child(4) { animation-delay: 0.4s; }
        .form-group:nth-child(5) { animation-delay: 0.5s; }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2, #667eea);
        }
        
        /* Loading state */
        .loading {
            position: relative;
            overflow: hidden;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            animation: loading 1.5s infinite;
        }
        
        @keyframes loading {
            0% {
                left: -100%;
            }
            100% {
                left: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✏️ Edit Profil</h1>
            <p>Perbarui informasi profil Anda</p>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php
        // Display flash messages
        $success_msg = getFlashMessage('success');
        $error_msg = getFlashMessage('error');
        if ($success_msg): ?>
            <div class="message success"><?php echo $success_msg; ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="message error"><?php echo $error_msg; ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="profile-picture-section">
                <img src="<?php echo $user['profile_picture'] ? 'uploads/profiles/' . htmlspecialchars($user['profile_picture']) : 'assets/default-avatar.jpg'; ?>" 
                     alt="Profile Picture" class="current-picture" id="preview-image">
                
                <div class="form-group">
                    <label>📷 Foto Profil</label>
                    <div class="file-input-wrapper">
                        <input type="file" name="profile_picture" accept="image/*" class="file-input" id="profile-picture-input">
                        <label for="profile-picture-input" class="file-input-button">
                            <i>📷</i> Pilih Foto Baru (Opsional)
                        </label>
                    </div>
                    <div class="form-hint">Format: JPG, PNG, GIF. Maksimal 5MB</div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="username">👤 Username</label>
                <input type="text" id="username" value="@<?php echo htmlspecialchars($user['username']); ?>" disabled>
                <div class="form-hint">Username tidak dapat diubah</div>
            </div>
            
            <div class="form-group">
                <label for="full_name">📝 Nama Lengkap *</label>
                <input type="text" id="full_name" name="full_name" 
                       value="<?php echo htmlspecialchars($user['full_name']); ?>" 
                       required maxlength="100" placeholder="Masukkan nama lengkap Anda">
            </div>
            
            <div class="form-group">
                <label for="email">📧 Email *</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($user['email']); ?>" 
                       required maxlength="100" placeholder="nama@email.com">
            </div>
            
            <div class="form-group">
                <label for="bio">💭 Bio</label>
                <textarea id="bio" name="bio" maxlength="500" 
                          placeholder="Ceritakan sedikit tentang diri Anda..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                <div class="form-hint">Maksimal 500 karakter</div>
            </div>
            
            <div class="password-section">
                <h3>🔒 Ubah Password (Opsional)</h3>
                
                <div class="form-group">
                    <label for="current_password">Password Saat Ini</label>
                    <input type="password" id="current_password" name="current_password" 
                           placeholder="Masukkan password saat ini">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="new_password">Password Baru</label>
                        <input type="password" id="new_password" name="new_password" 
                               placeholder="Password baru (min. 6 karakter)" minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Konfirmasi Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               placeholder="Konfirmasi password baru">
                    </div>
                </div>
                
                <div class="form-hint">💡 Kosongkan jika tidak ingin mengubah password</div>
            </div>
            
            <button type="submit" class="btn btn-primary">
                💾 Simpan Perubahan
            </button>
            
            <a href="profile.php" class="btn btn-secondary">
                Kembali 
            </a>
        </form>
        
    </div>

    <script>
        // Preview uploaded image
        document.getElementById('profile-picture-input').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file size
                if (file.size > 5 * 1024 * 1024) {
                    alert('Ukuran file terlalu besar! Maksimal 5MB.');
                    this.value = '';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Format file tidak didukung! Gunakan JPG, PNG, atau GIF.');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('preview-image').src = e.target.result;
                };
                reader.readAsDataURL(file);
                
                // Update file input button text
                const fileName = file.name.length > 20 ? file.name.substring(0, 20) + '...' : file.name;
                const button = document.querySelector('.file-input-button');
                button.innerHTML = `📷 ${fileName}`;
            }
        });
        
        // Password validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const currentPassword = document.getElementById('current_password');
        
        function validatePasswords() {
            // Check password match
            if (newPassword.value && confirmPassword.value) {
                if (newPassword.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Password tidak cocok');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            }
            
            // Require current password if new password is entered
            if (newPassword.value && !currentPassword.value) {
                currentPassword.setCustomValidity('Password saat ini diperlukan untuk mengubah password');
            } else {
                currentPassword.setCustomValidity('');
            }
        }
        
        newPassword.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);
        currentPassword.addEventListener('input', validatePasswords);
        
        // Bio character counter
        const bioTextarea = document.getElementById('bio');
        const bioHint = bioTextarea.parentNode.querySelector('.form-hint');
        
        bioTextarea.addEventListener('input', function() {
            const remaining = 500 - this.value.length;
            bioHint.textContent = `${remaining} karakter tersisa`;
            
            if (remaining < 50) {
                bioHint.style.color = '#e74c3c';
            } else {
                bioHint.style.color = '#666';
            }
        });
        
        // Form submission validation
        document.querySelector('form').addEventListener('submit', function(e) {
            validatePasswords();
            
            // Check if form is valid
            if (!this.checkValidity()) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            const submitBtn = document.querySelector('.btn-primary');
            submitBtn.innerHTML = '⏳ Menyimpan...';
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
        });
        
        // Auto-resize textarea
        bioTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        // Add smooth animations
        document.querySelectorAll('.form-group input, .form-group textarea').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentNode.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentNode.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>
