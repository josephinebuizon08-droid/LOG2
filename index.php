<?php
   
    ob_start();

    session_start();

         header("Cache-Control: no-cache, no-store, must-revalidate");
         header("Pragma: no-cache");
         header("Expires: 0");


      if(!isset($_SESSION['user_id'])) { 

          // Serve the landing page's content directly at "/" instead of
          // redirecting to /landing.php -- keeps the URL as just
          // log2.priority-handling.com in the browser's address bar.
          require __DIR__ . '/landing.php';
          exit();
      } 

        require_once __DIR__ . '/auth/session_guard.php';
        ftms_enforce_session_timeout('auth/login.php');
    ?>

    <?php
        // Show errors on screen only outside production. Set APP_ENV=production
        // (or leave APP_ENV unset) on the live server to keep errors out of the
        // rendered page while still logging them server-side.
        error_reporting(E_ALL);
        ini_set('display_errors', getenv('APP_ENV') === 'production' ? '0' : '1');
        ini_set('log_errors', '1');
    ?>

        <?php include 'includes/header.php'; ?>

        <div class="flex h-screen overflow-hidden">
            
            <?php include 'includes/sidebar.php'; ?>

                <div class="flex-1 flex flex-col overflow-hidden">

                <?php include 'includes/navbar.php'; ?>

                        <main class="flex-1 overflow-y-auto p-8">

                            <?php include './modules/dashboard.php'; ?>
                            <?php include './modules/fleet.php'; ?>
                            <?php include './modules/reservation.php'; ?>
                            <?php include './modules/monitoring.php' ?>
                            <?php include './modules/fuel.php'; ?>
                            <?php include './modules/route.php'; ?>
                            <?php include './modules/transport.php'; ?>
                            <?php include './modules/settings.php'; ?>
                            <?php include './modules/security.php'; ?>
                            <?php include './modules/user_management.php'; ?>
                            
                        </main>

                </div>
        </div>
        
        <?php include './includes/footer.php'; ?>
        <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
</script>
<?php ob_end_flush(); ?>