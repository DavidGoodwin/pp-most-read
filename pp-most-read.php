<?php
/*
Plugin Name: PP Most Read
Plugin URI: about:blank
Description: Generates a list of the most read posts in a predefined period.
Version: 0.1
Author: Steven Meyer <steven@palepurple.co.uk>
Author URI: about:blank
Licence: GPL
*/


define('PPMR_PLUGIN_NAME', 'PP Most Read');
define('PPMR_TRANSLATION_DOMAIN', 'ppmr-mostread');
define('PPMR_READ_CACHE_KEY', 'ppmr_mostread');
define('PPMR_HITS_TABLE', 'ppmr_hits');
define('PPMR_AJAX_ACTION', 'ppmr_mostread_hit'); //there must be a function with this name
define('PPMR_NONCE', 'pp-mostread-hit-nonce');
define('PPMR_COUNTERS_CACHE_KEY', 'ppmr_mostread_counters');
define('PPMR_HITS_SINCE_LAST_WRITE', 'ppmr_mostread_hitssincewrite');
define('PPMR_DEBUG', FALSE);

register_activation_hook(__FILE__, 'ppmr_install');
register_deactivation_hook(__FILE__, 'ppmr_uninstall');
add_action('admin_menu', 'ppmr_plugin_menu');
add_action('wp_ajax_nopriv_' . PPMR_AJAX_ACTION, PPMR_AJAX_ACTION);
add_action('wp_ajax_' . PPMR_AJAX_ACTION, PPMR_AJAX_ACTION);
add_action('wp_enqueue_scripts', 'ppmr_wp_enqueue_scripts');

/**
 * Logs to PHP error_log with the plugin name as a prefix only if PPMR_DEBUG is
 * TRUE.
 * @param String $message The message to log.
 */
function ppmr_debug_log($message) {
    ppmr_log($message, TRUE);
}

/**
 * Increments or creates the counter of hits since last write in cache.
 * Created to address some code duplication.
 * @param int $incrementAmount The amount to increment the counter by.
 */
function ppmr_incr_hits_since_last_write($incrementAmount = 1) {
    global $cacheExpires;
    $hitsSinceLastWrite = wp_cache_get(PPMR_HITS_SINCE_LAST_WRITE);
    if (FALSE === $hitsSinceLastWrite) {
        // cache object probably doesn't exists, so try and make it
        $hitsSinceLastWrite = wp_cache_add(PPMR_HITS_SINCE_LAST_WRITE, 1, '', $cacheExpires);
        if (FALSE === $hitsSinceLastWrite) {
            //couldn't add to cache
            //this probably means that another instance beat us to it
            $hitsSinceLastWrite = wp_cache_get(PPMR_HITS_SINCE_LAST_WRITE);
            if (FALSE === $hitsSinceLastWrite) {
                //this shouldn't happen unless there is an error
                ppmr_log("Could not increment the counter of hits since the "
                        . "last database update.");
                return;
            }
        } else {
            ppmr_log("Created hits-since-last-write counter. (Expires: $cacheExpires)");
        }
    }
    $hitsSinceLastWrite += $incrementAmount;
    wp_cache_set(PPMR_HITS_SINCE_LAST_WRITE, $hitsSinceLastWrite, '', $cacheExpires);
}

/**
 * Increases the hit counter for a post in the cache.
 * After a set number of calls to this function, the counters are added to the
 * database.
 * @param String $postID The ID of the post being hit.
 * @param int    $incrementAmount The amount to increment the counter by.
 * @param boolean $blockCacheCounter Stop the hits-since-last-write counter. This
 * is used internally.
 * Default is one.
 */
