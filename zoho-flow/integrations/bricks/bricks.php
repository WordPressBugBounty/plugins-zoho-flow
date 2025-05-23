<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * class used for APIs and Webhooks
 *
 * @since zohoflow      2.13.0
 * @since bricks        1.12.3
 */
class Zoho_Flow_Bricks extends Zoho_Flow_Service{

    /**
     *  @var array Webhook events supported.
     */
    public static $supported_events = array(
        "form_entry_submitted"
    );

    /**
     * List forms
     *
     * @param WP_REST_Request $request WP_REST_Request object.
     *
     * Request path param  Mandatory.
     * @type    int     $limit          Number of results. Default: 200.
     * @type    string  $order_by       List order by the field. Default: post_modified.
     * @type    string  $order          List order Values: ASC|DESC. Default: DESC.
     *
     * @return WP_REST_Response    WP_REST_Response array with form details
     */
    public function list_forms( $request ){
        $order_by_allowed = array(
            'ID',
            'post_date',
            'post_date_gmt',
            'post_modified',
            'post_modified_gmt'
        );
        $order_allowed = array('ASC', 'DESC');
        $order_by = ( isset( $request['order_by'] ) && ( in_array($request['order_by'], $order_by_allowed ) ) ) ? $request['order_by'] : 'post_modified';
        $order = ( isset( $request['order'] ) && ( in_array( $request['order'], $order_allowed ) ) ) ? $request['order'] : 'DESC';
        $limit = isset( $request['limit'] ) ? $request['limit'] : '200';
        $args = array(
            'post_type' => 'page',
            'posts_per_page' => $limit,
            'orderby'        => $order_by,
            'order'          => $order,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_bricks_page_content_2',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_bricks_page_content_2',
                    'value'   => 'form',
                    'compare' => 'LIKE',
                ),
                array(
                    'key'     => '_bricks_page_content_2',
                    'value'   => 'custom',
                    'compare' => 'LIKE',
                ),
            ),
        );
        $posts = get_posts( $args );
        
        $return_array = array();
        foreach( $posts as $post ){
            $form = $this->get_forms( $post->ID, $post->post_title, get_post_meta( $post->ID, '_bricks_page_content_2', true ) );
            if( !empty( $form ) ){
                $return_array = array_merge( $return_array, $form );
            }
        }
        return rest_ensure_response( $return_array );
    }

    private function get_forms( $post_id, $post_title, $form_content ){
        $forms = array();
        if( is_array( $form_content ) && !empty( $form_content ) ){
            foreach ( $form_content as $block ){
                if( ( $block['name'] === 'form' ) && ( is_array( $block['settings']['actions'] ) ) && ( in_array( 'custom', $block['settings']['actions'] ) ) ){
                    $forms[] = array(
                        'id' => $block['id'],
                        'name' => ( !empty( $block['label'] ) ? $block['label'] : $block['name'] ),
                        'post_id' => $post_id,
                        'post_title' => $post_title
                    );
                }
            }
        }
        return $forms;
    }

    /**
     * List form fields
     * 
     * @param WP_REST_Request $request WP_REST_Request object.
     * 
     * Request path param  Mandatory.
     * @type    int     $post_id    ID of the post.
     * @type    int     $form_id    Form block ID.
     * 
     * @return WP_REST_Response    WP_REST_Response array with form field details
     */
    public function list_form_fields( $request ){
        $post_id = $request->get_url_params()['post_id'];
        $form_id = $request->get_url_params()['form_id'];
        $fields = $this->get_form_fields( $form_id, get_post_meta( $post_id, '_bricks_page_content_2', true ) );
            if( $fields ){
                return rest_ensure_response( $fields );
            }
        return new WP_Error( 'rest_bad_request', 'Invalid Form', array( 'status' => 400 ) );
    }

    private function get_form_fields( $form_id, $form_content ){
        $forms = array();
        if( is_array( $form_content ) && !empty( $form_content ) ){
            foreach ( $form_content as $block ){
                if( ( $block['name'] === 'form' ) && ( $block['id'] === $form_id ) ){
                    return $block['settings']['fields'];
                }
            }
        }
        return false;
    }

    /**
     * Check whether the Form ID is valid or not.
     *
     * @param int $post_id  Post ID.
     * @param int $form_id  Form ID.
     * @return boolean  true if the form exists | false for others.
     */
    private function is_valid_form( $post_id, $form_id ){
        if( isset( $post_id ) && isset( $form_id ) ){
            $fields = $this->get_form_fields( $form_id, get_post_meta( $post_id, '_bricks_page_content_2', true ) );
            if( $fields ){
                return true;
            }
        }
        return false;
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
        if( ( !isset( $entry->post_id ) && !isset( $entry->form_id ) ) || !$this->is_valid_form( $entry->post_id, $entry->form_id ) ){
            return new WP_Error( 'rest_bad_request', "Form does not exist!", array( 'status' => 400 ) );
        }
        if( ( isset( $entry->name ) ) && ( isset( $entry->url ) ) && ( isset( $entry->event ) ) && ( in_array( $entry->event, self::$supported_events ) ) && ( preg_match( "/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i", $entry->url ) ) ){
            $args = array(
                'name' => $entry->name,
                'url' => $entry->url,
                'event' => $entry->event,
                'post_id' => $entry->post_id,
                'form_id' => $entry->form_id
            );
            $post_name = "Bricks ";
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
     * Fires after entry is processed.
     *
     * @param Bricks\Integrations\Form\Init   $form_info        Contact form info.
     */
    public function payload_form_entry_submitted( $form_info ){
        $entry_details = $form_info->get_fields();
        $args = array(
            'event' => 'form_entry_submitted',
            'form_id' => $entry_details['formId'],
            'post_id' => $entry_details['postId']
        );
        $webhooks = $this->get_webhook_posts( $args );
        if( !empty( $webhooks ) ){
            $event_data = array(
                'event' => 'form_entry_submitted',
                'data' => $entry_details
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
        $plugin_dir = ABSPATH . 'wp-content/themes/bricks/style.css';
        if(file_exists( $plugin_dir ) ){
            $plugin_data = get_plugin_data( $plugin_dir );
            $system_info['bricks'] = $plugin_data['Version'];
        }
        return rest_ensure_response( $system_info );
    }
}