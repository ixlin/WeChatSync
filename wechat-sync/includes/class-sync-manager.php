<?php
class Sync_Manager {
    public static function init() {
        add_action('admin_post_wechat_sync', [__CLASS__, 'handle_sync']);
        // add_action('admin_notices', [__CLASS__, 'show_sync_notices']);
    }

    // 处理同步请求
    public static function handle_sync() {
        check_admin_referer('wechat_sync_action');
        
        $post_ids = $_POST['post_ids'] ?? [];
        $appid = get_option('wechat_appid');
        $secret = get_option('wechat_secret');
        $token = get_option('wechat_access_token'); //wechat_token

        $wechat = new WeChat_API($appid, $secret, $token);
        
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);

            // 处理文章内容
            $content = apply_filters('the_content', $post->post_content);
            $content = self::replace_local_images_with_wechat($content, $wechat);

            // 清理 WordPress 特定短代码和样式
            $content = preg_replace('/\[.*?\]/', '', $content); // 移除短代码
            $content = preg_replace('/<style>.*?<\/style>/s', '', $content); // 移除内联 CSS
            $content = strip_tags($content, '<h1><h2><h3><p><a><img><strong><em><ul><ol><li><br>'); // 允许的标签
            $content = self::convert_to_wechat_html($content);

            $article = [
                'title' => $post->post_title,
                'content' => $content,
                'thumb_media_id' => self::upload_featured_image($post->ID, $wechat),
                'show_cover_pic' => 1,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'digest' => wp_trim_words($post->post_excerpt, 100)
            ];
            error_log(print_r($article, true));
            $result = $wechat->upload_news([$article]); // 上传图文消息到微信公众号，成功：返回media_id
            error_log(print_r($result,true));
            if ($result['media_id']) {
                update_post_meta($post_id, '_wechat_synced', 1);
                
            }
        }

        wp_redirect(admin_url('admin.php?page=wechat-sync&sync=success'));
        exit;
    }

    
    /**
     * 上传微信封面特色图片到永久素材库
     * @param mixed $post_id
     * @param mixed $wechat
     */
    private static function upload_featured_image($post_id, $wechat) {
        // 1. 获取文章的特色图片附件ID
        $attachment_id = get_post_thumbnail_id($post_id);
        if (empty($attachment_id)) {
            error_log('文章无特色图片: Post ID ' . $post_id);
            return false;
        }
    
        // 2. 获取图片的物理文件路径
        $image_path = get_attached_file($attachment_id);
        if (!file_exists($image_path)) {
            error_log('特色图片文件不存在: ' . $image_path);
            return false;
        }
    
        // 3. 获取微信 access_token（假设 $wechat 对象有该方法）
        $access_token = $wechat->get_access_token();
        if (empty($access_token)) {
            error_log('获取微信 access_token 失败');
            return false;
        }
    
        // 4. 构造微信素材上传接口 URL
        $api_url = "https://api.weixin.qq.com/cgi-bin/material/add_material?access_token={$access_token}&type=image";
    
        // 5. 准备 cURL 请求
        $ch = curl_init();
        $post_data = [
            'media' => new CURLFile($image_path, mime_content_type($image_path), basename($image_path))
        ];
    
        curl_setopt_array($ch, [
            CURLOPT_URL => $api_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
    
        // 6. 执行请求并解析响应
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('微信素材上传请求失败: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }
        curl_close($ch);
    
        $response_data = json_decode($response, true);
        if (empty($response_data) || isset($response_data['errcode'])) {
            error_log('微信素材上传失败: ' . $response);
            return false;
        }
    
        // 7. 返回微信 media_id
        return $response_data['media_id'];
    }

    /**
     * 替换文章内容中的本地图片为微信 CDN 链接
     * @param mixed $content
     * @param mixed $wechat
     * @return string
     */
    private static function replace_local_images_with_wechat($content, $wechat) {
        // 匹配所有 <img> 标签的 src 属性（包括相对路径）
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content,$matches);
        if (empty($matches[1])) return $content;

        // 获取 WordPress 上传目录信息
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        $base_path = $upload_dir['basedir'];
        foreach ($matches[1] as $image_url) {
            // 处理相对路径（如 /wp-content/uploads/...）
            if (strpos($image_url, 'http') !== 0) {
                $image_url = site_url($image_url);
            }
    
            // 仅处理本地图片（排除外链图片）
            if (strpos($image_url, $base_url) === false) {
                continue;
            }
    
            // 转换 URL 为服务器物理路径
            $image_path = str_replace($base_url, $base_path, $image_url);
    
            // 上传图片到微信并获取 CDN 链接
            $wechat_image_url = self::upload_and_cache_image($image_path, $wechat);
    
            if ($wechat_image_url) {
                // 替换原图片 URL（保留原 <img> 标签结构）
                $content = str_replace($image_url, $wechat_image_url, $content);
            }
        }
        return $content;
    }

    /**
     * 上传图片到微信并缓存结果（避免重复上传）
     * @param mixed $image_path
     * @param mixed $wechat
     * @return string | false
     */
    private static function upload_and_cache_image($image_path, $wechat) {
        // 生成唯一缓存键（基于图片 MD5）
        $cache_key = 'wechat_image_' . md5_file($image_path);
        $cached_url = get_transient($cache_key);

        // 如果已有缓存且未过期，直接返回
        if ($cached_url !== false) {
            return $cached_url;
        }

        // 上传到微信素材库
        $access_token = $wechat->get_access_token();
        $api_url = "https://api.weixin.qq.com/cgi-bin/material/add_material?access_token={$access_token}&type=image";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $api_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'media' => new CURLFile($image_path)
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 处理上传结果
        if ($http_code == 200) {
            $data = json_decode($response, true);
            if (!empty($data['url'])) {
                // 缓存 30 天（微信素材永久有效）
                set_transient($cache_key, $data['url'], 30 * DAY_IN_SECONDS);
                return $data['url'];
            }
        }

        // 记录错误日志
        error_log('微信图片上传失败: ' . $response);
        return false;
    }

    /**
     * 转换 WordPress 内容为微信兼容格式
     * @param mixed $content
     * @return array|string|null
     */
    private static function convert_to_wechat_html($content) {
        // 将 <h1>-<h6> 转换为微信支持的 <h2>（微信仅支持 h2）
        $content = preg_replace('/<h[1-6](.*?)>/', '<h2$1>', $content);
        $content = preg_replace('/<\/h[1-6]>/', '</h2>', $content);
    
        // 将 <div> 转换为 <section>
        $content = str_replace(['<div>', '</div>'], ['<section>', '</section>'], $content);
    
        // 移除所有 inline style 属性
        $content = preg_replace('/style="[^"]*"/', '', $content);
    
        return $content;
    }



}
Sync_Manager::init();