function ppmr_increment_counter($postID, $incrementAmount = 1, $blockCacheCounter = FALSE) {
    global $cacheExpires;
    $counters = wp_cache_get(PPMR_COUNTERS_CACHE_KEY);
    if (FALSE === $counters) {
        $result = wp_cache_add(PPMR_COUNTERS_CACHE_KEY,
                       array('flushing' => FALSE, $postID => $incrementAmount),
                       '', $cacheExpires);
        if ($result) {
            ppmr_log("Created cache counters. (Expires: $cacheExpires)");
            ppmr_incr_hits_since_last_write();
            return;
        }
        else { //another instance created the cache object
            $counters = wp_cache_get(PPMR_COUNTERS_CACHE_KEY);
        }
    }
    if (!isset($counters[$postID])) {
        $counters[$postID] = $incrementAmount;
    } else {
        $counters[$postID] += $incrementAmount;
    }
    wp_cache_set(PPMR_COUNTERS_CACHE_KEY, $counters, '', $cacheExpires);
    if (!$blockCacheCounter) ppmr_incr_hits_since_last_write();
    $hitsSinceLastWrite = wp_cache_get(PPMR_HITS_SINCE_LAST_WRITE);
    if (get_option('ppmr_flush_counters_after_x_hits') <= $hitsSinceLastWrite) {
        ppmr_log("Writing cached counters to database.");
        ppmr_write_cached_counters();
    }
}

/**
 * Run when the plugin is installed.  Creates the database table and sets the
 * default options.
 * @global wpdb $wpdb The WordPress database access object.
 */
function ppmr_install() {
    global $wpdb;

    $tableName = $wpdb->prefix . PPMR_HITS_TABLE;

    $sql = "CREATE TABLE IF NOT EXISTS $tableName (
                id int(10) NOT NULL AUTO_INCREMENT,
                post_id BIGINT(20) UNSIGNED NOT NULL,
                date DATE NOT NULL,
                hits INT(10) DEFAULT 1,
                PRIMARY KEY (id),
                FOREIGN KEY (post_id) REFERENCES $wpdb->posts(ID)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                UNIQUE (post_id, date)
                ) ENGINE = InnoDB";
    require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
    dbDelta($sql);

    add_option('ppmr_days_to_keep_hits', '100');
    add_option('ppmr_most_read_in_x_days', '7');
    add_option('ppmr_ignore_return_within_x_minutes', '60');
    add_option('ppmr_posts_to_display', '5');
    add_option('ppmr_flush_counters_after_x_hits', 10);
    add_option('ppmr_output_cache_expires_minutes', 10);
}

/**
 * Logs to PHP error_log with the plugin name as a prefix.
 * @param String  $message      The message to log
 * @param boolean $debugMessage Only display if PPMR_DEBUG is TRUE.
 */
function ppmr_log($message, $debugMessage = FALSE) {
    if (!$debugMessage || PPMR_DEBUG) {
        error_log(PPMR_PLUGIN_NAME . ': ' . $message);
    }
}

function ppmr_mostread() {
    global $wpdb;

    $output = wp_cache_get(PPMR_READ_CACHE_KEY);
    if (FALSE === $output) {
        //no cached result, so make one
        $expires = get_option('ppmr_output_cache_expires_minutes') * 60;
        $output  = '<div class="link">' . PHP_EOL;
        $output .= '<h2>Most Read</h2>' . PHP_EOL;
        $output .= '<nav title="Most Read Recently">' . PHP_EOL;
        $hits  = $wpdb->prefix . PPMR_HITS_TABLE;
        $posts = $wpdb->posts;
        $query = "SELECT $hits.post_id, SUM($hits.hits) as count, $posts.post_title " .
            "FROM $hits " .
            "INNER JOIN $posts ON $hits.post_id=$posts.ID " .
            "WHERE $hits.date>DATE_SUB(CURDATE(),INTERVAL " . get_option('ppmr_most_read_in_x_days') . ' DAY) ' .
            "GROUP BY $hits.post_id ORDER BY count DESC " .
            'LIMIT ' . get_option('ppmr_posts_to_display');
        $result = $wpdb->get_results($query);
        ppmr_debug_log("Got output from database.");
        if (empty($result) || !is_array($result)) {
            $output .= '<p class="ppmr_no_results">';
            $output .= __('There are no most popular posts just now.  Try again later.',
                    PPMR_TRANSLATION_DOMAIN);
            $output .= '</p>' . PHP_EOL;
        } else {
            $output .= '<ul class="anorakspots">' . PHP_EOL;
            foreach ($result as $post) {
                $output .= '<li>' . PHP_EOL;
                $output .= '<a href="' . get_permalink($post->post_id) . '">'
                        . $post->post_title . '</a>' . PHP_EOL;
                $output .= '</li>' . PHP_EOL;
            }
            $output .= '</ul>' . PHP_EOL;
        }
        $output .= '</nav></div>' . PHP_EOL;
        if (FALSE === wp_cache_add(PPMR_READ_CACHE_KEY, $output, '', $expires)) {
            //result was added to the cache by another instance
            $try_again = wp_cache_get(PPMR_READ_CACHE_KEY);
            if (FALSE === $try_again) {
                //something is wrong with the cache
                ppmr_log("Could not get html result from cache. Used database instead.");
            }
        } else {
            ppmr_log("Cached the output. (Expires: $expires)");
        }
    } else {
        ppmr_debug_log("Using cached output.");
    }
    return $output;
}

