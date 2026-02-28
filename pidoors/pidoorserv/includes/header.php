<?php
/**
 * Header Template
 * PiDoors Access Control System
 */

$config = include(__DIR__ . '/config.php');
require_once __DIR__ . '/security.php';
require_once $config['apppath'] . 'database/db_connection.php';

// Set timezone
date_default_timezone_set($config['timezone']);

// Start secure session
secure_session_start($config);

// Generate CSRF token for forms
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">
    <title>PiDoors - <?php echo htmlspecialchars($title ?? 'Access Control'); ?></title>

    <!-- Security Headers via Meta -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($config['url']); ?>/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($config['url']); ?>/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($config['url']); ?>/css/dashboard.css?v=3">
</head>
<body>
    <nav class="navbar navbar-light bg-light">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo htmlspecialchars($config['url']); ?>/">
                PiDoors
            </a>

            <?php if (is_logged_in()): ?>
                <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar" aria-controls="sidebar" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            <?php endif; ?>

            <div class="d-flex align-items-center">
                <?php
                if (isset($_GET['success'])) {
                    echo '<div class="alert alert-success alert-dismissible fade show mb-0 me-2 py-1 px-2 small" role="alert">'
                        . htmlspecialchars($_GET['success'])
                        . '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
                }
                if (isset($_GET['error'])) {
                    echo '<div class="alert alert-danger alert-dismissible fade show mb-0 me-2 py-1 px-2 small" role="alert">'
                        . htmlspecialchars($_GET['error'])
                        . '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
                }
                if (isset($_GET['barmess'])) {
                    echo '<div class="alert alert-info alert-dismissible fade show mb-0 me-2 py-1 px-2 small" role="alert">'
                        . htmlspecialchars($_GET['barmess'])
                        . '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
                }
                ?>

                <div class="dropdown">
                    <?php if (is_logged_in()): ?>
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php echo htmlspecialchars($_SESSION['email']); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($config['url']); ?>/users/profile.php">Profile</a></li>
                            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($config['url']); ?>/settings.php">Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($config['url']); ?>/users/logout.php">Sign out</a></li>
                        </ul>
                    <?php else: ?>
                        <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars($config['url']); ?>/users/login.php">Log in</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

            <?php if (is_logged_in()): ?>
                <nav id="sidebar" class="sidebar collapse d-md-block">
                    <div class="position-sticky">
                        <ul class="nav flex-column">
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                                    Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'doors.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/doors.php">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                                    Doors
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'cards.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/cards.php">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                                    Cards
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'logs.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/logs.php">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                                    Access Logs
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'schedules.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/schedules.php">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    Schedules
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'groups.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/groups.php">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                    Access Groups
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'holidays.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/holidays.php">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                    Holidays
                                </a>
                            </li>
                        </ul>

                        <?php if (is_admin()): ?>
                            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                                <span>Admin Tools</span>
                            </h6>
                            <ul class="nav flex-column mb-2">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'view_users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/users/view_users.php">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                                        Panel Users
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'adduser.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/users/adduser.php">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                                        Add Users
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'audit.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/audit.php">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                        Audit Log
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/settings.php">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                                        Settings
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/backup.php">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        Backup
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'importcards.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($config['url']); ?>/importcards.php">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                        Import Cards
                                    </a>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>

            <main role="main" class="main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><?php echo htmlspecialchars($title ?? 'Dashboard'); ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0" id="page-actions">
                    </div>
                </div>
