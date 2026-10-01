<?php

// Development only: lets Adminer open the SQLite file with the password "dev"
// (Adminer refuses SQLite logins without a password otherwise).
require_once 'plugins/login-password-less.php';

return new AdminerLoginPasswordLess(password_hash('dev', PASSWORD_DEFAULT));