/*
 * Function run when using AJAX.  Calls the functions to increment the counter.
 */
function ppmr_mostread_hit() {
    global $cacheExpires;
    ppmr_debug_log("AJAX function called.");
    if (isset($_POST['postID']) && isset($_POST['_ajax_nonce'])) {
        $nonce = $_POST['_ajax_nonce'];
        $validNonce = TRUE;//wp_verify_nonce($nonce, PPMR_AJAX_ACTION);
        if ($validNonce) {
            $cacheExpires = get_option('ppmr_days_to_keep_hits') * 24 * 60 * 60;
            if (2592000 < $cacheExpires) $cacheExpires = 2592000; //max value
            $postID = (int) $_POST['postID'];
            ppmr_debug_log("Hit postID:$postID");
            ppmr_increment_counter($postID);
        } else {
            ppmr_debug_log("Nonce check failed. (Nonce:$nonce, value:$validNonce)");
        }
    }
    exit();
}

/**
 * Defines where and how to put menus.
 */
function ppmr_plugin_menu() {
    add_options_page(PPMR_PLUGIN_NAME . ' Options', PPMR_PLUGIN_NAME, 'manage_options',
            'ppmr_mostread', 'ppmr_settings_page');
}

/**
 * The settings page in the admin area.
 */
function ppmr_settings_page() {
    $pluginName = PPMR_PLUGIN_NAME;
    $nonceField = wp_nonce_field('update-options');
    $dtkh  = get_option('ppmr_days_to_keep_hits');
    $fcaxh = get_option('ppmr_flush_counters_after_x_hits');
    $irwxm = get_option('ppmr_ignore_return_within_x_minutes');
    $mrixd = get_option('ppmr_most_read_in_x_days');
    $ocem  = get_option('ppmr_output_cache_expires_minutes');
    $ptd   = get_option('ppmr_posts_to_display');
    $save  = __('Save Changes');
    echo <<<EOT
    <div>
        <h2>$pluginName</h2>
        <form method="post" action="options.php">
        $nonceField
        <fieldset>
            <legend>Display</legend>
            <label for="ppmr_most_read_in_x_days">Display most read posts in the last</label>
            <input id="ppmr_most_read_in_x_days" name="ppmr_most_read_in_x_days" type="text" value="$mrixd" />
            days.<br />
            <label for="ppmr_posts_to_display">Number of posts to display.</label>
            <input id="ppmr_posts_to_display" name="ppmr_posts_to_display" type="text" value="$ptd" />
        </fieldset>
        <fieldset>
            <legend>Cache</legend>
            <label for="ppmr_flush_counters_after_x_hits">Write cached counters to the database after</label>
            <input id="ppmr_flush_counters_after_x_hits" name="ppmr_flush_counters_after_x_hits" type="text" value="$fcaxh" />
            page hits (to any page).<br />
            <label for="ppmr_output_cache_expires_minutes">Output cache (list of most read posts) expires after</label>
            <input id="ppmr_output_cache_expires_minutes" name="ppmr_output_cache_expires_minutes" type="text" value="$ocem" />
            minutes.
        </fieldset>
        <fieldset>
            <legend>Other</legend>
            <label for="ppmr_days_to_keep_hits">Keep hit records for</label>
            <input id="ppmr_days_to_keep_hits" name="ppmr_days_to_keep_hits" type="text" value="$dtkh" />
            days.<br />
            <label for="ppmr_ignore_return_within_x_minutes">Ignore page reloads within</label>
            <input id="ppmr_ignore_return_within_x_minutes" name="ppmr_ignore_return_within_x_minutes" type="text" value="$irwxm" />
            minutes (of the first visit).
        </fieldset>
        <input type="hidden" name="action" value="update" />
        <input type="hidden" name="page_options" value="ppmr_most_read_in_x_days,ppmr_posts_to_display,ppmr_flush_counters_after_x_hits,ppmr_output_cache_expires_minutes,ppmr_days_to_keep_hits,ppmr_ignore_return_within_x_minutes" />
        <input type="submit" value="$save" />
        </form>
    </div>
EOT;
}

