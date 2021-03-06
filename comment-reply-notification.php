<?php
/*
Plugin Name: Comment Reply Notification Updated
Plugin URI: https://github.com/EatingRichly/comment-reply-notification
Version: 1.6.1
Author: Eric J.
Description: When a reply is made to a comment the user has left on the blog, an e-mail shall be sent to the user to notify him of the reply. This will allow the users to follow up the comment and expand the conversation if desired.
Author URI: http://eatingrichly.com/about/#Eric
Donate link: http://eatingrichly.com
GitHub Plugin URI: https://github.com/EatingRichly/comment-reply-notification
GitHub Branch: master
*/

if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if(!class_exists('comment_reply_notification')):
class comment_reply_notification{
    var $version = '1.5.5';
    var $status = '';
    var $message = '';
    var $options = array();
    var $options_keys = array('mail_notify', 'mail_subject', 'mail_message', 'clean_option', 'dn_hide_note');
    var $db_options = 'commentreplynotification';

    function comment_reply_notification(){
        $this->initoption();
        $this->inithook();
    }

    function defaultoption($key=''){
        if(empty($key))
            return false;

        if($key === 'mail_notify'){
            return 'none';
        }elseif($key === 'mail_subject'){
            return __('Your comment at [[blogname]] has a new reply','comment-reply-notification');
        }elseif($key === 'mail_message'){
            return __("<p><strong>[blogname]</strong>: Your comment on the post <strong>[postname]</strong> has a new reply</p>\n<p>Here is your original comment:<br />\n[pc_content]</p>\n<p>Here is the new reply:<br />\n[cc_content]</p>\n<p>You can see more information for the comment on this post here:<br />\n<a href=\"[commentlink]\">[commentlink]</a></p>\n<p><strong>Thank you for your commenting on <a href=\"[blogurl]\">[blogname]</a></strong> -- </p>\n<p><strong>This email was sent automatically. Please don't reply to this email.</strong></p>",'comment-reply-notification');
        }elseif($key === 'clean_option'){
            return 'no';
        }elseif($key === 'dn_hide_note'){
            return 'no';
        }else{
            return false;
        }
    }

    function resetToDefaultOptions(){
        $this->options = array();

        foreach($this->options_keys as $key){
            $this->options[$key] = $this->defaultoption($key);
        }
        update_option($this->db_options, $this->options);
    }

    function initoption(){
        $optionsFromTable = get_option($this->db_options);
        if (empty($optionsFromTable)){
            $this->resetToDefaultOptions();
        }

        $flag = FALSE;
        foreach($this->options_keys as $key) {
            if(isset($optionsFromTable[$key]) && !empty($optionsFromTable[$key])){
                $this->options[$key] = $optionsFromTable[$key];
            }else{
                $this->options[$key] = $this->defaultoption($key);
                $flag = TRUE;
            }
        }
        if($flag === TRUE){
            update_option($this->db_options, $this->options);
        }
        unset($optionsFromTable,$flag);
    }

    function inithook(){
        add_action('init', array(&$this, 'init_textdomain'));
        add_action('comment_post', array(&$this,'add_mail_reply'),9998);
        add_action('wp_set_comment_status', array(&$this,'status_change'),9999,2);
        add_action('comment_post', array(&$this,'email'),9999);
        add_action('comment_form', array(&$this,'addreplyidformfield'),9999);
        add_action('admin_menu', array(&$this,'wpadmin'));
    }

    function init_textdomain(){
        load_plugin_textdomain('comment-reply-notification',false,basename(dirname(__FILE__)));
    }

    function deactivate(){
        if($this->options['clean_option'] === 'yes')
            delete_option($this->db_options);
        return true;
    }

    function status_change($id,$status){
        $id = (int) $id;
        if(isset($GLOBALS['comment']) && ($GLOBALS['comment']->comment_ID == $id)){
            unset($GLOBALS['comment']);
            $comment = get_comment($id);
            $GLOBALS['comment'] = $comment;
        }

        if ($status== 'approve' && intval($comment->comment_parent)>0){
            $this->mailer($id,$comment->comment_parent,$comment->comment_post_ID);
        }

        return $id;
    }

