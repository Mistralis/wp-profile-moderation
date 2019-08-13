<?php
/**
 * WP Profile Moderation
 *
 * @author      Mistral
 * @copyright   2019 Mistral
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: WP Profile Moderation
 * Plugin URI:  https://example.com/plugin-name
 * Description: wpForo companion plugin to moderate profile images via Google Vision API Safe-search. Displays a score against the user listing in WP Users admin. Google Vision API key required.
 * Version:     0.1
 * Author:      Mistral
 * Text Domain: wp-profile-moderation
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// our action will be used later
add_action("wp_profile_moderation_check_image_callback", "wp_profile_moderation_check_image", 10, 2);

//  WPforo action called after a profile has been updated.
add_action('wpforo_update_profile_after', 'wp_profile_moderation_wpforo_update_profile_after');
function wp_profile_moderation_wpforo_update_profile_after($user) {
    if (!$user['userid']) return; // no user!
    if (!isset($_FILES['avatar']) || empty($_FILES['avatar']) || !isset($_FILES['avatar']['name']) || !$_FILES['avatar']['name']) return; // there is no avatar file to process
    $avatarUrl = WPF()->member->get_avatar_url($user['userid']);
    if (!$avatarUrl) return; // there might not be an image

    // rather than calling the image check directly and prolonging the profile update thread, we schedule an event which makes it run like a background task
    wp_schedule_single_event(time(), 'wp_profile_moderation_check_image_callback', array($user['userid'], $avatarUrl));
}


/**
 * create and POST the API request
 * @param $userid
 * @param $avatarUrl
 */
function wp_profile_moderation_check_image($userid, $avatarUrl) {

    $apikey = esc_attr(get_option('wp_profile_moderation_google_vision_api_key'));
    if (!$apikey) return;
    $avatarUrl = wp_profile_moderation_protocol($avatarUrl); // get us a usable URL. The URL must be accessible on the web!

    // this is a predefined json string we can pass to Google Vision. We only check the safe-search feature.
    $json = '{
              "requests": [
                {
                  "image": {
                    "source": {
                      "imageUri": "urltoreplace"
                    }
                  },
                  "features": [
                    {
                      "type": "SAFE_SEARCH_DETECTION",
                      "maxResults": 1
                    }
                  ]
                }
              ]
            }';

    $json = str_replace("urltoreplace", $avatarUrl, $json); // inject the image URL.

    $apiurl = "https://vision.googleapis.com/v1/images:annotate?key=" . $apikey;
    $response = wp_remote_post($apiurl, array(
        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
        'body' => $json,
        'method' => 'POST',
        'data_format' => 'body',
    ));

    if (is_wp_error($response)) return; // to-do improve this and add retry
    $body = trim(wp_remote_retrieve_body($response));
    if (empty($body)) return;

    // we make an array of useful info, not all is used yet
    $result = array();
    $result['time'] = time();
    $result['hash'] = md5_file($avatarUrl); // fetch the remote file and make a hash from it
    $result['platform'] = 'Google-Vision'; // in case we want to use other platforms in the future
    $result['raw_response'] = $body;
    $score = wp_profile_moderation_check_score($body, $result['platform']);
    $result['score'] = $score[0];
    $result['response'] = $score[1];

    // store as user_meta, replacing any previous values
    update_user_meta($userid, 'wp_profile_moderation_avatar_response', $result);

}

/**
 * create a single score for the results
 * @param $body
 * @param $platform
 * @return array
 */
function wp_profile_moderation_check_score($body, $platform) {
    $score = 0;
    $body_array = json_decode($body);

    switch ($platform) {
        case 'Google-Vision':
            // each result is simply assigned a numeric value 0-4. We return the highest value found across all results.
            $likelihoodName = array('VERY_UNLIKELY', 'UNLIKELY', 'POSSIBLE', 'LIKELY', 'VERY_LIKELY');
            $safe_search = $body_array->responses[0]->safeSearchAnnotation;
            foreach ($safe_search as $item) {
                $this_score = array_search($item, $likelihoodName);
                if ($this_score > $score) $score = $this_score;
            }
            return array($score, wp_profile_moderation_print_r($safe_search));
            break;
    }
}

/**
 * tidy the image URL as stored by WPForo eg //yourwebsiteurl.com/imagelocation/profiletitle.jpg
 * @param $url
 * @return string
 */
