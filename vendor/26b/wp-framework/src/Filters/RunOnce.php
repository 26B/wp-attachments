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
     * @since 0.0.0 Added condition parameter.
     * @since 1.4.0
     *
     * @param string $hook_name The name of the filter hook.
     * @param callable $callback The callback function to be executed.
     * @param int $priority The priority of the filter. Default is 10.
     * @param int $accepted_args The number of arguments the callback accepts. Default is 1.
     * @param callable|null $condition An optional callable that returns a truthy value to determine if the filter should run, and then be removed.
     * @return void
     */
    public static function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1, ?callable $condition = null ) {
        $callback_wrapper = null;
        $callback_wrapper = function () use ( &$callback_wrapper, $hook_name, $callback, $priority, $condition ) {
            if ( is_callable( $condition ) && ! call_user_func_array( $condition, func_get_args() ) ) {
                // Condition not met, do not run or remove the filter, and return the first argument.
                return array_shift( func_get_args() );
            }

            remove_filter( $hook_name, $callback_wrapper, $priority );
            return call_user_func_array( $callback, func_get_args() );
        };
        add_filter( $hook_name, $callback_wrapper, $priority, $accepted_args );
    }

    /**
     * Add a action that will only run once.
     *
     * @since 0.0.0 Added condition parameter.
     * @since 1.4.0
     *
     * @param string $hook_name The name of the action hook.
     * @param callable $callback The callback function to be executed.
     * @param int $priority The priority of the action. Default is 10.
     * @param int $accepted_args The number of arguments the callback accepts. Default is 1.
     * @param callable|null $condition An optional callable that returns a truthy value to determine if the action should run, and then be removed.
     * @return void
     */
    public static function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1, ?callable $condition = null ) {
        $callback_wrapper = null;
        $callback_wrapper = function () use ( &$callback_wrapper, $hook_name, $callback, $priority, $condition ) {
            if ( is_callable( $condition ) && ! call_user_func_array( $condition, func_get_args() ) ) {
                // Condition not met, do not run or remove the action, and return early.
                return;
            }

            remove_action( $hook_name, $callback_wrapper, $priority );
            return call_user_func_array( $callback, func_get_args() );
        };
        add_action( $hook_name, $callback_wrapper, $priority, $accepted_args );
    }
}
