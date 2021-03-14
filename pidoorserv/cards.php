<?php 
    $title = 'Cards';
    require_once './includes/header.php'; 


  
     if($_SESSION['email'])  
     {  
?>


<table id="Logs" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>Card ID</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Doors</th>
                <th>Active</th>
                <th>Edit</th>
            </tr>
        </thead>
        <tbody>




<!-- Code needed to pull sql data goes here -->

<?php  
            include("./database/db_connection.php");  
            mysqli_select_db($dbcon,$config['sqldb2']);
            $view_users_query="select * from cards";//select query for viewing users.  
            $run=mysqli_query($dbcon,$view_users_query);//here run the sql query.  
      
            while($row=mysqli_fetch_array($run))//while look to fetch the result and store in a array $row.  
            {
                ?><tr><th><?php echo $row["1"]; ?></th>
                <th><?php echo $row["4"]; ?></th>
                <th><?php echo $row["5"]; ?></th>
                <th><?php echo $row["6"]; ?></th>
                <th><?php 
                if ($row["7"] == 1) {
                    ?>
                    <img src="<?php echo $config['url'];?>/images/greenapproved.png" width="20" height="20">
                    <?php
                }
                else if ($row["7"] == 0) {
                     ?>
                    <img src='<?php echo $config['url'];?>/images/reddenied.png' width="20" height="20">
                    <?php
                }
                
                
                ?></th>
                
                <th><a href="<?php echo $config['url'];?>/editcard.php?cardid=<?php echo $row["1"];?>&firstname=<?php echo $row["4"];?>&lastname=<?php echo $row["5"];?>&doors=<?php echo $row["6"];?>&active=<?php echo $row["7"];?>"><img src='<?php echo $config['url'];?>/images/moreicon.png' width="20" height="20"></a></th>
            </tr><?php
            
            } ?>  
            
        </tbody>
        <tfoot>
        <tr>
                <th>Card ID</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Doors</th>
                <th>Active</th>
                <th>Edit</th>
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