
<?php 
    $title = 'Edit Door';
    require_once './includes/header.php'; 


  
     if($_SESSION['email'])  
     {  
        if (isset($_GET['doorname'])) { $doorname = $_GET['doorname']; }        
?>
Editing Door.. <?php echo $doorname; ?>





<?php
    
    }  
    else{
        header("Location: " . $config['url'] ."/users/login.php");//redirect to the login page to secure the welcome page without login access. 
        die(); 
    }    
    require_once $config['apppath'] . 'includes/footer.php';

?>