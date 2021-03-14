<?php  
    $config = include('/home/pi/pidoorserv/includes/config.php');
    $dbcon=mysqli_connect($config['sqladdr'],$config['sqluser'],$config['sqlpass']);  
    mysqli_select_db($dbcon,$config['sqldb']);  
    if (mysqli_connect_errno())
    {
        echo "Failed to connect to MySQL: " . mysqli_connect_error();
        //you need to exit the script, if there is an error
        exit();
    }
?> 


