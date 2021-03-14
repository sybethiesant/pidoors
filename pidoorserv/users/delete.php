 <?php  
    include("../database/db_connection.php"); 
    session_start();    

    if(!$_SESSION['isadmin'])   {  
      

        echo "<script>window.open('" . $config['url'] ."/index.php','_self')</script>";  
        #header("Location: ../index.php");//redirect to the login page to secure the welcome page without login access.  
        newExit();
    }   
    $delete_id=$_GET['del'];  
    if (!$dbcon) {
        die('Not connected : ' . mysql_error());
    }
    //

    $delete_who=mysqli_query($dbcon, "select * from users WHERE id='$delete_id'");//shouldnt allow to delete self.
    if ($dbcon -> connect_errno) {
        echo "Failed to connect to MySQL: " . $dbcon -> connect_error;
        exit();
      }
    $row = mysqli_fetch_row($delete_who);
    if ($row[3] == $_SESSION['email']) {
        echo "<script>window.open('view_users.php?barmess=Cannot delete yourself!','_self')</script>"; 
    }
 
 
    else {
 
 
        $delete_query="delete  from users WHERE id='$delete_id'";//delete query  
        $run=mysqli_query($dbcon,$delete_query);  
        if($run)  
            {  
            //javascript function to open in the same window   
                echo "<script>window.open('view_users.php?barmess=User has been deleted!','_self')</script>";  
            }  
        }

      
    ?>  