<?php
   
    ob_start();

    session_start();

         header("Cache-Control: no-cache, no-store, must-revalidate");
         header("Pragma: no-cache");
         header("Expires: 0");


      if(!isset($_SESSION['user_id'])) { 

          header("Location: landing.php");
          exit();
      } 

        require_once __DIR__ . '/auth/session_guard.php';
        ftms_enforce_session_timeout('auth/login.php');
    ?>

    <?php
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
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