<?php
require_once 'includes/functions.php';

$auth = new Auth();
$auth->logout();

show_message('Başarıyla çıkış yaptınız.', 'success');
redirect('index.php');
?>
