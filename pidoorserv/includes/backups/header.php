
<?php 

  session_start();
  $config = include('/home/pi/pidoorserv/includes/config.php');
  include($config['apppath'].'/database/db_connection.php');//make connection here  

?>

     


   









<!doctype html>
<html lang="en">
  <head>
    
  <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="<?php echo $config['url'];?>/css/bootstrap.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">
    <link href="<?php echo $config['url'];?>/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo $config['url'];?>/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="canonical" href="https://getbootstrap.com/docs/4.5/examples/dashboard/">



    <title>PiDoors - <?php echo $title ?></title>
    
    <style>
      .bd-placeholder-img {
        font-size: 1.125rem;
        text-anchor: middle;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
      }

      @media (min-width: 768px) {
        .bd-placeholder-img-lg {
          font-size: 3.5rem;
        }
      }
    </style>
    <!-- Custom styles for this template -->
    <link href="<?php echo $config['url'];?>/css/dashboard.css" rel="stylesheet">
  </head>
  <body>


  <nav class="navbar navbar-dark sticky-top bg-dark flex-md-nowrap p-0 shadow">
  <a class="navbar-brand col-md-3 col-lg-2 mr-0 px-3" href="<?php echo $config['url'];?>">PiDoors</a>
  <button class="navbar-toggler position-absolute d-md-none collapsed" type="button" data-toggle="collapse" data-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  
  <?php 
if (isset($_GET['barmess'])) { $barmess = $_GET['barmess']; echo '<p class="text-warning">'. $barmess .'</p>'; }
?>
  <!-- <input class="form-control form-control-dark w-100" type="text" placeholder="" aria-label="Search">-->
  <ul class="navbar-nav px-3">
    <li class="nav-item text-nowrap">
    <?php 
      if(isset($_SESSION['email'])) {
        echo '<a class="nav-link" href="' . $config['url'] . '/users/logout.php">Log Out</a>'; 
      }
      else {
        echo '<a class="nav-link" href="' . $config['url'] . '/users/login.php">Log in</a>';
      }  

    ?>   
    </li>
  </ul>
</nav>


    <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
      <div class="sidebar-sticky pt-3">



      <?php 
            if(isset($_SESSION['email'])) {  
        ?>

      <ul class="nav flex-column">


    
        <li class="nav-item">
            <a class="nav-link" href="<?php echo $config['url'];?>">
              <span data-feather="home"></span>
              Dashboard 
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#">
              <span data-feather="file"></span>
              Doors
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#">
              <span data-feather="users"></span>
              Access
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?php echo $config['url'];?>/logs.php">
              <span data-feather="bar-chart-2"></span>
              Logs
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#">
              <span data-feather="bar-chart-2"></span>
              Reports
            </a>
          </li>
          </ul>
          <?php 
          } 

            if(isset($_SESSION['isadmin'])) {
        ?>
        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
          <span>Admin Tools</span>
 
        </h6>
        <ul class="nav flex-column mb-2">
          <li class="nav-item">
            <a class="nav-link" href="<?php echo $config['url'] . '/users/view_users.php'?>">
              <span data-feather="file-text"></span>
              View Users
            </a>
          </li>

          <li class="nav-item">
            <a class="nav-link" href="<?php echo $config['url'] . '/users/adduser.php'?>">
              <span data-feather="file-text"></span>
              Add User
            </a>
          </li>
        </ul>
          <?php } ?>
        
      </div>
    </nav>
    

    

