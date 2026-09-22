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

// Handle password change
$passwordMessage = '';
$passwordMessageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        // Direct form submission (fallback)
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $passwordMessage = 'All password fields are required.';
            $passwordMessageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $passwordMessage = 'New passwords do not match.';
            $passwordMessageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $passwordMessage = 'New password must be at least 8 characters long.';
            $passwordMessageType = 'error';
        } else {
            $verifyQuery = pg_query_params($conn,
                "SELECT password FROM users WHERE user_id = $1",
                [$userId]
            );
            $currentHash = pg_fetch_result($verifyQuery, 0, 0);
            
            if (password_verify($currentPassword, $currentHash)) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updatePasswordQuery = pg_query_params($conn,
                    "UPDATE users SET password = $1 WHERE user_id = $2",
                    [$newHash, $userId]
                );
                
                if ($updatePasswordQuery) {
                    $passwordMessage = 'Password changed successfully!';
                    $passwordMessageType = 'success';
                } else {
                    $passwordMessage = 'Failed to change password. Please try again.';
                    $passwordMessageType = 'error';
                }
            } else {
                $passwordMessage = 'Current password is incorrect.';
                $passwordMessageType = 'error';
            }
        }
    }
}
?>

<!-- SECURITY SETTINGS -->
<section id="security" class="section space-y-6" style="display: none;">
    <div class="rounded-2xl p-6 sm:p-10 lg:p-16 bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 text-white shadow-sm">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center">
                    <i class="ti ti-lock text-xl"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold">Security Settings</h2>
                    <p class="text-xs text-white/70">Manage your password and security preferences</p>
                </div>
            </div>
            <div class="flex items-center gap-2 text-xs text-white/80">
                <span class="flex items-center gap-1.5"><i class="ti ti-shield-check text-sm"></i>Protected</span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Password Change Form -->
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(245 158 11 / 0.1)">
                        <i class="ti ti-lock text-amber-500 dark:text-amber-400 text-lg"></i>
                    </div>
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Change Password</h3>
                </div>

                <?php if ($passwordMessage): ?>
                    <div class="mb-4 rounded-lg border <?= $passwordMessageType === 'success' ? 'border-green-500/20 bg-green-500/10' : 'border-red-500/20 bg-red-500/10' ?> px-4 py-3">
                        <p class="text-sm <?= $passwordMessageType === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' ?>">
                            <?= htmlspecialchars($passwordMessage) ?>
                        </p>
                    </div>
                <?php endif; ?>

                <form id="passwordForm" method="POST" action="" class="space-y-5">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-2">
                            Current Password
                        </label>
                        <div class="relative">
                            <i class="ti ti-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                            <input type="password" name="current_password" required
                                class="w-full rounded-lg border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-12 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-colors">
                            <button type="button" onclick="togglePasswordVisibility('current_password')" 
                                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                <i class="ti ti-eye text-base"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-2">
                            New Password
                        </label>
                        <div class="relative">
                            <i class="ti ti-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                            <input type="password" name="new_password" required minlength="8"
                                class="w-full rounded-lg border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-12 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-colors">
                            <button type="button" onclick="togglePasswordVisibility('new_password')" 
                                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                <i class="ti ti-eye text-base"></i>
                            </button>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Must be at least 8 characters long</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-2">
                            Confirm New Password
                        </label>
                        <div class="relative">
                            <i class="ti ti-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                            <input type="password" name="confirm_password" required minlength="8"
                                class="w-full rounded-lg border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-12 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-colors">
                            <button type="button" onclick="togglePasswordVisibility('confirm_password')" 
                                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                <i class="ti ti-eye text-base"></i>
                            </button>
                        </div>
                    </div>

                    <div class="pt-4 flex items-center gap-3">
                        <button type="submit"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-500 text-white px-6 py-3 text-sm font-semibold shadow-[0_0_20px_-8px_rgba(59,130,246,0.7)] hover:opacity-90 transition-opacity">
                            <i class="ti ti-lock-check text-base"></i>
                            Change Password
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

        <!-- Security Tips Card -->
        <div class="lg:col-span-1">
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                        <i class="ti ti-shield text-emerald-500 dark:text-emerald-400 text-lg"></i>
                    </div>
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Security Tips</h3>
                </div>

                <div class="space-y-4">
                    <div id="passwordStrengthBox" class="p-4 rounded-lg bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <div class="flex items-start gap-3">
                            <div id="passwordStrengthIcon" class="w-8 h-8 rounded-full bg-slate-200 dark:bg-white/10 flex items-center justify-center shrink-0">
                                <i class="ti ti-lock-open text-slate-400 dark:text-slate-500 text-sm"></i>
                            </div>
                            <div>
                                <p id="passwordStrengthTitle" class="text-sm font-medium text-slate-600 dark:text-slate-400">Strong Password</p>
                                <p id="passwordStrengthDesc" class="text-xs text-slate-500 dark:text-slate-500 mt-1">Use 8+ characters with numbers and symbols</p>
                            </div>
                        </div>
                    </div>

                    <ul class="space-y-3">
                        <li class="flex items-start gap-2">
                            <i class="ti ti-check text-emerald-500 dark:text-emerald-400 mt-0.5 text-sm"></i>
                            <span class="text-xs text-slate-600 dark:text-slate-400">Don't reuse passwords from other accounts</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="ti ti-check text-emerald-500 dark:text-emerald-400 mt-0.5 text-sm"></i>
                            <span class="text-xs text-slate-600 dark:text-slate-400">Change your password regularly</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="ti ti-check text-emerald-500 dark:text-emerald-400 mt-0.5 text-sm"></i>
                            <span class="text-xs text-slate-600 dark:text-slate-400">Never share your password with anyone</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="ti ti-check text-emerald-500 dark:text-emerald-400 mt-0.5 text-sm"></i>
                            <span class="text-xs text-slate-600 dark:text-slate-400">Use a password manager for security</span>
                        </li>
                    </ul>

                    <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                        <a href="#settings" onclick="goToSection('settings')" class="w-full inline-flex items-center justify-center gap-2 rounded-lg border border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 px-4 py-2.5 text-sm font-semibold hover:bg-blue-100 dark:hover:bg-blue-500/20 transition-colors">
                            <i class="ti ti-user text-base"></i>
                            Edit Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
