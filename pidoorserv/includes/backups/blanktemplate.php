<?php 
    $title = 'Change Me';
    require_once './includes/header.php'; 


  
     if($_SESSION['email'])  
     {  
?>


This is a Blank Template 





<?php
    
    }  
    else{
        header("Location: " . $config['url'] ."/users/login.php");//redirect to the login page to secure the welcome page without login access. 
        die(); 
    }    
    require_once $config['apppath'] . 'includes/footer.php';

?>
