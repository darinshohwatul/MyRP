overflow-x: hidden;

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

//Responsive navbar
        function toggleMenu() {
        document.querySelector('.nav-links').classList.toggle('active');
        }

        