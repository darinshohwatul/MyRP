<?php
require_once 'config.php';

// Redirect to feed if already logged in
if (isLoggedIn()) {
    redirect('feed.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyRP - Social Media Platform</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 90%;
            max-width: 900px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 500px;
        }
        
        .welcome-section {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        
        .logo {
            font-size: 3rem;
            font-weight: bold;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .tagline {
            font-size: 1.2rem;
            margin-bottom: 30px;
            opacity: 0.9;
        }
        
        .features {
            list-style: none;
            text-align: left;
        }
        
        .features li {
            margin: 10px 0;
            display: flex;
            align-items: center;
        }
        
        .features i {
            margin-right: 10px;
            width: 20px;
        }
        
        .auth-section {
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .auth-buttons {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-secondary {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .or-divider {
            text-align: center;
            margin: 20px 0;
            color: #666;
            position: relative;
        }
        
        .or-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #ddd;
            z-index: 1;
        }
        
        .or-divider span {
            background: white;
            padding: 0 20px;
            position: relative;
            z-index: 2;
        }
        
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
                margin: 20px;
            }
            
            .welcome-section {
                padding: 40px 20px;
            }
            
            .auth-section {
                padding: 40px 20px;
            }
            
            .logo {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="welcome-section">
            <div class="logo">MyRP</div>
            <div class="tagline">Share Your Life, Connect With Friends</div>
            <ul class="features">
                <li><i class="fas fa-camera"></i> Share photos and moments</li>
                <li><i class="fas fa-heart"></i> Like and comment on posts</li>
                <li><i class="fas fa-users"></i> Follow friends and creators</li>
                <li><i class="fas fa-hashtag"></i> Discover trending hashtags</li>
                <li><i class="fas fa-comments"></i> Private messaging</li>
                <li><i class="fas fa-search"></i> Find new connections</li>
            </ul>
        </div>
        
        <div class="auth-section">
            <h2 style="margin-bottom: 30px; color: #333; text-align: center;">Get Started</h2>
            <div class="auth-buttons">
                <a href="register.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Create New Account
                </a>
                <div class="or-divider">
                    <span>or</span>
                </div>
                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-sign-in-alt"></i> Sign In to Your Account
                </a>
            </div>
            <p style="text-align: center; margin-top: 30px; color: #666; font-size: 0.9rem;">
                Join thousands of users sharing their stories on MyRP
            </p>
        </div>
    </div>
</body>
</html>