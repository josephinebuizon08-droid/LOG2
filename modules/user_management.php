<?php
    require_once __DIR__ . '/../config/ftms_db.php';
    require_once __DIR__ . '/UI_helpers.php';
    require_once __DIR__ . '/../auth/rbac.php';

    $user_message = '';
    $user_message_type = 'success'; // 'success' | 'error'

    $VALID_ROLES = ['Administrator', 'Fleet Manager', 'driver', 'Customer'];

    // ================= ADD USER =================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
        require_admin();

        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $confirm    = $_POST['confirm_password'] ?? '';
        $email      = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $role       = trim($_POST['role'] ?? '');

        if ($username === '' || $password === '' || $email === '' || $first_name === '' || $last_name === '' || $role === '') {
            $user_message = 'Please complete all required fields.';
            $user_message_type = 'error';
        } elseif ($password !== $confirm) {
            $user_message = 'Passwords do not match.';
            $user_message_type = 'error';
        } elseif (strlen($password) < 8) {
            $user_message = 'Password must be at least 8 characters.';
            $user_message_type = 'error';
        } elseif (!in_array($role, $VALID_ROLES, true)) {
            $user_message = 'Please select a valid role.';
            $user_message_type = 'error';
        } else {
            $check = pg_query_params($conn, "SELECT user_id FROM users WHERE username = $1 OR email = $2", [$username, $email]);

            if ($check && pg_num_rows($check) > 0) {
                $user_message = 'A user with that username or email already exists.';
                $user_message_type = 'error';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);

                $res = pg_query_params(
                    $conn,
                    "INSERT INTO users (username, password, email, role, status, first_name, last_name)
                     VALUES ($1, $2, $3, $4, 'active', $5, $6)",
                    [$username, $hashed, $email, $role, $first_name, $last_name]
                );

                if ($res) {
                    // Get the newly created user_id
                    $new_user_id = pg_fetch_result(pg_query($conn, "SELECT lastval()"), 0, 0);
                    
                    // If role is 'driver', automatically create a driver record
                    if (strtolower($role) === 'driver' && $new_user_id) {
                        $driver_res = pg_query_params(
                            $conn,
                            "INSERT INTO drivers (user_id, first_name, last_name, email, status)
                             VALUES ($1, $2, $3, $4, 'Active')",
                            [$new_user_id, $first_name, $last_name, $email]
                        );
                        
                        if ($driver_res) {
                            $user_message = 'User account and driver record created successfully.';
                        } else {
                            $user_message = 'User account created, but driver record creation failed: ' . (pg_last_error($conn) ?: 'unknown error.');
                            $user_message_type = 'error';
                        }
                    } else {
                        $user_message = 'User account created successfully.';
                    }
                } else {
                    $user_message = 'Unable to create user: ' . (pg_last_error($conn) ?: 'unknown error.');
                    $user_message_type = 'error';
                }
            }
        }
    }

    // ================= EDIT USER =================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
        require_admin();

        $edit_user_id   = (int) ($_POST['edit_user_id'] ?? 0);
        $username       = trim($_POST['edit_username'] ?? '');
        $email          = trim($_POST['edit_email'] ?? '');
        $first_name     = trim($_POST['edit_first_name'] ?? '');
        $last_name      = trim($_POST['edit_last_name'] ?? '');
        $role           = trim($_POST['edit_role'] ?? '');
        $new_password   = $_POST['edit_password'] ?? '';

        if ($edit_user_id <= 0 || $username === '' || $email === '' || $first_name === '' || $last_name === '' || $role === '') {
            $user_message = 'Please complete all required fields.';
            $user_message_type = 'error';
        } elseif (!in_array($role, $VALID_ROLES, true)) {
            $user_message = 'Please select a valid role.';
            $user_message_type = 'error';
        } elseif ($new_password !== '' && strlen($new_password) < 8) {
            $user_message = 'New password must be at least 8 characters.';
            $user_message_type = 'error';
        } else {
            $dupe = pg_query_params(
                $conn,
                "SELECT user_id FROM users WHERE (username = $1 OR email = $2) AND user_id <> $3",
                [$username, $email, $edit_user_id]
            );

            if ($dupe && pg_num_rows($dupe) > 0) {
                $user_message = 'Another user already uses that username or email.';
                $user_message_type = 'error';
            } else {
                // Get current role before update
                $oldRoleCheck = pg_query_params($conn, "SELECT role FROM users WHERE user_id = $1", [$edit_user_id]);
                $oldRole = $oldRoleCheck ? (pg_fetch_assoc($oldRoleCheck)['role'] ?? '') : '';
                
                if ($new_password !== '') {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $res = pg_query_params(
                        $conn,
                        "UPDATE users
                         SET username = $1, email = $2, first_name = $3, last_name = $4,
                             role = $5, password = $6
                         WHERE user_id = $7",
                        [$username, $email, $first_name, $last_name, $role, $hashed, $edit_user_id]
                    );
                } else {
                    $res = pg_query_params(
                        $conn,
                        "UPDATE users
                         SET username = $1, email = $2, first_name = $3, last_name = $4,
                             role = $5
                         WHERE user_id = $6",
                        [$username, $email, $first_name, $last_name, $role, $edit_user_id]
                    );
                }

                if ($res) {
                    // Handle role change to driver
                    if (strtolower($oldRole) !== 'driver' && strtolower($role) === 'driver') {
                        // Role changed to driver - create driver record
                        $existingDriver = pg_query_params($conn, "SELECT driver_id FROM drivers WHERE user_id = $1", [$edit_user_id]);
                        if (!$existingDriver || pg_num_rows($existingDriver) === 0) {
                            $driver_res = pg_query_params(
                                $conn,
                                "INSERT INTO drivers (user_id, first_name, last_name, email, status)
                                 VALUES ($1, $2, $3, $4, 'Active')",
                                [$edit_user_id, $first_name, $last_name, $email]
                            );
                            if ($driver_res) {
                                $user_message = 'User account updated and driver record created successfully.';
                            } else {
                                $user_message = 'User account updated, but driver record creation failed: ' . (pg_last_error($conn) ?: 'unknown error.');
                                $user_message_type = 'error';
                            }
                        } else {
                            $user_message = 'User account updated successfully.';
                        }
                    } elseif (strtolower($oldRole) === 'driver' && strtolower($role) !== 'driver') {
                        // Role changed from driver - remove driver record
                        pg_query_params($conn, "DELETE FROM drivers WHERE user_id = $1", [$edit_user_id]);
                        $user_message = 'User account updated and driver record removed.';
                    } else {
                        $user_message = 'User account updated successfully.';
                    }

                    // Keep the session in sync if the admin edited their own account.
                    if ((int) ($_SESSION['user_id'] ?? 0) === $edit_user_id) {
                        $_SESSION['username']   = $username;
                        $_SESSION['first_name'] = $first_name;
                        $_SESSION['last_name']  = $last_name;
                        $_SESSION['role']       = $role;
                    }
                } else {
                    $user_message = 'Unable to update user: ' . (pg_last_error($conn) ?: 'unknown error.');
                    $user_message_type = 'error';
                }
            }
        }
    }

    // ================= TOGGLE STATUS (activate / deactivate) =================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_status'])) {
        require_admin();

        $toggle_user_id = (int) ($_POST['toggle_user_id'] ?? 0);
        $new_status     = ($_POST['new_status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($toggle_user_id === (int) ($_SESSION['user_id'] ?? 0) && $new_status === 'inactive') {
            $user_message = 'You cannot deactivate your own account.';
            $user_message_type = 'error';
        } elseif ($toggle_user_id > 0) {
            $res = pg_query_params($conn, "UPDATE users SET status = $1 WHERE user_id = $2", [$new_status, $toggle_user_id]);

            if ($res) {
                $user_message = $new_status === 'active' ? 'User account activated.' : 'User account deactivated.';
            } else {
                $user_message = 'Unable to update user status.';
                $user_message_type = 'error';
            }
        }
    }

    // ================= DELETE USER =================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
        require_admin();

        $delete_user_id = (int) ($_POST['delete_user_id'] ?? 0);

        if ($delete_user_id === (int) ($_SESSION['user_id'] ?? 0)) {
            $user_message = 'You cannot delete your own account.';
            $user_message_type = 'error';
        } elseif ($delete_user_id > 0) {
            // Guard against removing the last remaining Administrator.
            $roleCheck = pg_query_params($conn, "SELECT role FROM users WHERE user_id = $1", [$delete_user_id]);
            $targetRole = $roleCheck ? (pg_fetch_assoc($roleCheck)['role'] ?? '') : '';

            if (strtolower($targetRole) === 'administrator') {
                $adminCount = (int) pg_fetch_result(
                    pg_query($conn, "SELECT COUNT(*) FROM users WHERE LOWER(role) = 'administrator'"),
                    0,
                    0
                );

                if ($adminCount <= 1) {
                    $user_message = 'You cannot delete the last remaining Administrator account.';
                    $user_message_type = 'error';
                }
            }

            if ($user_message === '') {
                // Delete associated driver record if the user is a driver
                if (strtolower($targetRole) === 'driver') {
                    pg_query_params($conn, "DELETE FROM drivers WHERE user_id = $1", [$delete_user_id]);
                }
                
                $res = pg_query_params($conn, "DELETE FROM users WHERE user_id = $1", [$delete_user_id]);

                if ($res) {
                    $user_message = 'User account deleted.';
                } else {
                    $user_message = 'Unable to delete user: ' . (pg_last_error($conn) ?: 'unknown error.');
                    $user_message_type = 'error';
                }
            }
        }
    }

    // ================= STATS =================
    $totalUsers    = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM users"), 0, 0);
    $activeUsers   = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM users WHERE LOWER(status) = 'active'"), 0, 0);
    $adminUsers    = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM users WHERE LOWER(role) = 'administrator'"), 0, 0);
    $inactiveUsers = $totalUsers - $activeUsers;

    // ================= USERS LIST =================
    $usersRes = pg_query($conn, "
        SELECT user_id, username, email, role, status, create_at, first_name, last_name
        FROM users
        ORDER BY user_id ASC
    ");
    $users = [];
    if ($usersRes) {
        while ($row = pg_fetch_assoc($usersRes)) {
            $users[] = $row;
        }
    }
?>

<section id="users" class="section space-y-4">

    <?php if ($user_message !== ''): ?>
        <div class="rounded-lg px-4 py-3 text-sm <?= $user_message_type === 'error'
            ? 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20'
            : 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' ?>">
            <?= htmlspecialchars($user_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!is_admin()): ?>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-10 text-center">
            <i class="ti ti-lock text-3xl text-slate-400"></i>
            <h2 class="text-lg font-semibold text-ink-900 dark:text-white mt-3">Access Restricted</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Only Administrators can view and manage user accounts.
            </p>
        </div>

    <?php else: ?>

        <div class="rounded-2xl p-6 sm:p-10 lg:p-16 bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 text-white shadow-sm">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center">
                        <i class="ti ti-user text-xl"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold">User Management</h2>
                        <p class="text-xs text-white/70">Manage users account who can access the system</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-xs text-white/80">
                    <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>Account Active</span>
                </div>
            </div>
        </div>
        <!-- ================= STATS ================= -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Total Users</p>
                        <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= $totalUsers ?></p>
                        <p class="text-xs text-slate-400 mt-1">All accounts</p>
                    </div>
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                        <i class="ti ti-users text-blue-500 dark:text-blue-400 text-lg"></i>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Active</p>
                        <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= $activeUsers ?></p>
                        <p class="text-xs text-slate-400 mt-1">Can log in</p>
                    </div>
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                        <i class="ti ti-user-check text-emerald-500 dark:text-emerald-400 text-lg"></i>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Inactive</p>
                        <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= $inactiveUsers ?></p>
                        <p class="text-xs text-slate-400 mt-1">Deactivated</p>
                    </div>
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(148 163 184 / 0.1)">
                        <i class="ti ti-user-off text-slate-500 dark:text-slate-400 text-lg"></i>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Administrators</p>
                        <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= $adminUsers ?></p>
                        <p class="text-xs text-slate-400 mt-1">Full access</p>
                    </div>
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(249 115 22 / 0.1)">
                        <i class="ti ti-shield-lock text-orange-500 dark:text-orange-400 text-lg"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- ================= USERS TABLE ================= -->
        <!--
            Plain scoped CSS instead of Tailwind utility classes for the
            table's layout/overflow behavior. This project's Tailwind build
            (src/output.css) is a stale, pre-compiled file that only contains
            utilities already used elsewhere -- classes like overflow-x-auto,
            table-fixed, or any sm:/md:/lg: breakpoint variant are NOT in it,
            so they render as no-ops. Writing the rules directly here
            guarantees the table scrolls and truncates correctly regardless
            of that build's state.

            Same story for row hover: hover:bg-slate-50 (a single, non-combined
            utility) was already in the compiled CSS and worked, but the
            combined variant dark:hover:bg-slate-800/40 was not, so in dark
            mode a hovered row fell back to the light-mode slate-50 background
            -- a pale row with unreadable dark text sitting in an otherwise
            dark table. Handled with plain CSS below instead.
        -->
        <style>
            #users .users-table-wrap {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            #users .users-table {
                width: 100%;
                min-width: 760px; /* forces horizontal scroll on narrow screens instead of squeezing columns */
                table-layout: fixed;
                border-collapse: collapse;
            }
            #users .users-table col.col-id       { width: 9%; }
            #users .users-table col.col-name     { width: 17%; }
            #users .users-table col.col-username { width: 18%; }
            #users .users-table col.col-email    { width: 20%; }
            #users .users-table col.col-role     { width: 12%; }
            #users .users-table col.col-status   { width: 10%; }
            #users .users-table col.col-actions  { width: 14%; }

            #users .users-table th,
            #users .users-table td {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            #users .users-table td.cell-actions,
            #users .users-table th.cell-actions {
                overflow: visible; /* let the row of icon buttons render fully, never clipped */
            }

            #users .users-table tbody tr {
                transition: background-color 0.12s ease;
            }
            #users .users-table tbody tr:hover {
                background-color: rgb(248 250 252); /* slate-50 */
            }
            .dark #users .users-table tbody tr:hover {
                background-color: rgb(30 41 59 / 0.4); /* slate-800/40 */
            }

            @media (max-width: 640px) {
                #users .users-table th,
                #users .users-table td {
                    padding-left: 12px;
                    padding-right: 12px;
                    font-size: 0.8125rem;
                }
            }

            /* Password show/hide toggle button positioning */
            #users .password-field-wrap {
                position: relative;
            }
            #users .password-field-wrap input {
                padding-right: 2.5rem;
            }
            #users .password-toggle-btn {
                position: absolute;
                top: 0;
                bottom: 0;
                right: 0;
                display: flex;
                align-items: center;
                padding: 0 0.75rem;
                color: rgb(148 163 184); /* slate-400 */
                background: transparent;
                border: 0;
                cursor: pointer;
            }
            #users .password-toggle-btn:hover {
                color: rgb(71 85 105); /* slate-600 */
            }
        </style>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">

            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-slate-200 dark:border-slate-800">
                <div>
                    <h2 class="text-base font-semibold text-ink-900 dark:text-white">User Accounts</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Manage who can access this system and what they can do.</p>
                </div>

                <button type="button" onclick="openUserModal()"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium">
                    <i class="ti ti-user-plus text-lg"></i>
                    Add User
                </button>
            </div>

            <div class="users-table-wrap">
                <table class="users-table text-sm dark:bg-slate-900">
                    <colgroup>
                        <col class="col-id">
                        <col class="col-name">
                        <col class="col-username">
                        <col class="col-email">
                        <col class="col-role">
                        <col class="col-status">
                        <col class="col-actions">
                    </colgroup>
                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="text-left font-medium px-6 py-3">ID</th>
                            <th class="text-left font-medium px-6 py-3">Name</th>
                            <th class="text-left font-medium px-6 py-3">Username</th>
                            <th class="text-left font-medium px-6 py-3">Email</th>
                            <th class="text-left font-medium px-6 py-3">Role</th>
                            <th class="text-left font-medium px-6 py-3">Status</th>
                            <th class="cell-actions text-right font-medium px-6 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">

                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-slate-400 px-6 py-8">No user accounts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <?php
                                $isSelf = (int) ($_SESSION['user_id'] ?? 0) === (int) $u['user_id'];
                                $statusKey = strtolower($u['status'] ?? 'active');
                                $displayName = full_name($u['first_name'], $u['last_name']);
                            ?>
                            <tr>
                                <td class="px-6 py-3" title="<?= htmlspecialchars(code_id('USR', (int) $u['user_id']), ENT_QUOTES) ?>">
                                    <?= manifest_tag(code_id('USR', (int) $u['user_id'])) ?>
                                </td>
                                <td class="px-6 py-3 text-ink-900 dark:text-white" title="<?= htmlspecialchars($displayName, ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($displayName) ?>
                                    <?php if ($isSelf): ?>
                                        <span class="text-xs text-slate-400">(you)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-3 text-slate-600 dark:text-slate-300" title="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($u['username']) ?>
                                </td>
                                <td class="px-6 py-3 text-slate-600 dark:text-slate-300" title="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($u['email'] ?? '—') ?>
                                </td>
                                <td class="px-6 py-3"><?= badge($u['role'] ?? '—', 'bg-slate-100 dark:bg-slate-700/50 text-slate-600 dark:text-slate-300') ?></td>
                                <td class="px-6 py-3"><?= badge(ucfirst($statusKey), status_color($statusKey)) ?></td>
                                <td class="cell-actions px-6 py-3">
                                    <div class="flex items-center justify-end gap-2">

                                        <button type="button"
                                            title="Edit"
                                            onclick="openEditUserModal(
                                                <?= (int) $u['user_id'] ?>,
                                                '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($u['role'] ?? '', ENT_QUOTES) ?>'
                                            )"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
                                            <i class="ti ti-edit text-base"></i>
                                        </button>

                                        <?php if (!$isSelf): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="toggle_user_status" value="1">
                                            <input type="hidden" name="toggle_user_id" value="<?= (int) $u['user_id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $statusKey === 'active' ? 'inactive' : 'active' ?>">
                                            <button type="submit"
                                                title="<?= $statusKey === 'active' ? 'Deactivate' : 'Activate' ?>"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
                                                <i class="ti <?= $statusKey === 'active' ? 'ti-user-off' : 'ti-user-check' ?> text-base"></i>
                                            </button>
                                        </form>

                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this user account? This cannot be undone.');">
                                            <input type="hidden" name="delete_user" value="1">
                                            <input type="hidden" name="delete_user_id" value="<?= (int) $u['user_id'] ?>">
                                            <button type="submit"
                                                title="Delete"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10">
                                                <i class="ti ti-trash text-base"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= ADD USER MODAL ================= -->
        <div id="userModal" class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">

                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Add User</h2>
                        <p class="text-sm text-slate-500">Create a new account and assign it a role.</p>
                    </div>
                    <button type="button" onclick="closeUserModal()" class="text-slate-400 hover:text-slate-700 text-xl">&times;</button>
                </div>

                <form method="POST" class="p-6">
                    <input type="hidden" name="add_user" value="1">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" required maxlength="100"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" required maxlength="100"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
                            <input type="text" name="username" required maxlength="50"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required maxlength="100"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                            <div class="password-field-wrap">
                                <input type="password" name="password" id="add_password" required minlength="8"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('add_password', this)" tabindex="-1">
                                    <i class="ti ti-eye text-base"></i>
                                </button>
                            </div>
                            <p class="text-xs text-slate-400 mt-1">At least 8 characters.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                            <div class="password-field-wrap">
                                <input type="password" name="confirm_password" id="add_confirm_password" required minlength="8"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('add_confirm_password', this)" tabindex="-1">
                                    <i class="ti ti-eye text-base"></i>
                                </button>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1">Role <span class="text-red-500">*</span></label>
                            <select name="role" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                <option value="">Select a role</option>
                                <?php foreach ($VALID_ROLES as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                        <button type="button" onclick="closeUserModal()"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
                        <button type="submit"
                            class="px-4 py-2 rounded-lg text-sm font-medium bg-blue-600 hover:bg-blue-700 text-white">Create User</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================= EDIT USER MODAL ================= -->
        <div id="editUserModal" class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">

                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Edit User</h2>
                        <p class="text-sm text-slate-500">Update account details, or leave the password blank to keep it unchanged.</p>
                    </div>
                    <button type="button" onclick="closeEditUserModal()" class="text-slate-400 hover:text-slate-700 text-xl">&times;</button>
                </div>

                <form method="POST" class="p-6">
                    <input type="hidden" name="edit_user" value="1">
                    <input type="hidden" name="edit_user_id" id="edit_user_id">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="edit_first_name" id="edit_first_name" required maxlength="100"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" name="edit_last_name" id="edit_last_name" required maxlength="100"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
                            <input type="text" name="edit_username" id="edit_username" required maxlength="50"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="edit_email" id="edit_email" required maxlength="100"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">New Password</label>
                            <div class="password-field-wrap">
                                <input type="password" name="edit_password" id="edit_password_field" minlength="8"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('edit_password_field', this)" tabindex="-1">
                                    <i class="ti ti-eye text-base"></i>
                                </button>
                            </div>
                            <p class="text-xs text-slate-400 mt-1">Leave blank to keep the current password.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Role <span class="text-red-500">*</span></label>
                            <select name="edit_role" id="edit_role" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                <?php foreach ($VALID_ROLES as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                        <button type="button" onclick="closeEditUserModal()"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
                        <button type="submit"
                            class="px-4 py-2 rounded-lg text-sm font-medium bg-blue-600 hover:bg-blue-700 text-white">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openUserModal() {
                const modal = document.getElementById('userModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modal.classList.add('ftms-modal-open');
            }

            function closeUserModal() {
                const modal = document.getElementById('userModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modal.classList.remove('ftms-modal-open');
            }

            function openEditUserModal(userId, username, email, firstName, lastName, role) {
                document.getElementById('edit_user_id').value = userId;
                document.getElementById('edit_username').value = username;
                document.getElementById('edit_email').value = email;
                document.getElementById('edit_first_name').value = firstName;
                document.getElementById('edit_last_name').value = lastName;
                document.getElementById('edit_role').value = role;

                const modal = document.getElementById('editUserModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modal.classList.add('ftms-modal-open');
            }

            function closeEditUserModal() {
                const modal = document.getElementById('editUserModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modal.classList.remove('ftms-modal-open');
            }

            function togglePasswordVisibility(inputId, btn) {
                const input = document.getElementById(inputId);
                if (!input) return;
                const icon = btn.querySelector('i');
                const isHidden = input.type === 'password';

                input.type = isHidden ? 'text' : 'password';
                icon.classList.toggle('ti-eye', !isHidden);
                icon.classList.toggle('ti-eye-off', isHidden);
            }
        </script>

    <?php endif; ?>

</section>