function togglePasswordVisibility(inputId) {
    const input = document.querySelector(`input[name="${inputId}"]`);
    const button = input.nextElementSibling;
    const icon = button.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('ti-eye');
        icon.classList.add('ti-eye-off');
    } else {
        input.type = 'password';
        icon.classList.remove('ti-eye-off');
        icon.classList.add('ti-eye');
    }
}

// Password strength checker
function checkPasswordStrength(password) {
    let strength = 0;
    
    // Length check
    if (password.length >= 8) strength += 1;
    
    // Number check
    if (/\d/.test(password)) strength += 1;
    
    // Special character check
    if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 1;
    
    // Uppercase check
    if (/[A-Z]/.test(password)) strength += 1;
    
    return strength;
}

function updatePasswordStrengthIndicator(password) {
    const strengthBox = document.getElementById('passwordStrengthBox');
    const strengthIcon = document.getElementById('passwordStrengthIcon');
    const strengthTitle = document.getElementById('passwordStrengthTitle');
    const strengthDesc = document.getElementById('passwordStrengthDesc');
    
    if (!strengthBox || !strengthIcon || !strengthTitle || !strengthDesc) return;
    
    const strength = checkPasswordStrength(password);
    
    // Reset to default
    strengthBox.className = 'p-4 rounded-lg bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10';
    strengthIcon.className = 'w-8 h-8 rounded-full bg-slate-200 dark:bg-white/10 flex items-center justify-center shrink-0';
    strengthIcon.querySelector('i').className = 'ti ti-lock-open text-slate-400 dark:text-slate-500 text-sm';
    strengthTitle.className = 'text-sm font-medium text-slate-600 dark:text-slate-400';
    strengthDesc.className = 'text-xs text-slate-500 dark:text-slate-500 mt-1';
    
    if (password.length === 0) {
        // Default state
        return;
    }
    
    if (strength >= 2) {
        // Good password - green styling (lowered threshold)
        strengthBox.className = 'p-4 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20';
        strengthIcon.className = 'w-8 h-8 rounded-full bg-emerald-500/20 flex items-center justify-center shrink-0';
        strengthIcon.querySelector('i').className = 'ti ti-lock-check text-emerald-600 dark:text-emerald-400 text-sm';
        strengthTitle.className = 'text-sm font-medium text-emerald-900 dark:text-emerald-300';
        strengthDesc.className = 'text-xs text-emerald-700 dark:text-emerald-200 mt-1';
        strengthDesc.textContent = 'Password meets strength requirements';
    } else if (strength >= 1) {
        // Medium strength - yellow styling
        strengthBox.className = 'p-4 rounded-lg bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20';
        strengthIcon.className = 'w-8 h-8 rounded-full bg-amber-500/20 flex items-center justify-center shrink-0';
        strengthIcon.querySelector('i').className = 'ti ti-lock text-amber-600 dark:text-amber-400 text-sm';
        strengthTitle.className = 'text-sm font-medium text-amber-900 dark:text-amber-300';
        strengthDesc.className = 'text-xs text-amber-700 dark:text-amber-200 mt-1';
        strengthDesc.textContent = 'Add numbers or symbols for stronger password';
    } else {
        // Weak password - red styling
        strengthBox.className = 'p-4 rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20';
        strengthIcon.className = 'w-8 h-8 rounded-full bg-red-500/20 flex items-center justify-center shrink-0';
        strengthIcon.querySelector('i').className = 'ti ti-lock-open text-red-600 dark:text-red-400 text-sm';
        strengthTitle.className = 'text-sm font-medium text-red-900 dark:text-red-300';
        strengthDesc.className = 'text-xs text-red-700 dark:text-red-200 mt-1';
        strengthDesc.textContent = 'Password needs to be stronger';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const newPasswordInput = document.querySelector('input[name="new_password"]');
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', function() {
            updatePasswordStrengthIndicator(this.value);
        });
    }
});
</script>