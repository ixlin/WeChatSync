<div class="wrap">
    <h1>微信公众号同步设置</h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('wechat_sync_options'); ?>
        
        <table class="form-table">
            <tr>
                <th>AppID</th>
                <td>
                    <input type="text" name="wechat_appid" 
                           value="<?php echo esc_attr(get_option('wechat_appid')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th>AppSecret</th>
                <td>
                    <input type="password" name="wechat_secret" 
                           value="<?php echo esc_attr(get_option('wechat_secret')); ?>" 
                           class="regular-text">
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>

    <h2>待同步文章</h2>
    <?php
    $unsynced_posts = get_posts([
        'meta_query' => [
            [
                'key' => '_wechat_synced',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ]);
    ?>
    
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="wechat_sync">
        <?php wp_nonce_field('wechat_sync_action'); ?>
        
        <table class="wp-list-table widefat fixed striped">
            <?php foreach ($unsynced_posts as $post): ?>
            <tr>
                <td>
                    <input type="checkbox" name="post_ids[]" value="<?php echo $post->ID; ?>">
                    <?php echo $post->post_title; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <?php submit_button('同步选中文章'); ?>
    </form>
</div>