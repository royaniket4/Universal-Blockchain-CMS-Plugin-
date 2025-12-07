# Universal Blockchain CMS Plugin
WordPress plugin for MetaMask login and post integrity verification.
# Blockchain CMS Plugin 🔐📝

**MetaMask wallet login + post content integrity for WordPress.**
No smart contracts! Self-hosted, secure, open-source solution for passwordless authentication and tamperproof post verification.

***

## 📒 Table of Contents

- [Features ✨](#features-)
- [Technologies Used 🛠️](#technologies-used-)
- [Folder Structure 📁](#folder-structure-)
- [Requirements ⚙️](#requirements-)
- [Installation on Windows 🪟 (Step-by-step)](#installation-on-windows--step-by-step-)
- [Setting Up Pages 🧩](#setting-up-pages-)
- [Usage 🚀](#usage-)
- [Screenshots 📸](#screenshots-)
- [Troubleshooting 🏥](#troubleshooting-)
- [Security 🔒](#security-)
- [Contributing 🤝](#contributing-)
- [License 📝](#license-)
- [Support 💬](#support-)

***

## Features ✨

- 🔏 **MetaMask Login/Signup:** Passwordless authentication using Ethereum wallet signature
- 🟢 **Content Integrity:** Detect post changes using SHA-256 and Keccak-256 hashing; clear "Verified"/"Changed" badges
- 📊 **Modern SPA Dashboard:** Create, view, and manage posts in one place
- 🔗 **REST API Endpoints:** Secure routes for login, registration, post management, and verification
- 🏠 **Self-hosted \& No Contracts:** Works on your server, no third-party APIs, NO smart contracts
- 🖥️ **Cross-Platform:** Compatible with Windows, Linux, macOS (XAMPP/WAMP/MAMP/LAMP)

***

## Technologies Used 🛠️

- **PHP** (WordPress Plugins \& REST API) 🐘
- **WordPress** (CMS framework) 📝
- **JavaScript** (Frontend logic, SPA, MetaMask integration) ⚡
- **CSS** (Custom styles for dashboard and login UI) 🎨
- **MetaMask** (Browser wallet for secure authentication) 🦊
- **SHA-256, Keccak-256 Hashing** (Content tamperproofing) 🔑
- **MySQL/MariaDB** (Database for post and hash storage) 🗄️
- **XAMPP / WAMP / LAMP / MAMP** (Server environments) 🌐

***

## Folder Structure 📁

```
blockchain-cms-plugin/
├── assets/
│   ├── css/
│   │   └── enhanced-bcp-styles.css
│   └── js/
│       ├── bcp-dashboard-lite.js
│       ├── bcp-register.js
│       ├── bcp-wallet-login.js
│       ├── blockchain-cms-interface.js
│       └── dashboard.js
├── includes/
│   ├── admin-hash-monitor.php
│   ├── common.php
│   ├── content-hash.php
│   ├── rest-auth.php
│   ├── rest-meta.php
│   ├── rest-posts.php
│   ├── rest-user.php
│   └── verify-badge.php
├── src/
│   └── KeccakHelper.php
├── templates/
│   ├── dashboard-light-shortcode.php
│   ├── dashboard.php
│   ├── login-page.php
│   └── signup-page.php
├── vendor/
│   └── (composer dependencies if required)
└── blockchain-cms-plugin.php
```


***

## Requirements ⚙️

- WordPress 5.8+
- PHP 7.4+
- Apache/MySQL (XAMPP, WAMP, LAMP, MAMP)
- MetaMask browser extension

***

## Installation on Windows 🪟 (Step-by-step)

1. **Install XAMPP**
    - Download [XAMPP](https://www.apachefriends.org/) and install to `C:\xampp`
    - Start Apache \& MySQL from XAMPP control panel
2. **Set up WordPress**
    - Download [WordPress](https://wordpress.org/download/)
    - Extract to `C:\xampp\htdocs\your-site`
    - Create DB with phpMyAdmin (`localhost/phpmyadmin`)
    - Install via `localhost/your-site`
3. **Install Plugin**
    - Copy `blockchain-cms-plugin` folder to
`C:\xampp\htdocs\your-site\wp-content\plugins\`
    - Activate in `http://localhost/your-site/wp-admin` → Plugins
4. **Fix Upload Permissions (if needed)**
    - Right-click `uploads`, Properties > Security > Edit > Allow “Modify” for your user

***

## Setting Up Pages 🧩

- **Login:** `[bcp_login]`
- **Signup:** `[bcp_signup]`
- **Dashboard:** `[bcp_user_dashboard]`
- **Post Badge:** `[bcp_verify_badge id="POST_ID"]`

***

## Usage 🚀

1. Open Login page, click **Connect MetaMask**
2. Sign the message in MetaMask for authentication
3. Access Dashboard to create/view posts and check “Verified”/“Changed” badges
4. Each post’s hash is auto-generated and status is visible to users

***

## Screenshots 📸

- **Login Page**
- **Signup Page**
- **User Dashboard**
- **Content Verification Badge**
- **Database Hashes Example**

> <img width="964" height="500" alt="image" src="https://github.com/user-attachments/assets/920b16ed-cbe1-496c-b9b3-f617d699a881" />
<img width="964" height="479" alt="image" src="https://github.com/user-attachments/assets/39e12b48-c6b4-4934-a9ab-f91b16d00659" />
<img width="964" height="549" alt="image" src="https://github.com/user-attachments/assets/a8baccb4-310d-4189-8240-18ca89f6329f" />
<img width="962" height="454" alt="image" src="https://github.com/user-attachments/assets/73b304b7-9451-495f-8803-3e5022f7299f" />



***

## Troubleshooting 🏥

- **MetaMask not detected:** Ensure extension is installed and unlocked
- **Upload errors:** Set write permissions (see above)
- **REST errors:** Enable permalinks under WP Settings > Permalinks

***

## Security 🔒

- No passwords—wallet signature login!
- One-time nonce for each login (prevents replay)
- Hash checks on every post load/save
- REST endpoints protected with WP Nonce

***

## Contributing 🤝

Fork the repo, open PRs, or request features in Issues


