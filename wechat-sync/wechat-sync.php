<?php
/*
Plugin Name: WeChat Sync
Description: 同步文章到微信公众号
Version: 1.0
Author: sfrost
Author URI: http://sfrost.cn
*/

// 基础配置
define('WECHAT_SYNC_DIR', plugin_dir_path(__FILE__));
define('WECHAT_SYNC_URL', plugin_dir_url(__FILE__));

// 包含必要文件
require_once WECHAT_SYNC_DIR . 'includes/class-wechat-api.php';
require_once WECHAT_SYNC_DIR . 'includes/class-sync-manager.php';

// 初始化组件
// register_activation_hook(__FILE__, ['WeChat_Sync', 'activate']);
// register_deactivation_hook(__FILE__, ['WeChat_Sync', 'deactivate']);

class WeChat_Sync {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    // 注册设置项
    public static function register_settings() {
        register_setting('wechat_sync_options', 'wechat_appid');
        register_setting('wechat_sync_options', 'wechat_secret');
        register_setting('wechat_sync_options', 'wechat_token');
    }

    // 添加管理菜单
    public static function add_admin_menu() {
        add_menu_page(
            '微信同步设置',
            '微信同步',
            'manage_options',
            'wechat-sync',
            [__CLASS__, 'settings_page'],
            'dashicons-share'
        );
    }

    // 设置页面模板
    public static function settings_page() {
        include WECHAT_SYNC_DIR . 'templates/settings.php';
    }
}

WeChat_Sync::init();