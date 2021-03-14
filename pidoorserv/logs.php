<?php 
    $title = 'Logs';
    require_once './includes/header.php'; 


  
     if($_SESSION['email'])  
     {  
?>


<table id="Logs" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>User ID</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Date</th>
                <th>Location</th>
                <th>Granted</th>
            </tr>
        </thead>
        <tbody>




<!-- Code needed to pull sql data goes here -->

<?php  
            include("./database/db_connection.php");  
            mysqli_select_db($dbcon,$config['sqldb2']);
            $view_users_query="select * from logs ORDER BY Date";//select query for viewing users.  
            $run=mysqli_query($dbcon,$view_users_query);//here run the sql query.  
      
            while($row=mysqli_fetch_array($run))//while look to fetch the result and store in a array $row.  
            {  
            
                $view_users_query1='select * from cards WHERE user_id="'. $row["0"] .'"';//select query for viewing users.  
                $run1=mysqli_query($dbcon,$view_users_query1);//here run the sql query.
                $row1=mysqli_fetch_array($run1)
                ?><tr><th><?php echo $row["0"]; ?></th>
                <th><?php echo $row1["4"]; ?></th>
                <th><?php echo $row1["5"]; ?></th>
                <th><?php echo $row["1"]; ?></th>
                <th><?php echo $row["3"]; ?></th>
                <th><?php 
                if ($row["2"] == 1) {
                    ?>
                    <img src="<?php echo $config['url'];?>/images/greenapproved.png" width="20" height="20">
                    <?php
                }
                else if ($row["2"] == 0) {
                     ?>
                    <img src='<?php echo $config['url'];?>/images/reddenied.png' width="20" height="20">
                    <?php
                }
                
                
                ?></th></tr><?php
            
            } ?>  
            
        </tbody>
        <tfoot>
            <tr>
            <th>User ID</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Date</th>
                <th>Location</th>
                <th>Granted</th>
            </tr>
        </tfoot>
    </table>





<?php
    
    }  
    else{
        header("Location: " . $config['url'] ."/users/login.php");//redirect to the login page to secure the welcome page without login access. 
        die(); 
    }    
    require_once $config['apppath'] . 'includes/footer.php';

?>
