<?php

namespace TenupFramework\Filters;

/**
 * This class provides methods to create actions and filters that only run once.
 *
 * @package TenupFramework\Filters
 * @since 1.4.0
 */
class RunOnce {

    /**
     * Add a filter that will only run once.
     * 
     * @since 1.4.0
     * 
     * @param string $hook_name The name of the filter hook.
     * @param callable $callback The callback function to be executed.
     * @param int $priority The priority of the filter. Default is 10.
     * @param int $accepted_args The number of arguments the callback accepts. Default is 1.
     * @return void
     */
    public static function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        $callback_wrapper = null;
        $callback_wrapper = function () use ( &$callback_wrapper, $hook_name, $callback, $priority ) {
            remove_filter( $hook_name, $callback_wrapper, $priority );
            return call_user_func_array( $callback, func_get_args() );
        };
        add_filter( $hook_name, $callback_wrapper, $priority, $accepted_args );
    }

    /**
     * Add a action that will only run once.
     * 
     * @since 1.4.0
     * 
     * @param string $hook_name The name of the action hook.
     * @param callable $callback The callback function to be executed.
     * @param int $priority The priority of the action. Default is 10.
     * @param int $accepted_args The number of arguments the callback accepts. Default is 1.
     * @return void
     */
    public static function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        $callback_wrapper = null;
        $callback_wrapper = function () use ( &$callback_wrapper, $hook_name, $callback, $priority ) {
            remove_action( $hook_name, $callback_wrapper, $priority );
            return call_user_func_array( $callback, func_get_args() );
        };
        add_action( $hook_name, $callback_wrapper, $priority, $accepted_args );
    }
}
