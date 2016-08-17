<?php
/*
Plugin Name: Rooftop CMS - Request Parser
Description: Manipulate the REST request or server variables
Version: 0.0.1
Author: Error Studio
Author URI: http://errorstudio.co.uk
Plugin URI: http://errorstudio.co.uk
Text Domain: rooftop-request-parser
*/

require_once dirname( __FILE__ ) . '/class-rooftop-custom-errors.php';

/**
 * return a bool based on the current request headers or path.
 * if we're in preview mode, or including drafts - return true.
 */
add_filter( 'rooftop_include_drafts', function() {
    $preview_mode   = strtolower( @$_SERVER["HTTP_PREVIEW"] )== "true";
    $include_drafts = strtolower( @$_SERVER["HTTP_INCLUDE_DRAFTS"] ) == "true";
    $preview_route  = preg_match( "/\/preview$/", parse_url( @$_SERVER["REQUEST_URI"] )['path'] );

    if( $preview_mode || $include_drafts || $preview_route ) {
        return true;
    }else {
        return false;
    }
}, 1);

/**
 * return an array of valid post statuses based on the current request.
 */
add_filter( 'rooftop_published_statuses', function() {
    if( apply_filters( 'rooftop_include_drafts', false ) ) {
        return array( 'publish', 'draft', 'scheduled', 'pending' );
    }else {
        return array( 'publish' );
    }
}, 1);

/**
 * if any of the post types are in anything but a published state and we're NOT in preview mode,
 * we should send back a response which mimics the WP_Error auth failed response
 *
 * note: we need to add this hook since we can't alter the query args on a single-resource endpoint (rest_post_query is only called on collections)
 */
add_action( 'rest_api_init', function() {
    $types = get_post_types( array( 'public' => true, 'show_in_rest' => true ) );

    foreach( $types as $key => $type ) {
        add_action( "rest_prepare_$type", function( $response ) {
            global $post;

            $include_drafts = apply_filters( 'rooftop_include_drafts', false );
            if( $post->post_status != 'publish' && !$include_drafts ) {
                $response = new Custom_WP_Error( 'unauthorized', 'Authentication failed', array( 'status'=>403 ) );
            }
            return $response;
        });
    }
}, 10);

/**
 * Change the maximum per_page collection arg to 99999999 (effectively removing the limit)
 */
add_action( 'rest_api_init', function() {
    global $wp_rest_server;

    /**
     * The 'endpoints' property is protected; use reflection to get the property, mutate it, then re-set it
     */
    $reflection = new ReflectionClass( $wp_rest_server );
    $endpoints_property = $reflection->getProperty('endpoints');
    $endpoints_property->setAccessible( true );
    $endpoints = $endpoints_property->getValue( $wp_rest_server );

    foreach( $endpoints as $endpoint => $resource ) {
        foreach( $resource as $object => $params ) {
            if( $object == 'args' && isset( $params['args']['per_page'] ) ) {
                $endpoints[$endpoint][$object]['args']['per_page']['maximum'] = 99999999;
            }
        }
    }

    $endpoints_property->setValue( $wp_rest_server, $endpoints );
}, 11 );

add_action( 'rest_pre_dispatch', function( $served, $server, $request ) {
    $per_page = @$_GET['per_page'];
    if( $per_page == "" && !$served ) {
        $request->set_param( 'per_page', 10);
    }
}, 1, 4);
?>