<?php 
    $title = 'Add Panel User';
    require_once '../includes/header.php'; 


  
     if($_SESSION['isadmin'])  
     {  
?>


    <div class="container"><!-- container class is used to centered  the body of the browser with some decent width-->  
        <div class="row"><!-- row class is used for grid system in Bootstrap-->  
            <div class="col-md-4 col-md-offset-4"><!--col-md-4 is used to create the no of colums in the grid also use for medimum and large devices-->  
                <div class="login-panel panel panel-success">  
                    <div class="panel-heading">  
                        <h3 class="panel-title"></h3>  
                    </div>  
                    <div class="panel-body">  
                        <form role="form" method="post" action="adduser.php">  
                            <fieldset>  
                                <div class="form-group">  
                                    <input class="form-control" placeholder="Username" name="name" type="text" autofocus>  
                                </div>  
      
                                <div class="form-group">  
                                    <input class="form-control" placeholder="E-mail" name="email" type="email" autofocus>  
                                </div>  
                                <div class="form-group">  
                                    <input class="form-control" placeholder="Password" name="pass" type="password" value="">  
                                </div>  
                                <div class="form-group">  
                                <input type="checkbox" id="isadmin" name="isadmin" value="1"><label for="isadmin"> Admin</label><br>  
                                </div>  
                               <input class="btn btn-lg btn-success btn-block" type="submit" value="Add User" name="register" >  
      
                            </fieldset>  
                        </form>  
                        
                    </div>  
                </div>  
            </div>  
        </div>  
      
    </div>
    <?php  
        if(isset($_POST['register'])) 
        {  
  
        $user_name = $_POST['name'];//here getting result from the post array after submitting the form.  
        $user_pass = md5($config['sqlsalt'].$_POST['pass']);
        $user_email = $_POST['email'];//same  
        if ($_POST['isadmin']) {
        $user_isadmin = 1;
        }
        else {
            $user_isadmin = 0;
        }
      
      
        if($user_name=='')  
        {  
            //javascript use for input checking  
            echo"<script>alert('Please enter the name')</script>";  
            exit();//this use if first is not work then other will not show  
        }  
      
        if($user_pass=='')  
        {  
            echo"<script>alert('Please enter the password')</script>";  
            exit();  
        }  
      
        if($user_email=='')  
        {  
            echo"<script>alert('Please enter the email')</script>";  
            exit();  
        }  
    //here query check weather if user already registered so can't register again. 
        mysqli_select_db($dbcon,$config['sqldb']); 
        $check_email_query="select * from users WHERE user_email='$user_email'";  
        $run_query=mysqli_query($dbcon,$check_email_query);  
      
        if(mysqli_num_rows($run_query)>0)  
        {  
            echo "<script>alert('Email $user_email already exist in our database, Please try another one!')</script>";  
            exit();  
        }  
    //insert the user into the database.  
        $insert_user="insert into users (user_name,user_pass,user_email,admin) VALUE ('$user_name','$user_pass','$user_email',$user_isadmin)";  
        if(mysqli_query($dbcon,$insert_user))  
        {  
            echo"<script>window.open('./view_users.php?barmess=User has been Added.','_self')</script>";  
        }  
    }




}  
else{
    header("Location: " . $config['url'] ."/users/login.php");//redirect to the login page to secure the welcome page without login access. 
    die(); 
}    
require_once $config['apppath'] . 'includes/footer.php';

?>
  

