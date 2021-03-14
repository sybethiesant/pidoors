
    <?php       
    session_start();
    session_unset();  
    session_destroy();  
    //header("Location: $config['url']");//use for the redirection to some page  
    echo "<script>window.open('" . $config['url'] ."/index.php','_self')</script>";  
    ?>  
