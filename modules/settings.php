<?php
require_once __DIR__ . '/../config/ftms_db.php';

// Get current user information (session is already started in index.php)
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header("Location: ../auth/login.php");
    exit();
}

// Fetch user data
$userQuery = pg_query_params($conn, 
    "SELECT user_id, username, email, first_name, last_name, role FROM users WHERE user_id = $1", 
    [$userId]
);
$userData = pg_fetch_assoc($userQuery);

// Handle form submissions (for fallback/API integration)
$profileMessage = '';
$profileMessageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        // Direct form submission (fallback)
        $firstName = pg_escape_string($_POST['first_name'] ?? '');
        $lastName = pg_escape_string($_POST['last_name'] ?? '');
        $email = pg_escape_string($_POST['email'] ?? '');
        
        $updateQuery = pg_query_params($conn,
            "UPDATE users SET first_name = $1, last_name = $2, email = $3 WHERE user_id = $4",
            [$firstName, $lastName, $email, $userId]
        );
        
        if ($updateQuery) {
            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            $_SESSION['email'] = $email;
            
            $profileMessage = 'Profile updated successfully!';
            $profileMessageType = 'success';
            
            $userQuery = pg_query_params($conn, 
                "SELECT user_id, username, email, first_name, last_name, role FROM users WHERE user_id = $1", 
                [$userId]
            );
            $userData = pg_fetch_assoc($userQuery);
        } else {
            $profileMessage = 'Failed to update profile. Please try again.';
            $profileMessageType = 'error';
        }
    }
}
?>

<!-- PROFILE SETTINGS -->
<section id="settings" class="section space-y-6" style="display: none;">
    <div class="rounded-2xl p-6 sm:p-10 lg:p-16 bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 text-white shadow-sm">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center">
                    <i class="ti ti-user text-xl"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold">Profile Settings</h2>
                    <p class="text-xs text-white/70">Manage your personal information</p>
                </div>
            </div>
            <div class="flex items-center gap-2 text-xs text-white/80">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>Account Active</span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Profile Form -->
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                        <i class="ti ti-user text-blue-500 dark:text-blue-400 text-lg"></i>
                    </div>
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Personal Information</h3>
                </div>

                <?php if ($profileMessage): ?>
                    <div class="mb-4 rounded-lg border <?= $profileMessageType === 'success' ? 'border-green-500/20 bg-green-500/10' : 'border-red-500/20 bg-red-500/10' ?> px-4 py-3">
                        <p class="text-sm <?= $profileMessageType === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' ?>">
                            <?= htmlspecialchars($profileMessage) ?>
                        </p>
                    </div>
                <?php endif; ?>

                <form id="profileForm" method="POST" action="" class="space-y-5">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-2">
                                First Name
                            </label>
                            <div class="relative">
                                <i class="ti ti-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                                <input type="text" name="first_name" value="<?= htmlspecialchars($userData['first_name'] ?? '') ?>" required
                                    class="w-full rounded-lg border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-4 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-2">
                                Last Name
                            </label>
                            <div class="relative">
                                <i class="ti ti-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                                <input type="text" name="last_name" value="<?= htmlspecialchars($userData['last_name'] ?? '') ?>" required
                                    class="w-full rounded-lg border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-4 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-2">
                            Email Address
                        </label>
                        <div class="relative">
                            <i class="ti ti-mail absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                            <input type="email" name="email" value="<?= htmlspecialchars($userData['email'] ?? '') ?>" required
                                class="w-full rounded-lg border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-4 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
                        </div>
                    </div>

                    <div class="pt-4 flex items-center gap-3">
                        <button type="submit"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-500 text-white px-6 py-3 text-sm font-semibold shadow-[0_0_20px_-8px_rgba(59,130,246,0.7)] hover:opacity-90 transition-opacity">
                            <i class="ti ti-device-floppy text-base"></i>
                            Update Profile
                        </button>
                        <button type="button" onclick="location.reload()"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 dark:border-white/10 bg-slate-100 dark:bg-white/5 text-slate-700 dark:text-slate-200 px-6 py-3 text-sm font-semibold hover:bg-slate-200 dark:hover:bg-white/10 transition-colors">
                            <i class="ti ti-refresh text-base"></i>
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Account Info Card -->
        <div class="lg:col-span-1">
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                        <i class="ti ti-shield text-emerald-500 dark:text-emerald-400 text-lg"></i>
                    </div>
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Account Information</h3>
                </div>

                <div class="space-y-4">
                    <div class="p-4 rounded-lg bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full bg-linear-to-br from-sidebar-blue-900 via-sidebar-blue-300 to-sidebar-blue-600 text-white text-xs flex items-center justify-center font-medium">
                                <?= strtoupper(substr($userData['first_name'] ?? 'U', 0, 1)) ?>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($userData['first_name'] ?? '') ?> <?= htmlspecialchars($userData['last_name'] ?? '') ?></p>
                                <p class="text-xs text-slate-500 dark:text-slate-400"><?= htmlspecialchars($userData['email'] ?? '') ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 dark:bg-white/5">
                            <div class="flex items-center gap-2">
                                <i class="ti ti-user text-slate-400 dark:text-slate-500 text-sm"></i>
                                <span class="text-xs text-slate-600 dark:text-slate-400">Username</span>
                            </div>
                            <span class="text-xs font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($userData['username'] ?? '') ?></span>
                        </div>
                        <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 dark:bg-white/5">
                            <div class="flex items-center gap-2">
                                <i class="ti ti-badge text-slate-400 dark:text-slate-500 text-sm"></i>
                                <span class="text-xs text-slate-600 dark:text-slate-400">Role</span>
                            </div>
                            <span class="text-xs font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($userData['role'] ?? '') ?></span>
                        </div>
                        <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 dark:bg-white/5">
                            <div class="flex items-center gap-2">
                                <i class="ti ti-id text-slate-400 dark:text-slate-500 text-sm"></i>
                                <span class="text-xs text-slate-600 dark:text-slate-400">User ID</span>
                            </div>
                            <span class="text-xs font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($userData['user_id'] ?? '') ?></span>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                        <a href="#security" onclick="goToSection('security')" class="w-full inline-flex items-center justify-center gap-2 rounded-lg border border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 px-4 py-2.5 text-sm font-semibold hover:bg-blue-100 dark:hover:bg-blue-500/20 transition-colors">
                            <i class="ti ti-lock text-base"></i>
                            Change Password
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