function wp_profile_moderation_protocol($url) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    if (substr($url, 0, 2) === '//') $url = ltrim($url, '//');
    return $protocol . $url;
}

/**
 * pretty up the results for display in html
 * @param $my_array
 * @return string
 */
function wp_profile_moderation_print_r($my_array) {
    if (is_object($my_array)) {
        $string = '';
        foreach ($my_array as $k => $v) {
            $string .= '<strong>' . $k . ": </strong>";
            $string .= ($v);
            $string .= "<br>";
        }
        return $string;
    }
}

/*** Admin stuff ***/

// to style the tool tip we need these styles
add_action('admin_enqueue_scripts', 'wp_profile_moderation_load_custom_wp_admin_style');
function wp_profile_moderation_load_custom_wp_admin_style() {
    wp_register_style('custom_wp_admin_css', get_stylesheet_directory_uri() . '/admin_style.css', false, '1.0.0');
    wp_enqueue_style('custom_wp_admin_css');
}

// add score column on users page
add_filter('manage_users_columns', 'wp_profile_moderation_add_user_id_column');
function wp_profile_moderation_add_user_id_column($columns) {
    $columns['profile_image_moderation'] = 'Moderation Score';
    return $columns;
}

// add values to score column
add_action('manage_users_custom_column', 'wp_profile_moderation_show_user_id_column_content', 10, 3);
function wp_profile_moderation_show_user_id_column_content($value, $column_name, $user_id) {
    if ('profile_image_moderation' == $column_name) {
        $user_image_result = get_user_meta($user_id, 'wp_profile_moderation_avatar_response', true);
        return '<a href="#" class="tooltip">' . $user_image_result['score'] . '<span>' . $user_image_result['response'] . '</span></a>';
    }
}

/*** Sort and Filter Users ***/
// create the 'score' filter button and options menu and show them on the users page
add_action('restrict_manage_users', 'wp_profile_moderation_filter_by_score');
function wp_profile_moderation_filter_by_score($which) {
    // template for filtering
    $st = '<select name="Moderation Score_%s" style="float:none;margin-left:10px;">
    <option value="">%s</option>%s</select>';
    $options = '<option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>';
    $select = sprintf($st, $which, __('Moderation Score...'), $options);
    echo $select;
    submit_button(__('Filter'), null, $which, false);
}

// filter the score column against the user_meta
add_filter('pre_get_users', 'wp_profile_moderation_filter_users_by_score_section');
function wp_profile_moderation_filter_users_by_score_section($query) {
    global $pagenow;
    if (is_admin() && 'users.php' == $pagenow) {
        // figure out which button was clicked
        $top = $_GET['Moderation_Score_top'];
        $bottom = $_GET['Moderation_Score_bottom'];
        if (!empty($top) OR !empty($bottom)) {
            $section = !empty($top) ? $top : $bottom;

            $meta_query = array(array(
                'key' => 'wp_profile_moderation_avatar_response',
                'value' => '"score";i:' . $section, // as its a serialsed string we need to help it find the right part
                'compare' => 'LIKE'
            ));
            $query->set('meta_query', $meta_query);
        }
    }
}

// create custom plugin settings menu
add_action('admin_menu', 'wp_profile_moderation_create_menu');

function wp_profile_moderation_create_menu() {
    add_options_page(esc_html__('WP Profile Moderation', 'wp-profile-moderation'), esc_html__('WP Profile Moderation', 'wp-profile-moderation'), 'manage_options', 'wp_profile_moderation_admin_options_page', 'wp_profile_moderation_admin_options_page');
    add_action('admin_init', 'wp_profile_moderation_register_settings');
}


function wp_profile_moderation_register_settings() {
    //register our settings
    register_setting('wp_profile_moderation-settings-group', 'wp_profile_moderation_google_vision_api_key');
}

function wp_profile_moderation_admin_options_page() {
    ?>
    <div class="wrap">
        <h1>WP Profile Moderation</h1>

        <form method="post" action="options.php">
            <?php settings_fields('wp_profile_moderation-settings-group'); ?>
            <?php do_settings_sections('wp_profile_moderation-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Google Vision API Key</th>
                    <td><input type="text" name="wp_profile_moderation_google_vision_api_key" value="<?php echo esc_attr(get_option('wp_profile_moderation_google_vision_api_key')); ?>"/></td>
                </tr>
            </table>

            <?php submit_button(); ?>

        </form>
    </div>
<?php } ?>