<?php
/*
Plugin Name: Comment Reply Notification
Plugin URI:  http://fairyfish.net/2008/11/03/comment-reply-notification/
Description:  When a reply is made to a comment the user has left on the blog, an e-mail shall be sent to the user to notify him of the reply. This will allow the users to follow up the comment and expand the conversation if desired. 
Version: 0.1
Author: Denis
*/
$mail_options = array(
	'mail_subject' => get_option('blogname').' -- Reply Notification',
	'mail_message' => "<p>This message is to inform you that someone has responded to your comment on <strong>[postname]</strong> at ".get_option('blogname').".</p>\r\n<p>Your comment: <br />\r\n[parent_comment_content]</p>\r\n<p>Reply:<br />\r\n[current_comment_content]</p>\r\n<p>You can check the post yourself by clicking here:<br />\r\n<a href=\"[commentlink]\">[commentlink]</a></p>\r\n<p>Thank you for contributing to <a href=\"".get_option('siteurl')."\">".get_option('blogname')."</a>. -- Powered by <a href=\"http://fairyfish.net/2008/11/03/comment-reply-notification/\">Comment Reply Notification</a>.</p> <p><strong>Please do not respond to this e-mail as it is not monitored.</strong></p>"
);

function reply_email_notification ($id) {
	//global $user_ID, $userdata,$mail_options;
	global $mail_options;
	
	$comment_post_id = $_POST['comment_post_ID'];
	$post = get_post($comment_post_id);
	
	$parent_id = $_POST['comment_parent'];
	$parent_comment = get_comment($parent_id);
	$current_comment = get_comment($id);
	
	$parent_email = $parent_comment->comment_author_email;

	if(empty($parent_email) || !is_email($parent_email)){
		unset($parent_email);
		return;
	}

	$mail_subject = $mail_options['mail_subject'];
	$mail_message = $mail_options['mail_message'];
	$mail_message = str_replace('[parent_comment_content]', $parent_comment->comment_content, $mail_message);
	$mail_message = str_replace('[current_comment_content]', $current_comment->comment_content, $mail_message);

	$mail_message = str_replace('[postname]', $post->post_title, $mail_message);
	$mail_message = str_replace('[commentlink]', get_permalink($comment_post_id)."#comment-{$parent_id}", $mail_message);
	
	$mail_to = 'no-reply@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME']));
	$from = "From: \"".get_option('blogname')."\" <$mail_to>";

	$mail_headers = "$from\nContent-Type: text/html; charset=" . get_option('blog_charset') . "\n";
	
	$mail_message = apply_filters('comment_notification_text', $mail_message, $id);
	$mail_subject = apply_filters('comment_notification_subject', $mail_subject, $id);
	$mail_headers = apply_filters('comment_notification_headers', $mail_headers, $id);

	@wp_mail($parent_email, $mail_subject, $mail_message, $mail_headers);
	unset($mail_subject,$parent_email,$mail_message, $mail_headers);
	
	return;
}

add_action('comment_post', 'reply_email_notification',1000);
?>