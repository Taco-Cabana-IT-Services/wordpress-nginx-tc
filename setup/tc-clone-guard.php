<?php
/**
 * Plugin Name: TC Clone Guard
 * Description: While wp-config.php defines TC_CLONE_GUARD as true, WordPress sends no email,
 * so testing a copied site can't email customers or staff. Remove the constant at go-live.
 *
 * Install as a must-use plugin (loads before regular plugins such as FluentSMTP):
 *   sudo install -m 0644 -o www-data -g www-data /etc/nginx/setup/tc-clone-guard.php \
 *     /var/www/html/wordpress/wp-content/mu-plugins/tc-clone-guard.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('TC_CLONE_GUARD') || !TC_CLONE_GUARD) {
    return;
}

// wp_mail() is pluggable: defining it here wins over core and over FluentSMTP's version.
// Each blocked message is written to the site's nginx error log so form tests can be checked.
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array())
    {
        error_log('TC_CLONE_GUARD blocked email to ' . implode(', ', (array) $to) . ': ' . $subject);
        return false;
    }
}

add_action('admin_notices', function () {
    echo '<div class="notice notice-warning"><p><strong>TC Clone Guard is on:</strong> '
        . 'WordPress email is blocked on this server. Remove TC_CLONE_GUARD from wp-config.php at go-live.</p></div>';
});
