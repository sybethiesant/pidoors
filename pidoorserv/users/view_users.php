
<?php 
    $title = 'View Panel Users';
    require_once '../includes/header.php'; 


  
     if($_SESSION['isadmin'])  
     {  
?>

      <table class="table table-striped table-sm">
        <thead>
          <tr>
            <th>User Name</th>
            <th>User E-Mail</th>
            <th>Admin</th>
            <th>Delete</th>
          </tr>
        </thead>
        <tbody>
        <?php  
            include("../database/db_connection.php");  
            mysqli_select_db($dbcon,$config['sqldb']);
            $view_users_query="select * from users";//select query for viewing users.  
            $run=mysqli_query($dbcon,$view_users_query);//here run the sql query.  
      
            while($row=mysqli_fetch_array($run))//while look to fetch the result and store in a array $row.  
            {  
                $user_id=$row[0];  
                $user_name=$row[1];  
                $user_email=$row[3];  
                $user_isadmin=$row[4];  
      
            ?>  
            <tr>  
    <!--here showing results in the table -->  
                <td><?php echo $user_name;  ?></td>  
                <td><?php echo $user_email;  ?></td>  
                <?php 
                    if ($user_isadmin=="1") {
                        $user_isadmin="Yes";
                    }
                    else {
                        $user_isadmin="No";
                    }
                ?>
                <td><?php echo $user_isadmin;  ?></td> 
                <td><a href="delete.php?del=<?php echo $user_id ?>"><button class="btn btn-danger">Delete</button></a></td> <!--btn btn-danger is a bootstrap button to show danger-->  
            </tr>  
            <?php } ?>  
        </tbody>
      </table>

      <?php
    
  }  
  else{
    header("Location: " . $config['url'] ."/users/login.php");//redirect to the login page to secure the welcome page without login access. 
    die(); 
  }    
  require_once $config['apppath'] . 'includes/footer.php';

?>


