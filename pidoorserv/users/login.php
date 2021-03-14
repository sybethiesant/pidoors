

<?php 
    $title = 'Login';
    require_once '../includes/header.php'; 
    


    if(isset($_SESSION['email']))
    {  
    
        header("Location: ../index.php");//redirect to the login page to secure the welcome page without login access. 
        Exit(); 
    }  
?>

<link href="<?php echo $config['url'];?>/css/floating-labels.css" rel="stylesheet">
    <style>
      .bd-placeholder-img {
        font-size: 1.125rem;
        text-anchor: middle;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
      }

      @media (min-width: 768px) {
        .bd-placeholder-img-lg {
          font-size: 3.5rem;
        }
      }
    </style>
<div class="container">  
        <div class="row">  
            <div class="col-md-4 col-md-offset-4">  
                <div class="login-panel panel panel-success">  
                    <div class="panel-heading">  
                        <h3 class="panel-title">Sign In</h3>  
                    </div>  
                    <div class="panel-body">  
                        <form role="form" method="post" action="login.php" class="text-center mb-4">  
                            <fieldset>  
                                <div class="form-group"  >  
                                    <input class="form-control" placeholder="E-mail" name="email" type="email" autofocus>  
                                </div>  
                                <div class="form-group">  
                                    <input class="form-control" placeholder="Password" name="pass" type="password" value="">  
                                </div>  
      
      
                                    <input class="btn btn-lg btn-success btn-block" type="submit" value="Sign In" name="login" >  
                            </fieldset>  
                        </form>  
                    </div>  
                </div>  
            </div>  
        </div>  
    </div>   


   
    <?php  
      
    include("../database/db_connection.php");  

    if(isset($_POST['login']))  
    {  
        $user_email = $_POST['email'];  
        $user_pass = md5($config['sqlsalt'].$_POST['pass']);  
        
        $check_user="select * from users WHERE user_email='$user_email'AND user_pass='$user_pass'"; 
        $checkadmin="select * from users WHERE user_email='$user_email' AND user_pass='$user_pass' AND admin='1'";

        mysqli_select_db($dbcon,$config['sqldb']);
        $run=mysqli_query($dbcon,$check_user); 
        $isadmin=mysqli_query($dbcon,$checkadmin);  
      
        if(mysqli_num_rows($run))  
        {  
            echo "<script>window.open('" . $config['url'] ."/index.php','_self')</script>";  
      
            $_SESSION['email']=$user_email;//here session is used and value of $user_email store in $_SESSION.  



            
            if(mysqli_num_rows($isadmin))  
            {  
                $_SESSION['isadmin']=TRUE;// sets admin flag if is admin.
            }

        }  
        else  
        {  
          echo "<script>alert('Email or password is incorrect!')</script>";  
        }  
    }  
    require_once $config['apppath'] . 'includes/footer.php'; 
    
    ?>