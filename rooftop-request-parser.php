<?php
/*
Plugin Name: Rooftop CMS - Request Parser
Description: Manipulate the REST request or server variables
Version: 1.2.2
Author: RooftopCMS
Author URI: https://rooftopcms.com
Plugin URI: http://github.com/rooftopcms/rooftop-request-parser
License: GPL-3.0+
License URI: http://www.gnu.org/licenses/gpl-3.0.txt
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

            $deny_access_to_media_item = $post->post_type == 'attachment' && !in_array( $post->post_status, array( 'inherit', 'publish' ) );
            $deny_access_to_draft_post = $post->post_status != 'publish' && !$include_drafts;

            if( $deny_access_to_media_item ) {
                $response = new Custom_WP_Error( 'unauthorized', 'Authentication failed', array( 'status'=>403 ) );
            }elseif( $post->post_type != "attachment" && $deny_access_to_draft_post ) {
                $response = new Custom_WP_Error( 'unauthorized', 'Authentication failed', array( 'status'=>403 ) );
            }

            return $response;
        });

        // add support for the filter parameter: https://github.com/WP-API/rest-filter/blob/master/plugin.php

        add_filter( "rest_${type}_query", function( $args, $request) {
            if( empty( $request['filter'] ) || !is_array( $request['filter'] ) ) {
                return $args;
            }

            $filter = $request['filter'];
            if ( isset( $filter['per_page'] ) && ( (int) $filter['per_page'] >= 1 ) ) {
                $args['per_page'] = $filter['per_page'];
            }
            global $wp;
            $vars = apply_filters( 'query_vars', $wp->public_query_vars );
            foreach ( $vars as $var ) {
                if ( isset( $filter[ $var ] ) ) {
                    $args[ $var ] = $filter[ $var ];
                }
            }

            return $args;
        }, 10, 2);

        add_filter( "rest_${type}_collection_params", function( $params, $type ) {
            if( isset( $params['order'] ) && isset( $params['order']['enum'] ) ) {
                $params['order']['enum'] = array_merge($params['order']['enum'], array('ASC','DESC'));
            }
            return $params;
        }, 10, 2 );
    }
}, 10);

/**
 * Change some of the default query limits set in wp-api.
 * Ie. Max per_page collection arg to 99999999 (effectively removing the limit)
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

    // by default, we can only orderby a limited set of attributes
    $permitted_orderby_values = array( 'none', 'author', 'modified', 'name', 'type', 'parent', 'menu_order', 'meta_value', 'meta_value_num', 'post__in', 'post_name__in' );

    foreach( $endpoints as $endpoint => $resource ) {
        foreach( $resource as $object => $params ) {
            if( $object == 'args' && isset( $params['args']['orderby'] ) && isset( $params['args']['orderby']['enum'] ) ) {
                $endpoints[$endpoint][$object]['args']['orderby']['enum'] = array_merge( $endpoints[$endpoint][$object]['args']['orderby']['enum'], $permitted_orderby_values );
            }
            if( $object == 'args' && isset( $params['args']['per_page'] ) ) {
                $endpoints[$endpoint][$object]['args']['per_page']['minimum'] = -1;
                $endpoints[$endpoint][$object]['args']['per_page']['maximum'] = 99999999;
            }
        }
    }

    $endpoints_property->setValue( $wp_rest_server, $endpoints );
}, 100 );

add_action( 'rest_pre_dispatch', function( $served, $server, $request ) {
    $per_page = @$_GET['per_page'];
    if( $per_page == "" && !$served ) {
        $request->set_param( 'per_page', 10);
    }

    // add permitted filter keys as we need to ensure backwards compatibility between wp-api beta15
    // and our client libs, which send post__in in the filter parameter, rather than include as a parameter
    $parameter_mappings = array(
        'author__in'          => 'author',
        'author__not_in'      => 'author_exclude',
        'post__not_in'        => 'exclude',
        'post__in'            => 'include',
        'menu_order'          => 'menu_order',
        'offset'              => 'offset',
        'order'               => 'order',
        'orderby'             => 'orderby',
        'paged'               => 'page',
        'post_parent__in'     => 'parent',
        'post_parent'         => 'parent',
        'post_parent__not_in' => 'parent_exclude',
        's'                   => 'search',
        'post_name__in'       => 'slug',
        'post_status'         => 'status',
        'posts_per_page'      => 'per_page'
    );

    // remove any non-integer values in our filters (this is for some backwards compatibility with our RT clients.
    // arguments passed on the query string (not in filters[]) wont go through this cleanup - they need to be valid args.
    $integer_filter_types = ['author__in', 'author__not_in', 'post__not_in', 'post__in', 'post_parent__in', 'post_parent', 'post_parent__not_in'];
    foreach( $integer_filter_types as $filter_param ) {
        $request_filters = $request['filter'];

        if( isset( $request['filter'][$filter_param] ) ) {
            if( is_array( $request['filter'][$filter_param] ) ) {
                $filter_value = array_map( function( $i) {
                    return (int)$i;
                }, $request_filters[$filter_param] );

                $filter_value = array_filter( $filter_value );
            }else {
                $filter_value = (int)$request['filter'][$filter_param];
            }

            $request_filters[$filter_param] = $filter_value;
        }

        $request->set_param( 'filter', $request_filters );
    }

    foreach( $parameter_mappings as $param => $key ) {
        if( isset( $request['filter'] ) && isset( $request['filter'][$param] ) ) {
            $request->set_param( $key, $request['filter'][$param] );
        }
    }
}, 1, 4);


add_filter( 'query_vars', function( $vars ) {
    $vars = array_merge( $vars, array( 'meta_key', 'meta_value', 'meta_compare', 'meta_query', 'tax_query' ) );
    return $vars;
}, 1, 1 );
?>
