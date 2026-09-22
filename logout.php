<?php
require __DIR__ . '/app/bootstrap.php';

$_SESSION = [];
session_regenerate_id(true);
redirect('login.php');
