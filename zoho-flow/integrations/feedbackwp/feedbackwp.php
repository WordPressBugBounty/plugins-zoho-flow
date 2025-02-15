<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * class used for APIs and Webhooks
 *
 * @since zohoflow      2.11.0
 * @since feedbackwp    4.2.3
 */
class Zoho_Flow_New_FeedbackWP extends Zoho_Flow_Service{
    
    /**
     *  @var array Webhook events supported.
     */
    public static $supported_events = array(
        "rating_added",
        "feedback_added"
    );
    
    /**
     * Creates a webhook entry
     * The events available in $supported_events array only accepted
     *
     * @param WP_REST_Request   $request  WP_REST_Request object.
     * @return WP_REST_Response|WP_Error    WP_REST_Response array with Webhook ID | WP_Error object with error details.
     */
    public function create_webhook( $request ){
        $entry = json_decode( $request->get_body() );
        if( ( isset( $entry->name ) ) && ( isset( $entry->url ) ) && ( isset( $entry->event ) ) && ( in_array( $entry->event, self::$supported_events ) ) && ( preg_match( "/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i", $entry->url ) ) ){
            $args = array(
                'name' => $entry->name,
                'url' => $entry->url,
                'event' => $entry->event
            );
            $post_name = "FeedbackWP ";
            $post_id = $this->create_webhook_post( $post_name, $args );
            if( is_wp_error( $post_id ) ){
                $errors = $post_id->get_error_messages();
                return new WP_Error( 'rest_bad_request', $errors, array( 'status' => 400 ) );
            }
            return rest_ensure_response(
                array(
                    'webhook_id' => $post_id
                ) );
        }
        else{
            return new WP_Error( 'rest_bad_request', 'Data validation failed', array( 'status' => 400 ) );
        }
    }
    
    /**
     * Deletes a webhook entry
     * Webhook ID returned from webhook create event should be used. Use minimum user scope.
     *
     * @param WP_REST_Request   $request    WP_REST_Request object.
     * @return WP_REST_Response|WP_Error    WP_REST_Response array with success message | WP_Error object with error details.
     */
    public function delete_webhook( $request ){
        $webhook_id = $request->get_url_params()['webhook_id'];
        if( is_numeric( $webhook_id ) ){
            $webhook_post = $this->get_webhook_post( $webhook_id );
            if( !empty( $webhook_post[0]->ID ) ){
                $delete_webhook = $this->delete_webhook_post( $webhook_id );
                if( is_wp_error( $delete_webhook ) ){
                    $errors = $delete_webhook->get_error_messages();
                    return new WP_Error( 'rest_bad_request', $errors, array( 'status' => 400 ) );
                }
                else{
                    return rest_ensure_response( array( 'message' => 'Success' ) );
                }
            }
            else{
                return new WP_Error( 'rest_bad_request', 'Invalid webhook ID', array( 'status' => 400 ) );
            }
        }
        else{
            return new WP_Error( 'rest_bad_request', 'Invalid webhook ID', array( 'status' => 400 ) );
        }
    }
    
    /**
     * Fires after rating is added.
     *
     * @param   int     $post_id            ID of the post.
     * @param   float   $avg_rating         Average rating of the post.
     * @param   int     $new_vote_count     Total count of rating.
     * @param   int     $submitted_rating   Rating.
     */
    public function payload_rating_added( $post_id, $avg_rating, $new_vote_count, $submitted_rating ){
        $args = array(
            'event' => 'rating_added',
        );
        $webhooks = $this->get_webhook_posts( $args );
        if( !empty( $webhooks ) ){
            $post = get_post( $post_id, 'ARRAY_A' );
            unset(
                $post['post_content'],
                $post['post_excerpt'],
                $post['ping_status'],
                $post['post_password'],
                $post['to_ping'],
                $post['pinged'],
                $post['post_content_filtered'],
                $post['menu_order'],
                $post['filter'],
                );
            $post['post_id'] = $post_id;
            $post['avg_rating'] = $avg_rating;
            $post['new_vote_count'] = $new_vote_count;
            $post['submitted_rating'] = $submitted_rating;
            $event_data = array(
                'event' => 'rating_added',
                'data' => $post
            );
            foreach( $webhooks as $webhook ){
                $url = $webhook->url;
                zoho_flow_execute_webhook( $url, $event_data, array() );
            }
        }
    }
    
    /**
     * Fires after feedback is added.
     *
     * @param   int         $post_id      ID of the post.
     * @param   string      $feedback     Feedback text.
     */
    public function payload_feedback_added( $post_id, $feedback ){
        $args = array(
            'event' => 'feedback_added',
        );
        $webhooks = $this->get_webhook_posts( $args );
        if( !empty( $webhooks ) ){
            $post = get_post( $post_id, 'ARRAY_A' );
            unset(
                $post['post_content'],
                $post['post_excerpt'],
                $post['ping_status'],
                $post['post_password'],
                $post['to_ping'],
                $post['pinged'],
                $post['post_content_filtered'],
                $post['menu_order'],
                $post['filter'],
                );
            $post['post_id'] = $post_id;
            $post['feedback'] = $feedback;
            $event_data = array(
                'event' => 'feedback_added',
                'data' => $post
            );
            foreach( $webhooks as $webhook ){
                $url = $webhook->url;
                zoho_flow_execute_webhook( $url, $event_data, array() );
            }
        }
    }
    
    /**
     * Default API
     * Get user and system info.
     *
     * @return array|WP_Error System and logged in user details | WP_Error object with error details.
     */
    public function get_system_info(){
        $system_info = parent::get_system_info();
        if( ! function_exists( 'get_plugin_data' ) ){
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }
        $plugin_dir = ABSPATH . 'wp-content/plugins/rate-my-post/rate-my-post.php';
        if(file_exists( $plugin_dir ) ){
            $plugin_data = get_plugin_data( $plugin_dir );
            $system_info['feedbackwp'] = $plugin_data['Version'];
        }
        return rest_ensure_response( $system_info );
    }
}