    function email($id){

        global $wpdb;

        if((int) mysql_escape_string($_POST['comment_parent']) === 0 || (int) mysql_escape_string($_POST['comment_post_ID']) === 0){
            $sendemail = 0;
            if (isset($_POST['action']) && $_POST['action'] == 'replyto-comment' && isset($_POST['comment_ID'])) {
                $id_parent = $_POST['comment_ID'];
                if($this->options['mail_notify'] === 'parent_check'){
                    $request = $wpdb->get_row("SELECT comment_mail_notify FROM $wpdb->comments WHERE comment_ID='$id_parent'");
                    $sendemail = $request->comment_mail_notify;
                } else {
                    $sendemail = 1;
                }
            }
            if ($sendemail == 0) {
                return $id;
            }
            $comment_parent = mysql_escape_string($_POST['comment_ID']);
            $comment_post = mysql_escape_string($_POST['comment_post_ID']);
        } else {
            $comment_parent = mysql_escape_string($_POST['comment_parent']);
            $comment_post = mysql_escape_string($_POST['comment_post_ID']);
        }

        if($this->options['mail_notify'] != 'none'){
            $this->mailer($id,$comment_parent,$comment_post);
        }
        return $id;
    }

    function add_mail_reply($id){
        global $wpdb;

        if(isset($_POST['comment_mail_notify'])){
            $i = 0;
            if($wpdb->query("Describe {$wpdb->comments} comment_mail_notify") == 0 && $i < 10){
                $wpdb->query("ALTER TABLE {$wpdb->comments} ADD COLUMN comment_mail_notify TINYINT NOT NULL DEFAULT 0;");
                $i++;
            }
            $wpdb->query("UPDATE {$wpdb->comments} SET comment_mail_notify='1' WHERE comment_ID='$id'");
        }

        return $id;
    }

