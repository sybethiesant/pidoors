<?php 
    $title = 'Edit Card';
    require_once './includes/header.php'; 


  
     if($_SESSION['email'])  
     {  

        if (isset($_REQUEST['cardid']))
        {
            $cardid = $_REQUEST['cardid']; 
            $firstname =  $_REQUEST['firstname'];
            $lastname =  $_REQUEST['lastname'];
            $doors =  $_REQUEST['doors'];
            $doorsarray = explode(" ", $doors);
            $doorsnoarray = implode(" ",$doors); 
            $active =  $_REQUEST['active'];
            if ($active == "1") {

            }
            else {
                $active = "0";
            }
        }

        if (isset($_REQUEST['submitted'])) {
            include("./database/db_connection.php");  
            mysqli_select_db($dbcon,$config['sqldb2']);
            $editcardquery="update cards SET firstname = '$firstname', lastname = '$lastname', doors = '$doorsnoarray', active = '$active' WHERE user_id = '$cardid'";//select query for viewing users.   

            $run=mysqli_query($dbcon,$editcardquery);//here run the sql query.  
            if(mysqli_query($dbcon,$editcardquery))  
            {  
                echo"<script>window.open('./cards.php?barmess=Card has been edited.','_self')</script>";  
            }  
            else {
                echo "Error updating record: " . $dbcon->error;
            }
        exit;
        }
?>


<form class="form-horizontal" action="editcard.php" method="post">
<fieldset>

<!-- Form Name -->
<legend>Editing Card <?php echo $cardid; ?></legend>

<!-- Text input-->
<div class="form-group">
<input type="hidden" id="cardid" name="cardid" value="<?php echo $cardid; ?>">
<input type="hidden" id="submitted" name="submitted" value="1">
  <label class="col-md-4 control-label" for="First Name">First Name</label>  
  <div class="col-md-4">
  <input id="firstname" name="firstname" type="text" placeholder="First Name" class="form-control input-md" required="" value="<?php echo $firstname; ?>">
    
  </div>
</div>

<!-- Text input-->
<div class="form-group">
  <label class="col-md-4 control-label" for="Last Name">Last Name</label>  
  <div class="col-md-4">
  <input id="lastname" name="lastname" type="text" placeholder="Last Name" class="form-control input-md" required="" required="" value="<?php echo $lastname; ?>">
    
  </div>
</div>

<!-- Select Multiple -->
<div class="form-group">
  <label class="col-md-4 control-label" for="doors">Doors</label>
  <div class="col-md-4">
    <select id="doors" name="doors[]" class="form-control" multiple="multiple">

    <?php  
            include("./database/db_connection.php");  
            mysqli_select_db($dbcon,$config['sqldb2']);
            $view_users_query="select * from doors ORDER BY name";//select query for viewing users.  
            $run=mysqli_query($dbcon,$view_users_query);//here run the sql query.  
      
            while($row=mysqli_fetch_array($run))//while look to fetch the result and store in a array $row.  
            {  
                ?><option value="<?php echo $row["0"]; ?>" 
                
                <?php
                $i = 0;
                foreach ($doorsarray as $key => $value) {
            $i++;
            if ($row["0"] == $value){ echo 'selected'; }
            }
?>




                ><?php echo $row["0"]; ?></option> <?php }?>
    </select>
  </div>
</div>

<!-- Multiple Checkboxes (inline) -->
<div class="form-group">
  <label class="col-md-4 control-label" for="Active">Active</label>
  <div class="col-md-4">
    <label class="checkbox-inline" for="Active">
      <input type="checkbox" name="active" id="active" value="1" <?php if ($active == 1) { echo "checked";}?>>
    </label>
  </div>
</div>

<!-- Button (Double) -->
<div class="form-group">
  <label class="col-md-4 control-label" for="button1id"></label>
  <div class="col-md-8">
    <button type="submit" class="btn btn-success">Submit</button>
  </div>
</div>

</fieldset>
</form>








<?php
    
    }  
    else{
        header("Location: " . $config['url'] ."/users/login.php");//redirect to the login page to secure the welcome page without login access. 
        die(); 
    }    
    require_once $config['apppath'] . 'includes/footer.php';

?>