/**
 * Run when the plugin is uninstalled.  Unsets the options.
 */
function ppmr_uninstall() {
    delete_option('ppmr_days_to_keep_hits');
    delete_option('ppmr_most_read_in_x_days');
    delete_option('ppmr_ignore_return_within_x_minutes');
    delete_option('ppmr_posts_to_display');
    delete_option('ppmr_flush_counters_after_x_hits');
    delete_option('ppmr_output_cache_expires_minutes');
    //TODO remove db table
}

/**
 * Things to do in the wp_enqueue_script hook
 */
function ppmr_wp_enqueue_scripts() {
    global $post;
    if (is_single()) {
        $src = plugin_dir_url(__FILE__) . 'pp-hit.js';
        $nonce = wp_create_nonce(PPMR_NONCE);
        wp_register_script('pp-register-hit', $src, array('jquery'));
        wp_enqueue_script('pp-register-hit');
        wp_localize_script('pp-register-hit', 'PPMostRead',
                array('action'  => PPMR_AJAX_ACTION,
                      'ajaxurl' => admin_url('admin-ajax.php'),
                      'postid'  => $post->ID,
                      'nonce'   => $nonce));

        ppmr_debug_log("Registered script with nonce:$nonce");
    }
}

/**
 * Increments the database counters by the values in the cached counters.
 * @global wpdb $wpdb The WordPress database.
 * @return void
 */
function ppmr_write_cached_counters() {
    global $wpdb, $cacheExpires;
    $myKey = rand();
    $counters = wp_cache_get(PPMR_COUNTERS_CACHE_KEY);
    if (is_array($counters) && FALSE === $counters['flushing']) {
        $counters['flushing'] = $myKey;
        wp_cache_set(PPMR_COUNTERS_CACHE_KEY, $counters, '', $cacheExpires);
    } else {
        ppmr_log("The hit counters in the cache are not set properly.");
    }
    /*
     * Just in case any other instances tried to do this function and got the
     * $counters before this instance had written to cache, wait to give those
     * instances chance to "lock" the file (overwriting this "lock").
     */
    sleep(1);
    $counters = wp_cache_get(PPMR_COUNTERS_CACHE_KEY);
    if (!is_array($counters) || $myKey != $counters['flushing']) {
        return; //some other instance is writing to the db
    }
    //reset counters
    wp_cache_set(PPMR_COUNTERS_CACHE_KEY, array('flushing' => FALSE), '', $cacheExpires);
    wp_cache_set(PPMR_HITS_SINCE_LAST_WRITE, 0, '', $cacheExpires);
    unset($counters['flushing']);
    if (empty($counters)) {
        ppmr_log("The hit counters in cache are empty. Should this function "
                . "have been called here?");
        return;
    }

    //build query
    $tableName = $wpdb->prefix . PPMR_HITS_TABLE;
    $query = "INSERT INTO $tableName (post_ID, date, hits) VALUES ";
    $values = array();
    foreach ($counters as $postID => $count) {
        $values[] = "('$postID', '" . date('Y-m-d') . "', '$count')";
    }
    $query .= implode(',', $values)
            . ' ON DUPLICATE KEY UPDATE hits=hits+VALUES(hits)';
    $result = $wpdb->query($query);
    if (FALSE === $result) { //if it didn't work, then write back to cache.
        foreach ($counters as $postID => $count) {
            ppmr_increment_counter($postID, $count, TRUE);
        }
    }
}
