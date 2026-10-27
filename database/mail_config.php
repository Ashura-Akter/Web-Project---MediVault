<?php
/*
 * MediVault mail settings (used by Forgot Password OTP).
 *
 * OPTION A - real emails (recommended when you demo/deploy):
 *   1. Use a Gmail account, turn on 2-Step Verification.
 *   2. Google Account -> Security -> App passwords -> create one (16 letters).
 *   3. Put the Gmail address in 'username' and the app password in 'password'
 *      (no spaces), then set 'smtp_enabled' => true.
 *
 * OPTION B - local XAMPP testing without email:
 *   Leave 'smtp_enabled' false. If the email cannot be sent, the OTP is shown
 *   on the Forgot Password page (and saved in database/mail_log.txt) so the
 *   reset flow still works. Set 'dev_show_otp' => false before a real launch.
 */
return [
    'smtp_enabled' => false,
    'host'         => 'smtp.gmail.com',
    'port'         => 587,          // STARTTLS
    'username'     => '',           // e.g. yourname@gmail.com
    'password'     => '',           // Gmail App Password
    'from_email'   => '',           // leave empty to use 'username'
    'from_name'    => 'MediVault',
    'dev_show_otp' => true,
];