    function mailer($id,$parent_id,$comment_post_id){
        global $wpdb, $user_ID, $userdata;

        $post = get_post($comment_post_id);

        if(empty($post)){
            unset($post);
            return false;
        }

        if($this->options['mail_notify'] == 'admin'){
            $cap = $wpdb->prefix . 'capabilities';
            if((strtolower((string) array_shift(array_keys((array)($userdata->$cap)))) !== 'administrator') && ((int)$post->post_author !== (int)$user_ID)){
                unset($post, $cap);
                return false;
            }
        }

        //$parent_email = trim($wpdb->get_var("SELECT comment_author_email FROM {$wpdb->comments} WHERE comment_ID='$parent_id'"));
        $pc = get_comment($parent_id);
        if(empty($pc)){
            unset($pc);
            return false;
        }

        if(intval($pc->comment_mail_notify) === 0 && ($this->options['mail_notify'] === 'parent_uncheck' || $this->options['mail_notify'] === 'parent_check')){
            unset($pc);
            return false;
        }

        $parent_email = trim($pc->comment_author_email);

        if(empty($parent_email) || !is_email($parent_email)){
            unset($pc, $parent_email);
            return false;
        }

        $cc = get_comment($id);
        if(empty($cc)){
            unset($pc,$cc);
            return false;
        }

        if ($cc->comment_approved != '1')
        {
            unset($pc,$cc);
            return false;
        }

        if($parent_email === trim($cc->comment_author_email)){ // If they do not send a reply e-mail their comments
            unset($pc,$cc);
            return false;
        }

        $mail_subject = $this->options['mail_subject'];
        $mail_subject = str_replace('[blogname]', get_option('blogname'), $mail_subject);
        $mail_subject = str_replace('[postname]', $post->post_title, $mail_subject);

        $mail_message = $this->options['mail_message'];
        $mail_message = str_replace('[pc_date]', mysql2date( get_option('date_format'), $pc->comment_date), $mail_message);
        $mail_message = str_replace('[pc_content]', $pc->comment_content, $mail_message);
        $mail_message = str_replace('[pc_author]', $pc->comment_author, $mail_message);

        $mail_message = str_replace('[cc_author]', $cc->comment_author, $mail_message);
        $mail_message = str_replace('[cc_date]', mysql2date( get_option('date_format'), $cc->comment_date), $mail_message);
        $mail_message = str_replace('[cc_url]', $cc->comment_url, $mail_message);
        $mail_message = str_replace('[cc_content]', $cc->comment_content, $mail_message);

        $mail_message = str_replace('[blogname]', get_option('blogname'), $mail_message);
        $mail_message = str_replace('[blogurl]', get_option('home'), $mail_message);
        $mail_message = str_replace('[postname]', $post->post_title, $mail_message);

        //$permalink = get_permalink($comment_post_id);
        $permalink =  get_comment_link($parent_id);

        //$mail_message = str_replace('[commentlink]', $permalink . "#comment-{$parent_id}", $mail_message);
        $mail_message = str_replace('[commentlink]', $permalink, $mail_message);

        $wp_email = 'no-reply@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME']));
        $from = "From: \"".get_option('blogname')."\" <$wp_email>";

        $mail_headers = "$from\nContent-Type: text/html; charset=" . get_option('blog_charset') . "\n";

        unset($wp_email, $from, $post, $pc, $cc, $cap, $permalink);

        $mail_message = convert_smilies($mail_message);

        $mail_message = apply_filters('comment_notification_text', $mail_message, $id);
        $mail_subject = apply_filters('comment_notification_subject', $mail_subject, $id);
        $mail_headers = apply_filters('comment_notification_headers', $mail_headers, $id);

        wp_mail($parent_email, $mail_subject, $mail_message, $mail_headers);
        unset($mail_subject,$parent_email,$mail_message, $mail_headers);

        return true;
    }

    function addreplyidformfield(){
        if($this->options['mail_notify'] === 'parent_check')
            echo '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" checked="checked" style="width: auto;" /><label for="comment_mail_notify">' . __('Notify me of follow-up comments via e-mail', 'comment-reply-notification') . '</label></p>';
        elseif($this->options['mail_notify'] === 'parent_uncheck')
            echo '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" style="width: auto;" /><label for="comment_mail_notify">' . __('Notify me of follow-up comments via e-mail', 'comment-reply-notification') . '</label></p>';
        else{}
    }

    function displayMessage() {
        if ( $this->message != '') {
            $message = $this->message;
            $status = $this->status;
            $this->message = $this->status = '';
        }

        if ( $message ) {
?>
            <div id="message" class="<?php echo ($status != '') ? $status :'updated '; ?> fade">
                <p><strong><?php echo $message; ?></strong></p>
            </div>
<?php
        }
        unset($message,$status);
    }

    function wpadmin(){
        add_options_page(__('Comment Reply Notification Option','comment-reply-notification'), __('Comment Reply Notification','comment-reply-notification'), 'manage_options', __FILE__, array(&$this,'options_page'));
    }

    function options_page(){

        if(isset($_POST['updateoptions'])){
            foreach((array) $this->options as $key => $oldvalue) {
                $this->options[$key] = (isset($_POST[$key]) && !empty($_POST[$key])) ? stripslashes($_POST[$key]) : $this->defaultoption($key);
            }
            update_option($this->db_options, $this->options);
            $this->message = __('Options saved','comment-reply-notification');
            $this->status = 'updated';
        }elseif( isset($_POST['reset_options']) ){
            $this->resetToDefaultOptions();
            $this->message = __('Plugin confriguration has been reset back to default!','comment-reply-notification');
        }else{}
        $this->displayMessage();
?>

<div class="wrap">
    <style scoped type="text/css">
        div.clearing{border-top:1px solid #2580B2 !important;clear:both;}
    </style>

    <h2>Comment Reply Notification</h2>
    <form method="post" action="">
        <fieldset name="wp_basic_options" class="options">
        <p>
            <strong><?php _e('Email notify the parent commenter when his comment was replied','comment-reply-notification'); ?></strong>
            <br /><br />
            <input type="radio" name="mail_notify" id="do_none" value="none" <?php if ($this->options['mail_notify'] !== 'admin' || $this->options['mail_notify'] !== 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('Disabled','comment-reply-notification'); ?></label>
            <br />
            <input type="radio" name="mail_notify" id="do_admin" value="admin" <?php if ($this->options['mail_notify'] === 'admin') { ?> checked="checked"<?php } ?>/><label><?php _e('Replied by the author of the post or administrator ONLY','comment-reply-notification'); ?></label>
            <br />
            <input type="radio" name="mail_notify" id="do_everyone" value="everyone" <?php if ($this->options['mail_notify'] === 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('Anyone replies','comment-reply-notification'); ?></label>
            <br />
            <input type="radio" name="mail_notify" id="do_parent_check" value="parent_check" <?php if ($this->options['mail_notify'] === 'parent_check') { ?> checked="checked"<?php } ?>/><label><?php _e('Commenter choose to do so(default checked)','comment-reply-notification'); ?></label>
            <br />
            <input type="radio" name="mail_notify" id="do_parent_uncheck" value="parent_uncheck" <?php if ($this->options['mail_notify'] === 'parent_uncheck') { ?> checked="checked"<?php } ?>/><label><?php _e('Commenter choose to do so(default unchecked)','comment-reply-notification'); ?></label>
            <br />
        </p>
        <div class="clearing"></div>
        <div>
            <strong><?php _e('Edit the subject of notification email','comment-reply-notification'); ?></strong>
            <br /><br />
            <input type="text" name="mail_subject" id="mail_subject" value="<?php echo $this->options['mail_subject']; ?>" size="80" />
            <br />
            <p><?php _e('Use TEXT only. As a easier way, you may use the following tags: <strong>[blogname]</strong> for blog name and <strong>[postname]</strong> for comment post name','comment-reply-notification'); ?></p>
            <br />
        </div>
        <div class="clearing"></div>
        <div>
            <strong><?php _e('Edit Notification Message','comment-reply-notification'); ?></strong>
            <br /><br />
            <textarea style="font-size: 90%" name="mail_message" id="mail_message" cols="100" rows="10" ><?php echo $this->options['mail_message']; ?></textarea>
            <br />
            <p><?php _e('Use HTML only. As a easier way, you may use the following tags: <strong>[pc_author]</strong> for parent comment author, <strong>[pc_date]</strong> for parent comment date, <strong>[pc_content]</strong> for parent comment content, <strong>[cc_author]</strong> for child comment author, <strong>[cc_date]</strong> for child comment date, <strong>[cc_url]</strong> for child comment author url, <strong>[cc_content]</strong> for child comment content, <strong>[commentlink]</strong> for parent comment link, <strong>[blogname]</strong> for blog name, <strong>[blogurl]</strong> for blog url and <strong>[postname]</strong> for post name.','comment-reply-notification'); ?></p>
        </div>
        <div class="clearing"></div>
        <div>
            <strong><?php _e('Configuration action of deactivate','comment-reply-notification'); ?></strong>
            <br /><br />
            <label><?php _e('Delete options after deactivate:','comment-reply-notification'); ?></label>
            <input type="checkbox" name="clean_option" id="clean_option" value="yes" <?php if ($this->options['clean_option'] === 'yes') { ?> checked="checked"<?php } ?>/>
            <br />
            <p><?php _e('check box if you want to delete all of options of Comment Reply Notification after deactivate this plugin','comment-reply-notification'); ?></p>
        </div>
        <div class="clearing"></div>
        <p class="submit">
            <input type="submit" class="button-primary" name="updateoptions" value="<?php _e('Update Options','comment-reply-notification'); ?> &raquo;" />
            <input type="submit" class="button" name="reset_options" onclick="return confirm('<?php _e('Do you really want to reset your current configuration?','comment-reply-notification'); ?>');" value="<?php _e('Reset Options','comment-reply-notification'); ?>" />
        </p>
        </fieldset>
    </form>

</div>
<?php
    }  //End options_page()
}      //End class comment_reply_notification
endif;

$new_comment_reply_notification = new comment_reply_notification();

/**
 *  Add settings link on plugin page
 */
function crn_plugin_settings_link($links) {
  $settings_link = '<a href="options-general.php?page=comment-reply-notification/comment-reply-notification.php">Settings</a>';
  array_unshift($links, $settings_link);
  return $links;
}

$plugin = plugin_basename(__FILE__);

add_filter("plugin_action_links_$plugin", 'crn_plugin_settings_link' );
?>
