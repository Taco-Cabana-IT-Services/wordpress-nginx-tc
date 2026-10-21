<?php
/**
 * Temporary clone safeguards. Production behavior resumes when the environment
 * is explicitly changed to production at cutover.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (wp_get_environment_type() !== 'production') {
    add_filter('pre_wp_mail', '__return_false');
    add_filter('pre_option_blog_public', '__return_zero');
}
