<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * class used for APIs and Webhooks
 *
 * @since zohoflow      2.10.0
 * @since sureforms     1.0.4
 */
class Zoho_Flow_SureForms extends Zoho_Flow_Service{
    
    /**
     *  @var array Webhook events supported.
     */
    public static $supported_events = array(
        "form_entry_submitted",
    );
    
    /**
     * List forms
     *
     * @param WP_REST_Request $request WP_REST_Request object.
     *
     * @return WP_REST_Response|WP_Error    WP_REST_Response array of form details | WP_Error object with error details.
     */
    public function list_forms( $request ){
        $args = [
            'post_type'      => 'sureforms_form',
            'post_status'    => 'publish',
            'posts_per_page' => -1, // Retrieve all posts.
        ];
        
        $form_posts = get_posts( $args );
        
        $forms_return_list = [];
        
        foreach ( $form_posts as $post ) {
            $forms_return_list[] = array(
                'id'      => $post->ID,
                'name'   => $post->post_title
            );
        }
        return rest_ensure_response( $forms_return_list );
    }
    
    /**
     * List form fields
     *
     * @param WP_REST_Request $request WP_REST_Request object.
     *
     * Request path param  Mandatory.
     * @type int  form_id   Form ID to retrive the fields for.
     *
     * @return WP_REST_Response|WP_Error    WP_REST_Response array with form field details | WP_Error object with error details.
     */
    public function list_form_fields( $request ){
        $form_id = $request['form_id'];
        if( $this->is_valid_form( $form_id ) ){
            $form = get_post( $form_id, 'ARRAY_A' );
            return rest_ensure_response( parse_blocks( $form[ 'post_content' ] ) );
        }
        return new WP_Error( 'rest_bad_request', "Form does not exist!", array( 'status' => 404 ) );
    }
    
    /**
     * Check whether the Form ID is valid or not.
     *
     * @param int $form_id  Form ID.
     * @return boolean  true if the form exists | false for others.
     */
    private function is_valid_form( $form_id ){
        if( isset( $form_id ) ){
            if( "sureforms_form" === get_post_type( $form_id ) ){
                return true;
            }
            return false;
        }
        else{
            return false;
        }
    }
    
    /**
     * Creates a webhook entry
     * The events available in $supported_events array only accepted
     *
     * @param WP_REST_Request   $request  WP_REST_Request object.
     * @return WP_REST_Response|WP_Error    WP_REST_Response array with Webhook ID | WP_Error object with error details.
     */
    public function create_webhook( $request ){
        $entry = json_decode( $request->get_body() );
        if( ( !isset( $entry->form_id ) ) || !$this->is_valid_form( $entry->form_id ) ){
            return new WP_Error( 'rest_bad_request', "Form does not exist!", array( 'status' => 400 ) );
        }
        if( ( isset( $entry->name ) ) && ( isset( $entry->url ) ) && ( isset( $entry->event ) ) && ( in_array( $entry->event, self::$supported_events ) ) && ( preg_match( "/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i", $entry->url ) ) ){
            $args = array(
                'name' => $entry->name,
                'url' => $entry->url,
                'event' => $entry->event,
                'form_id' => $entry->form_id
            );
            $post_name = "SureForms ";
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
     * Fires after entry is submitted.
     *
     * @param   array   form_submit_response  Form entry details array.
     */
    public function payload_form_entry_submitted( $form_submit_response ){
        $form_id = $form_submit_response['form_id'];
        $args = array(
            'event' => 'form_entry_submitted',
            'form_id' => $form_id
        );
        $webhooks = $this->get_webhook_posts( $args );
        if( !empty( $webhooks ) ){
            $event_data = array(
                'event' => 'form_entry_submitted',
                'data' => $form_submit_response['data']
            );
            foreach( $webhooks as $webhook ){
                $event_data['id'] = $webhook->ID;
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
        $plugin_dir = ABSPATH . 'wp-content/plugins/sureforms/sureforms.php';
        if(file_exists( $plugin_dir ) ){
            $plugin_data = get_plugin_data( $plugin_dir );
            $system_info['sureforms'] = $plugin_data['Version'];
        }
        return rest_ensure_response( $system_info );
    }
}